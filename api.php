<?php
declare(strict_types=1);

/**
 * Skrid JSON API. POST only, logged-in, CSRF token in X-CSRF-Token.
 * ?action=save | block | meta | restore | data_save | data_restore | styles | password | lang | logout
 */

require __DIR__ . '/lib/core.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sk_json(['error' => 'method'], 405);
}
sk_require_login(true);
sk_check_csrf();

$in = sk_input();
$action = (string) ($_GET['action'] ?? $in['action'] ?? '');

/** The page named in the request, its current text and scan. */
function api_page(array $in): array
{
    $rel = sk_resolve((string) ($in['page'] ?? ''));
    if ($rel === null) {
        sk_json(['error' => 'notfound'], 404);
    }
    $s = (string) file_get_contents(sk_file($rel));
    if (isset($in['hash']) && $in['hash'] !== '' && !hash_equals(sha1($s), (string) $in['hash'])) {
        sk_json(['error' => 'conflict', 'hash' => sha1($s)], 409);
    }
    if (!is_writable(sk_file($rel))) {
        sk_json(['error' => 'readonly'], 403);
    }
    return [$rel, $s];
}

switch ($action) {
    case 'save':
        [$rel, $s] = api_page($in);
        $scan = skrid_scan($s);
        $edits = is_array($in['edits'] ?? null) ? $in['edits'] : [];
        $changes = [];
        foreach ($edits as $n => $html) {
            $n = (int) $n;
            if (!isset($scan['editables'][$n]) || !is_string($html) || strlen($html) > 200000) {
                sk_json(['error' => 'bad-element', 'n' => $n], 400);
            }
            $clean = skrid_sanitize($html);
            // An emptied text keeps a non-breaking space, so it stays on the page and stays editable.
            $plain = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}");
            if ($plain === '' && !str_contains($clean, '<br')) {
                $clean = '&nbsp;';
            }
            if ($clean !== skrid_inner($s, $scan, $n)) {
                $changes[$n] = $clean;
            }
        }
        // Paragraph styles: only the classes of the block styles are added or removed, the others stay.
        $universe = sk_block_classes();
        $classChanges = [];
        foreach (is_array($in['blocks'] ?? null) ? $in['blocks'] : [] as $n => $want) {
            $n = (int) $n;
            if (!isset($scan['editables'][$n]) || !is_array($want)) {
                sk_json(['error' => 'bad-element', 'n' => $n], 400);
            }
            $want = array_values(array_unique(array_filter(array_map('strval', $want), static fn ($c) => in_array($c, $universe, true))));
            $e = $scan['editables'][$n];
            $cur = $e['class'] ? preg_split('/\s+/', trim(html_entity_decode(substr($s, $e['class'][0], $e['class'][1] - $e['class'][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : [];
            $cur = array_values(array_filter((array) $cur, 'strlen'));
            $next = array_merge(array_values(array_filter($cur, static fn ($c) => !in_array($c, $universe, true))), $want);
            if ($next !== $cur) {
                $classChanges[$n] = implode(' ', $next);
            }
        }
        if (!$changes && !$classChanges) {
            sk_json(['ok' => true, 'hash' => sha1($s), 'saved' => (object) [], 'count' => count($scan['editables'])]);
        }
        $new = sk_ensure_builtin_css(skrid_apply($s, $scan, $changes, $classChanges));
        if (skrid_php_code($new) !== skrid_php_code($s)) {
            sk_json(['error' => 'php'], 400);           // never change a PHP page's code
        }
        if (!sk_write($rel, $new, $s)) {
            sk_json(['error' => 'write'], 500);
        }
        sk_log('save', $rel, ['n' => count($changes + $classChanges)]);
        sk_json(['ok' => true, 'hash' => sha1($new), 'saved' => (object) $changes, 'classes' => (object) $classChanges, 'count' => count(skrid_scan($new)['editables'])]);

    case 'meta':
        [$rel, $s] = api_page($in);
        $scan = skrid_scan($s);
        $title = trim((string) ($in['title'] ?? ''));
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($title) > 300 || mb_strlen($desc) > 1000) {
            sk_json(['error' => 'too-long'], 400);
        }
        $txt = static fn (string $v): string => htmlspecialchars($v, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attr = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dec = static fn (array $r): string => trim(html_entity_decode(substr($s, $r[0], $r[1] - $r[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $ops = [];                                   // [start, end, replacement]
        $oldTitle = $scan['title'] ? $dec($scan['title']) : '';
        $oldDesc = isset($scan['meta']['description']) ? $dec($scan['meta']['description']) : '';
        if ($scan['title']) {
            $ops[] = [$scan['title'][0], $scan['title'][1], $txt($title)];
        } elseif ($title !== '' && $scan['head_open_end'] !== null) {
            $ops[] = [$scan['head_open_end'], $scan['head_open_end'], "\n<title>" . $txt($title) . '</title>'];
        }
        if (isset($scan['meta']['description'])) {
            [$a, $b] = $scan['meta']['description'];
            $ops[] = [$a, $b, $attr($desc)];
        } elseif ($desc !== '') {
            $at = $scan['title'] ? (int) strpos($s, '>', $scan['title'][1]) + 1 : ($scan['head_close'] ?? null);
            if ($at !== null) {
                $ops[] = [$at, $at, "\n<meta name=\"description\" content=\"" . $attr($desc) . '">'];
            }
        }
        // Social cards that repeated the old title / description follow the change.
        foreach (['og:title' => [$oldTitle, $title], 'twitter:title' => [$oldTitle, $title],
                  'og:description' => [$oldDesc, $desc], 'twitter:description' => [$oldDesc, $desc]] as $k => [$old, $newV]) {
            if (isset($scan['meta'][$k]) && $old !== '' && $dec($scan['meta'][$k]) === $old) {
                $ops[] = [$scan['meta'][$k][0], $scan['meta'][$k][1], $attr($newV)];
            }
        }
        foreach ($ops as [$a, $b]) {
            if ($scan['php'] && skrid_touches_php($scan['php'], $a, max($b, $a + 1))) {
                sk_json(['error' => t('page.metaPhp')], 400);   // title or description written by PHP
            }
        }
        usort($ops, static fn ($x, $y) => $y[0] <=> $x[0]);
        $new = $s;
        foreach ($ops as [$a, $b, $r]) {
            $new = substr($new, 0, $a) . $r . substr($new, $b);
        }
        if ($new !== $s) {
            if (!sk_write($rel, $new, $s)) {
                sk_json(['error' => 'write'], 500);
            }
            sk_log('meta', $rel);
        }
        sk_json(['ok' => true, 'hash' => sha1($new)]);

    case 'block':
        // Duplicate, move (up / down / drop), delete block K or set its classes.
        // The page must be the one the bar was served (hash required).
        if ((string) ($in['hash'] ?? '') === '') {
            sk_json(['error' => 'conflict'], 409);
        }
        [$rel, $s] = api_page($in);
        $scan = skrid_scan($s);
        $k = (int) ($in['block'] ?? -1);
        $op = (string) ($in['op'] ?? '');
        $became = '';
        try {
            if ($op === 'drop') {                    // drag and drop: {target: block or -1 (the zone), where: before|after|inside}
                [$new, $at, $became] = skrid_block_drop($s, $scan, $k, (int) ($in['target'] ?? -2), (string) ($in['where'] ?? ''));
            } elseif ($op === 'classes') {           // {classes: "a b c"}
                $new = skrid_block_classes($s, $scan, $k, (string) ($in['classes'] ?? ''));
                $at = $scan['blocks'][$k]['start'];
            } else {
                [$new, $at] = skrid_block_op($s, $scan, $k, $op);
            }
        } catch (InvalidArgumentException $e) {
            sk_json(['error' => $e->getMessage()], 400);
        }
        if ($new === $s) {
            sk_json(['ok' => true, 'hash' => sha1($s), 'block' => $k, 'backup' => null, 'count' => count($scan['editables']), 'same' => true]);
        }
        if (skrid_php_code($new) !== skrid_php_code($s)) {
            sk_json(['error' => 'php'], 400);           // never change a PHP page's code
        }
        $backup = sk_write($rel, $new, $s);
        if ($backup === false) {
            sk_json(['error' => 'write'], 500);
        }
        sk_log('block', $rel, ['op' => $op, 'tag' => $scan['blocks'][$k]['tag']]);
        $after = skrid_scan($new);
        sk_json(['ok' => true, 'hash' => sha1($new), 'block' => skrid_block_at($after, $at), 'backup' => $backup,
                 'count' => count($after['editables']), 'became' => $became]);

    case 'restore':
        // Admin › History sends no hash; the bar's "Undo" sends one, so it never undoes someone else's change.
        [$rel, $s] = api_page(['page' => $in['page'] ?? '', 'hash' => $in['hash'] ?? '']);
        $old = sk_backup_read($rel, (string) ($in['backup'] ?? ''));
        if ($old === null) {
            sk_json(['error' => 'notfound'], 404);
        }
        if (!sk_write($rel, $old, $s)) {
            sk_json(['error' => 'write'], 500);
        }
        sk_log('restore', $rel, ['from' => (string) $in['backup']]);
        sk_json(['ok' => true, 'hash' => sha1($old)]);

    case 'data_save':
        $rel = sk_data_resolve((string) ($in['file'] ?? ''));
        if ($rel === null) {
            sk_json(['error' => 'notfound'], 404);
        }
        $s = (string) file_get_contents(sk_file($rel));
        if (!hash_equals(sha1($s), (string) ($in['hash'] ?? ''))) {
            sk_json(['error' => 'conflict', 'hash' => sha1($s)], 409);
        }
        if (!is_writable(sk_file($rel))) {
            sk_json(['error' => 'readonly'], 403);
        }
        $text = (string) ($in['json'] ?? '');
        if (strlen($text) > 4000000) {
            sk_json(['error' => 'too-long'], 400);
        }
        try {
            $value = json_decode($text, false, 512, JSON_THROW_ON_ERROR);   // objects stay objects ({} vs [])
        } catch (JsonException $e) {
            sk_json(['error' => t('data.invalid', $e->getMessage())], 400);
        }
        $new = sk_json_format($value, $s);
        if ($new !== $s) {
            if (!sk_write($rel, $new, $s)) {
                sk_json(['error' => 'write'], 500);
            }
            sk_log('data', $rel);
        }
        sk_json(['ok' => true, 'hash' => sha1($new), 'json' => $new]);

    case 'data_restore':
        $rel = sk_data_resolve((string) ($in['file'] ?? ''));
        $s = $rel === null ? null : (string) file_get_contents(sk_file($rel));
        $old = $rel === null ? null : sk_backup_read($rel, (string) ($in['backup'] ?? ''));
        if ($old === null) {
            sk_json(['error' => 'notfound'], 404);
        }
        if (!sk_write($rel, $old, (string) $s)) {
            sk_json(['error' => 'write'], 500);
        }
        sk_log('data-restore', $rel, ['from' => (string) $in['backup']]);
        sk_json(['ok' => true, 'hash' => sha1($old)]);

    case 'password':
        $cur = (string) ($in['current'] ?? '');
        $new = (string) ($in['new'] ?? '');
        if (!password_verify($cur, (string) sk_config()['password_hash'])) {
            sleep(1);
            sk_json(['error' => t('login.wrong')], 400);
        }
        if ($new !== (string) ($in['new2'] ?? '')) {
            sk_json(['error' => t('settings.mismatch')], 400);
        }
        if (mb_strlen($new) < 8) {
            sk_json(['error' => t('settings.short')], 400);
        }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        if (!sk_settings_save(['password_hash' => $hash])) {
            sk_json(['error' => 'write'], 500);
        }
        $_SESSION['skrid_pw'] = substr(sha1($hash), 0, 12);   // stay logged in, others are logged out
        sk_log('password', '');
        sk_json(['ok' => true]);

    case 'styles':
        $inline = (string) ($in['inline'] ?? '');
        $block = (string) ($in['block'] ?? '');
        if (strlen($inline) > 20000 || strlen($block) > 20000) {
            sk_json(['error' => 'too-long'], 400);
        }
        [, $bad1] = sk_parse_styles($inline, true);
        [, $bad2] = sk_parse_styles($block, false);
        if ($bad1 || $bad2) {
            sk_json(['error' => t('settings.stylesBad', implode(' · ', array_merge($bad1, $bad2)))], 400);
        }
        sk_settings_save(['styles_inline' => $inline, 'styles_block' => $block, 'styles_builtin' => (bool) ($in['builtin'] ?? true)]);
        sk_json(['ok' => true]);

    case 'lang':
        $l = (string) ($in['lang'] ?? 'auto');
        sk_settings_save(['lang' => in_array($l, ['fr', 'en'], true) ? $l : 'auto']);
        sk_json(['ok' => true]);

    case 'logout':
        sk_logout();
        sk_json(['ok' => true]);

    default:
        sk_json(['error' => 'action'], 400);
}
