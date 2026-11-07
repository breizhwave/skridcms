<?php
declare(strict_types=1);

/**
 * Show a page of the site exactly as it is, plus the Skrid bar.
 * ?p=/fr/musique/  [&mode=edit]  [&backup=20261102-153000-abcdef.html (read-only copy)]
 *
 * Editable elements get data-skrid="N", blocks data-skrid-block="K" (attributes only, nothing else changes) and a <base> makes
 * the page's relative links, images and styles work from /skrid/.
 *
 * A PHP page is run from its marked source (sk_render_php): its fixed texts carry data-skrid, and what
 * its code prints sits between <!--skrid-dyn--> markers, which the bar outlines and links to the data editor.
 */

require __DIR__ . '/lib/core.php';
sk_require_login();
sk_private_headers();

$p = (string) ($_GET['p'] ?? sk_site_url());
$rel = sk_resolve($p);
$backupName = (string) ($_GET['backup'] ?? '');

if ($rel === null) {
    // Something of the site that is not a page (a PHP script, a PDF…): open it for real.
    $path = rawurldecode((string) preg_replace('/[?#].*$/s', '', $p));
    $site = sk_site_url();
    $inSite = str_starts_with($path, $site) ? substr($path, strlen($site)) : ltrim($path, '/');
    $full = realpath(sk_site_root() . '/' . $inSite);
    $segsOk = !array_filter(explode('/', trim($inSite, '/')), static fn ($x) => $x === '..' || ($x !== '' && sk_excluded($x)));
    if ($full && $segsOk && str_starts_with($full, sk_site_root()) && !str_starts_with($full, realpath(SKRID_DIR))) {
        header('Location: ' . $site . implode('/', array_map('rawurlencode', explode('/', ltrim($inSite, '/')))));
        exit;
    }
    http_response_code(404);
    ?><!doctype html>
<html lang="<?= h(skrid_lang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base href="<?= h(sk_skrid_url()) ?>">
<title>Skrid · <?= h(t('notfound.title')) ?></title><link rel="stylesheet" href="assets/admin.css?v=<?= SKRID_VERSION ?>"></head>
<body class="login"><main class="login-card"><p class="brand"><img src="assets/skrid.svg" alt="" width="36" height="36"> <span>Skrid</span></p>
<h1><?= h(t('notfound.title')) ?></h1><p class="muted"><?= h(t('notfound.lead')) ?></p><p><code><?= h($p) ?></code></p>
<p><a class="btn primary" href="admin.php"><?= h(t('page.back')) ?></a></p></main></body></html>
<?php
    exit;
}

$current = (string) file_get_contents(sk_file($rel));
$html = $current;
$backup = null;
if ($backupName !== '') {
    $html = sk_backup_read($rel, $backupName);
    if ($html === null) {
        header('Location: view.php?p=' . rawurlencode(sk_page_url($rel)));
        exit;
    }
    $b = array_values(array_filter(sk_backups($rel), static fn ($x) => $x['name'] === $backupName))[0] ?? null;
    $backup = ['name' => $backupName, 'date' => $b ? sk_date($b['time']) : $backupName];
}

$scan = skrid_scan($html);
$isPhp = sk_is_php($rel);
$out = $backup ? $html : skrid_mark($html, $scan, $isPhp);
$renderError = null;
if ($isPhp) {
    [$out, $renderError] = sk_render_php($rel, $out);
}
$at = $isPhp ? skrid_scan($out) : $scan;           // where <head> and <body> are in what is sent
$dataFiles = $backup ? [] : sk_page_data($rel, $current);

// Links, images and styles written relative to the page must resolve from its real folder.
$dir = trim(str_replace('\\', '/', dirname($rel)), '.');
$baseHref = sk_site_url() . ($dir === '' ? '' : implode('/', array_map('rawurlencode', explode('/', trim($dir, '/')))) . '/');

$config = [
    'page' => $rel,
    'url' => sk_page_url($rel),
    'hash' => sha1($current),
    'csrf' => sk_csrf(),
    'api' => sk_skrid_url() . 'api.php',
    'admin' => sk_skrid_url() . 'admin.php',
    'view' => sk_skrid_url() . 'view.php',
    'site' => sk_site_url(),
    'skrid' => sk_skrid_url(),
    'count' => count($scan['editables']),
    // Blocks: [enclosing block or -1, can move up, can move down, classes as written in the file], by data-skrid-block index.
    'blocks' => $backup ? [] : array_map(static fn ($b) => [
        $b['parent'] ?? -1, $b['prev'] !== null ? 1 : 0, $b['next'] !== null ? 1 : 0,
        $b['class'] ? trim(html_entity_decode(substr($html, $b['class'][0], $b['class'][1] - $b['class'][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '',
    ], $scan['blocks']),
    'mode' => ($_GET['mode'] ?? '') === 'edit' ? 'edit' : '',
    'backup' => $backup,
    'lang' => skrid_lang(),
    'texts' => t_group('bar.'),
    'styles' => sk_styles(),
    'blockClasses' => sk_block_classes(),
    'version' => SKRID_VERSION,
    'php' => $isPhp,
    'renderError' => $renderError,
    'data' => array_map(static fn ($d) => ['file' => $d, 'name' => basename($d), 'url' => sk_data_url($d, '', sk_page_url($rel))], $dataFiles),
];

$headIn = '';
if (!preg_match('/<base[\s>]/i', substr($out, 0, (int) ($at['head_close'] ?? 4096)))) {
    $headIn .= "\n<base href=\"" . h($baseHref) . '">';
}
$css = "\n<link rel=\"stylesheet\" href=\"" . h(sk_skrid_url()) . 'assets/inline.css?v=' . SKRID_VERSION . '">';
$js = "\n<script src=\"" . h(sk_skrid_url()) . 'assets/bar.js?v=' . SKRID_VERSION . '" data-skrid-config="'
    . h(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\" defer></script>\n";

// Head: <base> first thing in <head>, the editor styles last. Marks only touched <body>, so head offsets hold.
if ($at['head_open_end'] !== null && $at['head_close'] !== null) {
    $out = substr($out, 0, $at['head_close']) . $css . "\n" . substr($out, $at['head_close']);
    $out = substr($out, 0, $at['head_open_end']) . $headIn . substr($out, $at['head_open_end']);
} else {
    $out = $headIn . $css . "\n" . $out;
}
$bodyClose = strripos($out, '</body');
$out = $bodyClose !== false ? substr($out, 0, $bodyClose) . $js . substr($out, $bodyClose) : $out . $js;

header('Content-Type: text/html; charset=utf-8');
echo $out;
