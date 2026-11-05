<?php
declare(strict_types=1);

/**
 * Skrid core: settings, paths, login, CSRF, pages, data files, backups, activity log.
 * Files are the only storage: the site's own pages (.html, and .php pages for their fixed HTML),
 * its .json data files, plus skrid/data/ (settings, backups, log).
 */

const SKRID_VERSION = '0.4.0';

/** JSON files that belong to tools, not to the site's content. */
const SK_DATA_SKIP = ['package.json', 'package-lock.json', 'composer.json', 'composer.lock', 'tsconfig.json', 'jsconfig.json'];
define('SKRID_DIR', dirname(__DIR__));
define('SKRID_DATA', SKRID_DIR . '/data');

require __DIR__ . '/i18n.php';
require __DIR__ . '/scan.php';
require __DIR__ . '/sanitize.php';
require __DIR__ . '/styles.php';

date_default_timezone_set((string) (sk_config()['timezone'] ?? '') ?: (ini_get('date.timezone') ?: 'Europe/Paris'));

// ---------------------------------------------------------------- settings

function sk_config(): array
{
    static $c = null;
    if ($c === null) {
        $c = require SKRID_DIR . '/config.php';
        foreach (sk_settings() as $k => $v) {
            if (in_array($k, ['password_hash', 'lang'], true)) {
                $c[$k] = $v;
            }
        }
    }
    return $c;
}

