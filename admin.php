<?php
declare(strict_types=1);

/**
 * Skrid admin: pages (title, description, history), data files, activity, headers & footers, settings.
 * ?tab=pages|data|activity|chrome|settings   ?page=fr/musique/index.html
 */

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ui.php';
sk_require_login();
sk_private_headers();

$tab = (string) ($_GET['tab'] ?? 'pages');
$pageRel = isset($_GET['page']) ? sk_resolve((string) $_GET['page']) : null;
if ($pageRel !== null) {
    $tab = 'pages';
}
$pages = sk_pages();
$siteName = sk_site_name($pages);
$dataFiles = sk_data_files();
$dataUse = $dataFiles ? sk_data_usage($pages) : [];
$pageData = [];                                     // page rel => data files it uses
foreach ($dataUse as $d => $ps) {
    foreach ($ps as $p) {
        $pageData[$p['rel']][] = $d;
    }
}
$GLOBALS['sk_page_data'] = $pageData;
$viewUrl = static fn (string $url, string $extra = ''): string => 'view.php?p=' . rawurlencode($url) . $extra;
$titles = [];
foreach ($pages as $p) {
    $titles[$p['rel']] = $p['title'] ?: $p['url'];
}

/** Header/footer groups per language, for the "Headers & footers" tab. */
function chrome_groups(array $pages): array
{
    $g = [];
    foreach ($pages as $p) {
        $scan = skrid_scan((string) @file_get_contents(sk_file($p['rel'])));
        $lang = $scan['lang'] ?: '—';
        $g[$lang] ??= ['pages' => 0, 'header' => [], 'footer' => []];
        $g[$lang]['pages']++;
        foreach ($scan['chrome'] as $c) {
            if ($c['tag'] === 'header' || $c['tag'] === 'footer') {
                $g[$lang][$c['tag']][$c['hash']] = true;
            }
        }
    }
    ksort($g);
    return $g;
}

/**
 * Pages as a tree that follows the site's folders: "fr/musique/index.html" is the page of the folder
 * fr/musique, child of fr. A folder without its own index page still shows, as a plain folder.
 */
function pages_tree(array $pages): array
{
    $root = ['name' => '', 'page' => null, 'kids' => []];
    foreach ($pages as $p) {
        $segs = explode('/', $p['rel']);
        $file = array_pop($segs);
        if (!preg_match('/^index\.(html?|php)$/i', $file)) {
            $segs[] = $file;
        }
        $node = &$root;
        foreach ($segs as $seg) {
            $node['kids'][$seg] ??= ['name' => $seg, 'page' => null, 'kids' => []];
            $node = &$node['kids'][$seg];
        }
        $node['page'] ??= $p;
        unset($node);
    }
    return $root;
}

function tree_count(array $node): int
{
    $n = $node['page'] ? 1 : 0;
    foreach ($node['kids'] as $k) {
        $n += tree_count($k);
    }
    return $n;
}

/** One level of the tree: folders first in natural order, error pages (404…) last. */
function tree_html(array $node, callable $viewUrl, string $path = ''): string
{
    $kids = $node['kids'];
    uksort($kids, static function ($a, $b) {
        $ka = (preg_match('/^\d/', $a) ? '1' : '0') . $a;
        $kb = (preg_match('/^\d/', $b) ? '1' : '0') . $b;
        return strnatcasecmp($ka, $kb);
    });
    $out = '';
    foreach ($kids as $name => $kid) {
        $out .= tree_node($kid, $viewUrl, $path . $name . '/');
    }
    return $out;
}

function tree_node(array $node, callable $viewUrl, string $path): string
{
    $p = $node['page'];
    // The tree shows where a page sits; its own row only needs the last part of the address.
    $seg = basename(rtrim($path, '/'));
    $last = $path === '' ? '/' : ($p && preg_match('/\.(html?|php)$/i', $seg) ? $seg : $seg . '/');
    if ($p) {
        $text = mb_strtolower($p['title'] . ' ' . $p['url']);
        // "Music: Dour Laouen… | wave.bzh" → "Music: Dour Laouen…" (the site name is the same on every page).
        $short = trim((string) preg_split('/\s+[|·–—]\s+/u', (string) $p['title'])[0]) ?: $p['rel'];
        $row = '<span class="lang">' . h($p['lang'] ?: '—') . '</span>'
            . '<span class="what"><a class="title" href="admin.php?page=' . h(rawurlencode($p['rel'])) . '" title="' . h($p['title']) . '">' . h($short) . '</a>'
            . '<code title="' . h($p['url']) . '">' . h($last) . '</code>'
            . ($p['php'] ? '<span class="tag" title="' . h(t('pages.phpNote')) . '">PHP</span>' : '')
            . implode('', array_map(static fn ($d) => '<a class="chip" href="' . h(sk_data_url($d, '', $p['url'])) . '" title="' . h(t('pages.dataNote')) . '">' . h(basename($d)) . '</a>', $GLOBALS['sk_page_data'][$p['rel']] ?? []))
            . ($p['writable'] ? '' : '<strong class="bad">' . h(t('pages.notwritable')) . '</strong>') . '</span>'
            . '<span class="when muted" title="' . h(t('pages.modified')) . '">' . h(sk_date($p['mtime'])) . '</span>'
            . '<a class="btn small" href="' . h($viewUrl($p['url'], '&mode=edit')) . '">' . h(t('pages.edit')) . '</a>'
            . '<a class="btn small ghost" href="admin.php?page=' . h(rawurlencode($p['rel'])) . '">' . h(t('pages.details')) . '</a>';
    } else {
        $n = tree_count($node);
        $text = mb_strtolower($path);
        $row = '<span class="folder" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2l2 2h8.8A1.5 1.5 0 0 1 21 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z" fill="#dfe6f0" stroke="#8592a6" stroke-width="1.3" stroke-linejoin="round"/></svg></span>'
            . '<span class="what"><span class="title">' . h(rtrim($path, '/')) . '/</span></span>'
            . '<span class="muted">' . h($n === 1 ? t('pages.count1') : t('pages.countN', $n)) . '</span>';
    }
    $html = '<li class="node" data-text="' . h($text) . '">';
    if ($node['kids']) {
        $html .= '<details open><summary class="row"><span class="twisty" aria-hidden="true"></span>' . $row . '</summary>'
            . '<ul class="tree" role="list">' . tree_html($node, $viewUrl, $path) . '</ul></details>';
    } else {
        $html .= '<div class="row"><span class="twisty leaf" aria-hidden="true"></span>' . $row . '</div>';
    }
    return $html . '</li>';
}
?>
<!doctype html>
<html lang="<?= h(skrid_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<base href="<?= h(sk_skrid_url()) ?>">
<meta name="skrid-csrf" content="<?= h(sk_csrf()) ?>">
<meta name="skrid-api" content="api.php">
<title>Skrid · <?= h($siteName) ?></title>
<link rel="icon" href="assets/skrid.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/admin.css?v=<?= SKRID_VERSION ?>">
<script src="assets/admin.js?v=<?= SKRID_VERSION ?>" defer></script>
</head>
<body>
<?= sk_top_bar($tab, $viewUrl($pageRel ? sk_page_url($pageRel) : sk_site_url()), $siteName, (bool) $dataFiles) ?>

<main class="wrap">
<?php if (sk_password_is_default() && $tab !== 'settings'): ?>
  <p class="msg warn"><?= h(t('settings.weak')) ?> <a href="admin.php?tab=settings"><?= h(t('settings.password')) ?> →</a></p>
<?php endif; ?>