/** Settings changed from the admin (data/settings.json), on top of config.php. */
function sk_settings(): array
{
    $f = SKRID_DATA . '/settings.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

function sk_settings_save(array $changes): bool
{
    sk_data_dir();
    $d = array_merge(sk_settings(), $changes);
    return file_put_contents(SKRID_DATA . '/settings.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/** data/ exists and is closed to visitors. */
function sk_data_dir(): void
{
    if (!is_dir(SKRID_DATA)) {
        @mkdir(SKRID_DATA, 0775, true);
    }
    if (!is_file(SKRID_DATA . '/.htaccess')) {
        @file_put_contents(SKRID_DATA . '/.htaccess', "Require all denied\n");
    }
    if (!is_file(SKRID_DATA . '/index.php')) {
        @file_put_contents(SKRID_DATA . '/index.php', "<?php http_response_code(404);\n");
    }
}

function skrid_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $want = (string) (sk_config()['lang'] ?? 'auto');
    if (!in_array($want, ['fr', 'en'], true)) {
        $al = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        $want = str_starts_with($al, 'fr') || str_contains($al, 'br') ? 'fr' : 'en';
    }
    return $lang = $want;
}

// ---------------------------------------------------------------- paths and URLs

function sk_site_root(): string
{
    static $r = null;
    if ($r === null) {
        $cfg = sk_config()['site_root'] ?? null;
        $r = realpath($cfg ?: dirname(SKRID_DIR)) ?: dirname(SKRID_DIR);
    }
    return $r;
}

/** URL path of skrid/, e.g. "/skrid/". */
function sk_skrid_url(): string
{
    $d = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/skrid/index.php'));
    return rtrim($d, '/') . '/';
}

/** URL path of the website root, e.g. "/". */
function sk_site_url(): string
{
    $cfg = sk_config()['site_url'] ?? null;
    if ($cfg) {
        return '/' . trim($cfg, '/') . (trim($cfg, '/') === '' ? '' : '/');
    }
    $u = rtrim(dirname(rtrim(sk_skrid_url(), '/')), '/\\');
    return $u . '/';
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------- session, login, CSRF

function sk_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('skrid_sid');
    session_set_cookie_params([
        'path' => sk_skrid_url(),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function sk_logged_in(): bool
{
    sk_session();
    return !empty($_SESSION['skrid_user']) && ($_SESSION['skrid_pw'] ?? '') === substr(sha1((string) sk_config()['password_hash']), 0, 12);
}

/** Pages: redirect to the login. API: 401. */
function sk_require_login(bool $json = false): void
{
    if (sk_logged_in()) {
        return;
    }
    if ($json) {
        sk_json(['error' => 'login'], 401);
    }
    $next = $_SERVER['REQUEST_URI'] ?? sk_skrid_url();
    header('Location: ' . sk_skrid_url() . 'index.php?next=' . rawurlencode($next));
    exit;
}

function sk_csrf(): string
{
    sk_session();
    if (empty($_SESSION['skrid_csrf'])) {
        $_SESSION['skrid_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['skrid_csrf'];
}

function sk_check_csrf(): void
{
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($t) || $t === '' || !hash_equals(sk_csrf(), $t)) {
        sk_json(['error' => 'csrf'], 403);
    }
}

/** Login attempts per visitor address (hashed), to slow down guessing. */
function sk_attempts(?bool $failed = null): int
{
    sk_data_dir();
    $f = SKRID_DATA . '/attempts.json';
    $all = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $key = substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|skrid'), 0, 16);
    $now = time();
    foreach ($all as $k => $a) {
        if ($now - ($a['t'] ?? 0) > 900) {
            unset($all[$k]);                      // forget after 15 minutes
        }
    }
    if ($failed === true) {
        $all[$key] = ['n' => ($all[$key]['n'] ?? 0) + 1, 't' => $all[$key]['t'] ?? $now];
    } elseif ($failed === false) {
        unset($all[$key]);
    }
    if ($failed !== null) {
        @file_put_contents($f, json_encode($all), LOCK_EX);
    }
    return (int) ($all[$key]['n'] ?? 0);
}

/** 'ok' | 'wrong' | 'blocked' */
function sk_login(string $password): string
{
    if (sk_attempts() >= 8) {
        return 'blocked';
    }
    $hash = (string) (sk_config()['password_hash'] ?? '');
    if ($hash !== '' && $password !== '' && password_verify($password, $hash)) {
        sk_attempts(false);
        sk_session();
        session_regenerate_id(true);
        $_SESSION['skrid_user'] = 'admin';
        $_SESSION['skrid_pw'] = substr(sha1($hash), 0, 12);   // a password change logs everyone out
        return 'ok';
    }
    sk_attempts(true);
    sleep(1);
    return 'wrong';
}

function sk_logout(): void
{
    sk_session();
    $_SESSION = [];
    session_destroy();
}

function sk_password_is_default(): bool
{
    return password_verify('KL', (string) (sk_config()['password_hash'] ?? ''));
}

// ---------------------------------------------------------------- HTTP helpers

function sk_private_headers(): void
{
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
}

function sk_json(array $data, int $code = 200): never
{
    http_response_code($code);
    sk_private_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sk_input(): array
{
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : $_POST;
}

// ---------------------------------------------------------------- pages

function sk_excluded(string $name): bool
{
    return $name === '' || $name[0] === '.' || in_array($name, (array) sk_config()['exclude'], true);
}

/** Every file of the site (relative paths), outside skrid/, hidden and excluded folders. */
function sk_site_files(): array
{
    static $files = null;
    if ($files !== null) {
        return $files;
    }
    $files = [];
    $root = sk_site_root();
    $skrid = realpath(SKRID_DIR);
    $walk = static function (string $dir, int $depth) use (&$walk, &$files, $root, $skrid): void {
        if ($depth > 8 || count($files) > 20000) {
            return;
        }
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                if (!sk_excluded($name) && realpath($path) !== $skrid && !is_link($path)) {
                    $walk($path, $depth + 1);
                }
            } elseif ($name[0] !== '.') {
                $files[] = ltrim(substr($path, strlen($root)), '/');
            }
        }
    };
    $walk($root, 0);
    return $files;
}

/** Is this file a page? Any .html/.htm; a .php file only if its own HTML (outside PHP code) has <html> or <body>. */
function sk_is_page(string $path): bool
{
    if (preg_match('/\.html?$/i', $path)) {
        return true;
    }
    if (!preg_match('/\.php$/i', $path) || !is_file($path) || filesize($path) > 2000000) {
        return false;
    }
    $s = (string) @file_get_contents($path);
    foreach (array_reverse(skrid_php_ranges($s)) as [$a, $b]) {
        $s = substr($s, 0, $a) . substr($s, $b);
    }
    return (bool) preg_match('/<(html|body)[\s>]/i', $s);
}

function sk_is_php(string $rel): bool
{
    return (bool) preg_match('/\.php$/i', $rel);
}

/** Every page of the site: rel path, URL, title, language, date. */
function sk_pages(): array
{
    $root = sk_site_root();
    $pages = [];
    foreach (sk_site_files() as $rel) {
        $path = $root . '/' . $rel;
        if (count($pages) > 3000 || !preg_match('/\.(html?|php)$/i', $rel) || !sk_is_page($path)) {
            continue;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 65536);
        $head = (string) preg_replace('/<\?(php\b|=).*?(\?>|\z)/is', '', $head);
        preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $tm);
        preg_match('/<html[^>]*\slang=["\']?([a-zA-Z-]+)/i', $head, $lm);
        $pages[] = [
            'rel' => $rel,
            'url' => sk_page_url($rel),
            'title' => trim(html_entity_decode(strip_tags($tm[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'lang' => strtolower($lm[1] ?? ''),
            'mtime' => (int) @filemtime($path),
            'size' => (int) @filesize($path),
            'writable' => is_writable($path),
            'php' => sk_is_php($rel),
        ];
    }
    usort($pages, static function ($a, $b) {
        // Home first, then by folder and name.
        $ka = (preg_match('/^index\.(html?|php)$/i', $a['rel']) ? '0' : '1') . $a['rel'];
        $kb = (preg_match('/^index\.(html?|php)$/i', $b['rel']) ? '0' : '1') . $b['rel'];
        return strnatcasecmp($ka, $kb);
    });
    return $pages;
}

/** "fr/musique/index.html" → "/fr/musique/" (with the site URL prefix). Same for index.php. */
function sk_page_url(string $rel): string
{
    $u = preg_replace('#(^|/)index\.(html?|php)$#i', '$1', $rel);
    return sk_site_url() . $u;
}

/**
 * A page from what the browser asked for: "/fr/musique/", "fr/musique/index.html", "/a.html#x"…
 * Returns the relative path of an existing, editable HTML file of the site, or null.
 */
function sk_resolve(string $p): ?string
{
    $p = rawurldecode((string) preg_replace('/[?#].*$/s', '', $p));
    if (str_contains($p, "\0")) {
        return null;
    }
    $base = sk_site_url();
    if (str_starts_with($p, $base)) {
        $p = substr($p, strlen($base));
    }
    $p = ltrim($p, '/');
    $parts = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..' || sk_excluded($seg)) {
            return null;
        }
        $parts[] = $seg;
    }
    $root = sk_site_root();
    $rel = implode('/', $parts);
    $candidates = [];
    if ($rel === '' || str_ends_with($p, '/') || is_dir($root . '/' . $rel)) {
        foreach (['index.html', 'index.htm', 'index.php'] as $index) {
            $candidates[] = ($rel === '' ? '' : $rel . '/') . $index;
        }
    } elseif (preg_match('/\.(html?|php)$/i', $rel)) {
        $candidates[] = $rel;
    } else {
        $candidates[] = $rel . '.html';
        $candidates[] = $rel . '.php';
    }
    $skrid = realpath(SKRID_DIR);
    foreach ($candidates as $c) {
        $full = realpath($root . '/' . $c);
        if ($full && is_file($full) && str_starts_with($full, $root . DIRECTORY_SEPARATOR)
            && !str_starts_with($full, $skrid . DIRECTORY_SEPARATOR) && sk_is_page($full)) {
            return ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
        }
    }
    return null;
}

function sk_file(string $rel): string
{
    return sk_site_root() . '/' . $rel;
}

/** Write a page in place (same file, same permissions), after a backup copy. */
function sk_write(string $rel, string $content, string $before): bool
{
    sk_backup($rel, $before);
    return file_put_contents(sk_file($rel), $content, LOCK_EX) !== false;
}

// ---------------------------------------------------------------- PHP pages

/**
 * Run a PHP page of the site from its (marked) source, as the web server would, and return its HTML.
 * __DIR__ and __FILE__ keep the page's real values, the working folder is the page's folder.
 * Returns [html, error message or null].
 */
function sk_render_php(string $rel, string $src): array
{
    $file = realpath(sk_file($rel)) ?: sk_file($rel);
    $dir = dirname($file);
    if (!function_exists('token_get_all')) {      // rare hosting without the tokenizer: plain replace
        $src = str_replace(['__DIR__', '__FILE__'], [var_export($dir, true), var_export($file, true)], $src);
    }
    $code = '';
    foreach (function_exists('token_get_all') ? token_get_all($src) : [$src] as $tok) {
        if (is_array($tok) && $tok[0] === T_DIR) {
            $code .= var_export($dir, true);
        } elseif (is_array($tok) && $tok[0] === T_FILE) {
            $code .= var_export($file, true);
        } else {
            $code .= is_array($tok) ? $tok[1] : $tok;
        }
    }
    // eval() starts in PHP mode: drop the page's first "<?php", or close PHP mode if it starts with HTML.
    $code = preg_match('/^<\?php\s/i', $code) ? substr($code, 5) : '?>' . $code;

    $server = $_SERVER;
    $get = $_GET;
    $cwd = getcwd();
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = sk_site_url() . $rel;
    $_SERVER['REQUEST_URI'] = sk_page_url($rel);
    $_SERVER['QUERY_STRING'] = '';
    $_GET = [];
    @chdir($dir);
    $level = ob_get_level();
    ob_start();
    $error = null;
    try {
        (static function (string $__skrid_code): void {
            eval($__skrid_code);
        })($code);
    } catch (Throwable $e) {
        $error = $e->getMessage() . ' (' . basename($e->getFile()) . ', ' . $e->getLine() . ')';
    }
    $html = '';
    while (ob_get_level() > $level) {
        $html = (string) ob_get_clean() . $html;
    }
    $_SERVER = $server;
    $_GET = $get;
    if ($cwd !== false) {
        @chdir($cwd);
    }
    return [$html, $error];
}

// ---------------------------------------------------------------- data files (.json)

/** The site's .json files: rel, size, date, writable. */
function sk_data_files(): array
{
    $out = [];
    foreach (sk_site_files() as $rel) {
        if (!preg_match('/\.json$/i', $rel) || in_array(strtolower(basename($rel)), SK_DATA_SKIP, true)) {
            continue;
        }
        $path = sk_file($rel);
        $out[] = ['rel' => $rel, 'size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path), 'writable' => is_writable($path)];
    }
    usort($out, static fn ($a, $b) => strnatcasecmp($a['rel'], $b['rel']));
    return $out;
}

/** A data file from what the browser sent: rel path of an existing .json of the site, or null. */
function sk_data_resolve(string $f): ?string
{
    $f = ltrim(str_replace('\\', '/', $f), '/');
    if ($f === '' || str_contains($f, "\0") || !preg_match('/\.json$/i', $f)) {
        return null;
    }
    foreach (explode('/', $f) as $seg) {
        if ($seg === '..' || sk_excluded($seg)) {
            return null;
        }
    }
    foreach (sk_data_files() as $d) {
        if ($d['rel'] === $f && $d['size'] <= 2000000) {
            return $f;
        }
    }
    return null;
}

/**
 * The data files a page uses: named in the page itself, or in a PHP file the page includes
 * (portfolio.php reads data/selectedwork.json → the portfolio pages use it).
 */
function sk_page_data(string $rel, ?string $src = null): array
{
    $json = sk_data_files();
    if (!$json) {
        return [];
    }
    $hay = $src ?? (string) @file_get_contents(sk_file($rel));
    if (preg_match_all('/[\w.\-]+\.php\b/i', $hay, $m)) {
        $names = array_unique(array_map('strtolower', $m[0]));
        foreach (sk_site_files() as $f) {
            if ($f !== $rel && in_array(strtolower(basename($f)), $names, true)) {
                $hay .= "\n" . (string) @file_get_contents(sk_file($f), false, null, 0, 262144);
            }
        }
    }
    $out = [];
    foreach ($json as $d) {
        if (str_contains($hay, basename($d['rel']))) {
            $out[] = $d['rel'];
        }
    }
    return $out;
}

/** Data file => pages that use it. */
function sk_data_usage(array $pages): array
{
    $use = [];
    foreach ($pages as $p) {
        foreach (sk_page_data($p['rel']) as $d) {
            $use[$d][] = $p;
        }
    }
    return $use;
}

/** Address of the data editor for a file (optionally on the item that holds $q, coming from page $from). */
function sk_data_url(string $rel, string $q = '', string $from = ''): string
{
    return sk_skrid_url() . 'data.php?f=' . rawurlencode($rel)
        . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($from !== '' ? '&from=' . rawurlencode($from) : '');
}

/**
 * JSON text for a data file, written like the original: same indentation (2 or 4 spaces, tabs),
 * accents and slashes as they are, final newline kept.
 */
function sk_json_format(mixed $value, string $original): string
{
    $out = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (preg_match('/^([ \t]+)[\["{]/m', $original, $m) && $m[1] !== '    ') {
        $unit = $m[1];
        $out = (string) preg_replace_callback('/^(?: {4})+/m', static fn ($x) => str_repeat($unit, intdiv(strlen($x[0]), 4)), $out);
    }
    return $out . (str_ends_with($original, "\n") ? "\n" : '');
}

/** "3 lists · 22 items" style summary of a decoded JSON value. */
function sk_data_summary(mixed $v): array
{
    $lists = 0;
    $items = 0;
    if (is_array($v) && array_is_list($v)) {
        return [1, count($v)];
    }
    if (is_object($v) || is_array($v)) {
        foreach ((array) $v as $x) {
            if (is_array($x) && array_is_list($x)) {
                $lists++;
                $items += count($x);
            }
        }
    }
    return [$lists, $items];
}

// ---------------------------------------------------------------- backups

function sk_backup_dir(string $rel): string
{
    return SKRID_DATA . '/backups/' . str_replace('/', '~', $rel);
}

function sk_backup(string $rel, string $content): string
{
    sk_data_dir();
    $dir = sk_backup_dir($rel);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.html';
    @file_put_contents($dir . '/' . $name, $content, LOCK_EX);
    $keep = max(1, (int) (sk_config()['backups_per_page'] ?? 30));
    $all = sk_backups($rel);
    foreach (array_slice($all, $keep) as $old) {
        @unlink($dir . '/' . $old['name']);
    }
    return $name;
}

/** Newest first: [name, time, size]. */
function sk_backups(string $rel): array
{
    $dir = sk_backup_dir($rel);
    $out = [];
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $f) {
        if (preg_match('/^(\d{8})-(\d{6})-[0-9a-f]{6}\.html$/', $f, $m)) {
            $t = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]);
            $out[] = ['name' => $f, 'time' => $t ? $t->getTimestamp() : 0, 'size' => (int) filesize($dir . '/' . $f)];
        }
    }
    usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

function sk_backup_read(string $rel, string $name): ?string
{
    if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}\.html$/', $name)) {
        return null;
    }
    $f = sk_backup_dir($rel) . '/' . $name;
    return is_file($f) ? (string) file_get_contents($f) : null;
}

// ---------------------------------------------------------------- activity log

function sk_log(string $action, string $rel, array $extra = []): void
{
    sk_data_dir();
    $line = json_encode(['t' => time(), 'a' => $action, 'p' => $rel] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(SKRID_DATA . '/activity.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Newest first. */
function sk_log_read(int $limit = 100): array
{
    $f = SKRID_DATA . '/activity.log';
    if (!is_file($f)) {
        return [];
    }
    $lines = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$limit);
    $out = [];
    foreach (array_reverse($lines) as $l) {
        $d = json_decode($l, true);
        if (is_array($d)) {
            $out[] = $d;
        }
    }
    return $out;
}

function sk_date(int $t): string
{
    return skrid_lang() === 'fr' ? date('d/m/Y H:i', $t) : date('j M Y, H:i', $t);
}