<?php if ($tab === 'pages' && $pageRel !== null):
    $file = sk_file($pageRel);
    $s = (string) file_get_contents($file);
    $scan = skrid_scan($s);
    $dec = static fn (?array $r): string => $r ? trim(html_entity_decode(substr($s, $r[0], $r[1] - $r[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    $pTitle = $dec($scan['title']);
    $pDesc = $dec($scan['meta']['description'] ?? null);
    $url = sk_page_url($pageRel);
    $backups = sk_backups($pageRel);
    ?>
  <p><a href="admin.php"><?= h(t('page.back')) ?></a></p>
  <div class="page-head">
    <div>
      <h1><?= h($pTitle ?: $url) ?></h1>
      <p class="muted"><code><?= h($url) ?></code> · <?= h($scan['lang'] ?: '—') ?> · <?= h(t('page.editables', count($scan['editables']))) ?>
        <?= is_writable($file) ? '' : ' · <strong class="bad">' . h(t('pages.notwritable')) . '</strong>' ?></p>
      <?php if (sk_is_php($pageRel)): ?><p class="msg small"><?= h(t('page.phpLead')) ?></p><?php endif; ?>
    </div>
    <a class="btn primary" href="<?= h($viewUrl($url, '&mode=edit')) ?>"><?= h(t('pages.edit')) ?></a>
  </div>

  <?php if (!empty($pageData[$pageRel])): ?>
  <section class="card">
    <h2><?= h(t('page.data')) ?></h2>
    <p class="muted"><?= h(t('page.dataLead')) ?></p>
    <ul class="list">
      <?php foreach ($pageData[$pageRel] as $d): ?>
        <li><code><?= h($d) ?></code><span class="grow"></span><a class="btn small primary" href="<?= h(sk_data_url($d, '', $url)) ?>"><?= h(t('data.edit')) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2><?= h(t('page.meta')) ?></h2>
    <p class="muted"><?= h(t('page.metaLead')) ?></p>
    <form class="meta" data-meta data-page="<?= h($pageRel) ?>" data-hash="<?= h(sha1($s)) ?>">
      <div class="field">
        <label for="m-title"><?= h(t('page.titleLabel')) ?> <span class="count" data-count-for="m-title" data-max="60"></span></label>
        <input id="m-title" name="title" value="<?= h($pTitle) ?>" maxlength="300">
      </div>
      <div class="field">
        <label for="m-desc"><?= h(t('page.descLabel')) ?> <span class="count" data-count-for="m-desc" data-max="160"></span></label>
        <textarea id="m-desc" name="description" rows="3" maxlength="1000"><?= h($pDesc) ?></textarea>
      </div>
      <div class="serp" aria-label="<?= h(t('page.preview')) ?>">
        <p class="serp-label"><?= h(t('page.preview')) ?></p>
        <p class="serp-url"><?= h(($_SERVER['HTTP_HOST'] ?? '') . $url) ?></p>
        <p class="serp-title" data-serp-title><?= h($pTitle) ?></p>
        <p class="serp-desc" data-serp-desc><?= h($pDesc) ?></p>
      </div>
      <p class="row"><button class="btn primary" type="submit"><?= h(t('page.save')) ?></button> <span class="msg-inline" role="status"></span></p>
    </form>
  </section>

  <section class="card">
    <h2><?= h(t('page.history')) ?></h2>
    <p class="muted"><?= h(t('page.historyLead', (int) sk_config()['backups_per_page'])) ?></p>
    <?php if (!$backups): ?>
      <p><?= h(t('page.noHistory')) ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($backups as $b): ?>
          <li>
            <span class="when"><?= h(sk_date($b['time'])) ?></span>
            <span class="muted"><?= number_format($b['size'] / 1024, 1) ?> <?= skrid_lang() === 'fr' ? 'Ko' : 'KB' ?></span>
            <span class="grow"></span>
            <a class="btn small" href="<?= h($viewUrl($url, '&backup=' . rawurlencode($b['name']))) ?>"><?= h(t('page.view')) ?></a>
            <button class="btn small" type="button" data-restore="<?= h($b['name']) ?>" data-page="<?= h($pageRel) ?>"
              data-confirm="<?= h(t('page.restoreConfirm')) ?>"><?= h(t('page.restore')) ?></button>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'pages'): ?>
  <h1><?= h(t('pages.title')) ?></h1>
  <p class="muted"><?= h(t('pages.lead', count($pages))) ?></p>
  <div class="toolbar">
    <input class="search" type="search" placeholder="<?= h(t('pages.search')) ?>" aria-label="<?= h(t('pages.search')) ?>" data-search>
    <span class="grow"></span>
    <button class="btn small ghost" type="button" data-fold="close"><?= h(t('pages.collapse')) ?></button>
    <button class="btn small ghost" type="button" data-fold="open"><?= h(t('pages.expand')) ?></button>
  </div>
  <?php if (!$pages): ?>
    <p class="msg"><?= h(t('pages.none', sk_site_root())) ?></p>
  <?php else: ?>
    <?php $tree = pages_tree($pages); ?>
    <div class="tree-box">
      <ul class="tree" role="list" data-tree>
        <?= $tree['page'] ? tree_node($tree, $viewUrl, '') : tree_html($tree, $viewUrl) ?>
      </ul>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'data'): ?>
  <h1><?= h(t('data.title')) ?></h1>
  <p class="muted lead"><?= h(t('data.lead')) ?></p>
  <?php if (!$dataFiles): ?>
    <p class="msg"><?= h(t('data.none')) ?></p>
  <?php else: ?>
    <ul class="list data-files">
      <?php foreach ($dataFiles as $d):
          $v = json_decode((string) @file_get_contents(sk_file($d['rel'])));
          [$nl, $ni] = $v === null ? [0, 0] : sk_data_summary($v);
          $users = $dataUse[$d['rel']] ?? []; ?>
        <li>
          <span class="what">
            <a class="title" href="<?= h(sk_data_url($d['rel'])) ?>"><?= h(basename($d['rel'])) ?></a>
            <code><?= h($d['rel']) ?></code>
            <span class="muted small"><?= $v === null ? '<strong class="bad">' . h(t('data.broken')) . '</strong>' : h($nl > 1 ? t('data.summaryN', $nl, $ni) : t('data.summary1', $ni)) ?> · <?= h(sk_size($d['size'])) ?> · <?= h(sk_date($d['mtime'])) ?></span>
            <span class="used small"><?php if ($users): ?><?= h(t('data.usedBy')) ?>
              <?php foreach ($users as $u): ?><a class="chip" href="<?= h($viewUrl($u['url'], '&mode=edit')) ?>"><span class="lang"><?= h($u['lang'] ?: '—') ?></span> <?= h($u['url']) ?></a><?php endforeach; ?>
            <?php else: ?><span class="muted"><?= h(t('data.notUsed')) ?></span><?php endif; ?></span>
          </span>
          <a class="btn small primary" href="<?= h(sk_data_url($d['rel'])) ?>"><?= h(t('data.edit')) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

<?php elseif ($tab === 'activity'): $log = sk_log_read(150); ?>
  <h1><?= h(t('activity.title')) ?></h1>
  <?php if (!$log): ?>
    <p class="muted"><?= h(t('activity.none')) ?></p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($log as $e):
          $what = match ($e['a'] ?? '') {
              'save' => t('activity.save', (int) ($e['n'] ?? 0)),
              'meta' => t('activity.meta'),
              'restore' => t('activity.restore'),
              'data' => t('activity.data'),
              'data-restore' => t('activity.dataRestore'),
              'password' => t('settings.changed'),
              default => (string) ($e['a'] ?? ''),
          }; ?>
        <li>
          <span class="when"><?= h(sk_date((int) $e['t'])) ?></span>
          <?php if (!empty($e['p']) && str_starts_with((string) ($e['a'] ?? ''), 'data')): ?>
            <a href="<?= h(sk_data_url((string) $e['p'])) ?>"><?= h($e['p']) ?></a>
          <?php elseif (!empty($e['p'])): ?>
            <a href="admin.php?page=<?= h(rawurlencode($e['p'])) ?>"><?= h($titles[$e['p']] ?? $e['p']) ?></a>
          <?php endif; ?>
          <span class="muted"><?= h($what) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

<?php elseif ($tab === 'chrome'): $groups = chrome_groups($pages); ?>
  <h1><?= h(t('chrome.title')) ?></h1>
  <p class="lead"><?= h(t('chrome.lead')) ?></p>
  <table class="table">
    <thead><tr><th><?= h(t('chrome.lang')) ?></th><th><?= h(t('chrome.pages')) ?></th><th><?= h(t('chrome.headers')) ?></th><th><?= h(t('chrome.footers')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($groups as $lang => $g): ?>
        <tr><td><span class="lang"><?= h($lang) ?></span></td><td><?= $g['pages'] ?></td><td><?= count($g['header']) ?></td><td><?= count($g['footer']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted"><?= h(t('chrome.why')) ?></p>
  <p class="msg"><?= h(t('chrome.next')) ?></p>

<?php elseif ($tab === 'settings'):
    $writable = count(array_filter($pages, static fn ($p) => $p['writable']));
    sk_data_dir();
    $origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
    $bookmarklet = "javascript:location.href='" . $origin . sk_skrid_url() . "view.php?p='+encodeURIComponent(location.pathname)+location.hash";
    $curLang = (string) (sk_settings()['lang'] ?? sk_config()['lang'] ?? 'auto');
    ?>
  <h1><?= h(t('settings.title')) ?></h1>
  <?php if (sk_password_is_default()): ?><p class="msg warn"><?= h(t('settings.weak')) ?></p><?php endif; ?>

  <section class="card">
    <h2><?= h(t('settings.password')) ?></h2>
    <form class="stack" data-password>
      <div class="field"><label for="pw0"><?= h(t('settings.current')) ?></label><input id="pw0" name="current" type="password" autocomplete="current-password" required></div>
      <div class="field"><label for="pw1"><?= h(t('settings.new')) ?></label><input id="pw1" name="new" type="password" autocomplete="new-password" minlength="8" required></div>
      <div class="field"><label for="pw2"><?= h(t('settings.new2')) ?></label><input id="pw2" name="new2" type="password" autocomplete="new-password" minlength="8" required></div>
      <p class="row"><button class="btn primary" type="submit"><?= h(t('settings.change')) ?></button> <span class="msg-inline" role="status" data-ok="<?= h(t('settings.changed')) ?>"></span></p>
    </form>
  </section>

  <section class="card">
    <h2><?= h(t('settings.lang')) ?></h2>
    <p class="row">
      <select data-lang aria-label="<?= h(t('settings.lang')) ?>">
        <option value="auto"<?= $curLang === 'auto' ? ' selected' : '' ?>>Auto</option>
        <option value="fr"<?= $curLang === 'fr' ? ' selected' : '' ?>>Français</option>
        <option value="en"<?= $curLang === 'en' ? ' selected' : '' ?>>English</option>
      </select>
    </p>
  </section>

  <?php $st = sk_settings(); ?>
  <section class="card">
    <h2><?= h(t('settings.styles')) ?></h2>
    <p class="muted"><?= h(t('settings.stylesLead')) ?></p>
    <form data-styles>
      <p class="check"><label><input type="checkbox" name="builtin"<?= ($st['styles_builtin'] ?? true) !== false ? ' checked' : '' ?>> <?= h(t('settings.stylesBuiltin')) ?></label></p>
      <p class="muted small"><?= h(t('settings.stylesBuiltinNote')) ?></p>
      <div class="two">
        <div class="field">
          <label for="st-inline"><?= h(t('settings.stylesInline')) ?></label>
          <textarea id="st-inline" name="inline" rows="6" spellcheck="false" class="mono" placeholder="Fin de titre = span h1-tail&#10;Gris = text-slate-2&#10;Petit = small"><?= h((string) ($st['styles_inline'] ?? '')) ?></textarea>
        </div>
        <div class="field">
          <label for="st-block"><?= h(t('settings.stylesBlock')) ?></label>
          <textarea id="st-block" name="block" rows="6" spellcheck="false" class="mono" placeholder="Introduction = text-lead&#10;Note = text-small text-slate-2"><?= h((string) ($st['styles_block'] ?? '')) ?></textarea>
        </div>
      </div>
      <p class="row"><button class="btn primary" type="submit"><?= h(t('settings.stylesSave')) ?></button> <span class="msg-inline" role="status" data-ok="<?= h(t('settings.stylesSaved')) ?>"></span></p>
    </form>
  </section>

  <section class="card">
    <h2><?= h(t('settings.bookmarklet')) ?></h2>
    <p class="muted"><?= h(t('settings.bookmarkletLead')) ?></p>
    <p><a class="btn bookmarklet" href="<?= h($bookmarklet) ?>" draggable="true"><?= h(t('settings.bookmarkletName')) ?></a></p>
  </section>

  <section class="card">
    <h2><?= h(t('settings.install')) ?></h2>
    <dl class="facts">
      <dt><?= h(t('settings.root')) ?></dt><dd><code><?= h(sk_site_root()) ?></code></dd>
      <dt><?= h(t('settings.siteUrl')) ?></dt><dd><code><?= h($origin . sk_site_url()) ?></code></dd>
      <dt><?= h(t('settings.writable')) ?></dt><dd><?= $writable ?> / <?= count($pages) ?></dd>
      <dt><?= h(t('settings.data')) ?></dt><dd><?= is_writable(SKRID_DATA) ? h(t('settings.ok')) : '<strong class="bad">' . h(t('settings.ko')) . '</strong>' ?></dd>
      <dt>PHP</dt><dd><?= h(PHP_VERSION) ?> · Skrid <?= SKRID_VERSION ?></dd>
    </dl>
  </section>
<?php endif; ?>
</main>
</body>
</html>
