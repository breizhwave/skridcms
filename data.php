<?php
declare(strict_types=1);

/**
 * Data editor: one of the site's .json files as a form (lists of items, each with its fields),
 * or as raw JSON. Saving rewrites the file with the same indentation; a copy is kept before each save.
 * ?f=data/selectedwork.json  [&q=text: open the item that holds it]  [&from=/fr/portfolio/: page to go back to]
 */

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/ui.php';
sk_require_login();
sk_private_headers();

$rel = sk_data_resolve((string) ($_GET['f'] ?? ''));
if ($rel === null) {
    header('Location: admin.php?tab=data');
    exit;
}
$file = sk_file($rel);
$s = (string) file_get_contents($file);
$error = '';
$value = null;
try {
    $value = json_decode($s, false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    $error = $e->getMessage();
}
$pages = sk_pages();
$users = sk_data_usage($pages)[$rel] ?? [];
$fromRel = isset($_GET['from']) ? sk_resolve((string) $_GET['from']) : null;
$fromUrl = $fromRel !== null ? sk_page_url($fromRel) : ($users[0]['url'] ?? sk_site_url());
$backups = sk_backups($rel);
[$lists, $items] = $error === '' ? sk_data_summary($value) : [0, 0];
preg_match('/^([ \t]+)[\["{]/m', $s, $im);

$config = [
    'file' => $rel,
    'hash' => sha1($s),
    'site' => sk_site_url(),
    'lang' => skrid_lang(),
    'indent' => $im[1] ?? '    ',
    'q' => (string) ($_GET['q'] ?? ''),
    'error' => $error,
    'writable' => is_writable($file),
    'texts' => t_group('data.'),
];
$viewUrl = static fn (string $url, string $extra = ''): string => 'view.php?p=' . rawurlencode($url) . $extra;
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
<title>Skrid · <?= h(basename($rel)) ?></title>
<link rel="icon" href="assets/skrid.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/admin.css?v=<?= SKRID_VERSION ?>">
<script src="assets/admin.js?v=<?= SKRID_VERSION ?>" defer></script>
<script src="assets/data.js?v=<?= SKRID_VERSION ?>" defer></script>
</head>
<body>
<?= sk_top_bar('data', $viewUrl($fromUrl), sk_site_name($pages), true) ?>

<main class="wrap data-wrap" data-editor data-config="<?= h(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
  <p class="crumbs">
    <a href="admin.php?tab=data"><?= h(t('data.back')) ?></a>
    <?php if ($fromRel !== null): ?>
      <span aria-hidden="true">·</span> <a href="<?= h($viewUrl($fromUrl, '&mode=edit')) ?>"><?= h(t('data.backToPage')) ?></a>
    <?php endif; ?>
  </p>
  <div class="page-head">
    <div>
      <h1><?= h(basename($rel)) ?></h1>
      <p class="muted"><code><?= h($rel) ?></code> · <?= h(sk_size(strlen($s))) ?>
        <?php if ($error === ''): ?> · <?= h($lists > 1 ? t('data.summaryN', $lists, $items) : t('data.summary1', $items)) ?><?php endif; ?>
        <?= is_writable($file) ? '' : ' · <strong class="bad">' . h(t('pages.notwritable')) . '</strong>' ?></p>
      <?php if ($users): ?>
        <p class="used"><?= h(t('data.usedBy')) ?>
          <?php foreach ($users as $u): ?>
            <a class="chip" href="<?= h($viewUrl($u['url'], '&mode=edit')) ?>"><span class="lang"><?= h($u['lang'] ?: '—') ?></span> <?= h($u['url']) ?></a>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
    </div>
  </div>

  <div class="dtools" data-tools>
    <input class="search" type="search" data-find placeholder="<?= h(t('data.search')) ?>" aria-label="<?= h(t('data.search')) ?>">
    <span class="seg" role="group" aria-label="<?= h(t('data.view')) ?>">
      <button type="button" data-view="form" aria-pressed="true"><?= h(t('data.form')) ?></button>
      <button type="button" data-view="raw" aria-pressed="false">JSON</button>
    </span>
    <button class="btn small ghost" type="button" data-fold="open"><?= h(t('pages.expand')) ?></button>
    <button class="btn small ghost" type="button" data-fold="close"><?= h(t('pages.collapse')) ?></button>
    <span class="grow"></span>
    <span class="dstatus" role="status" aria-live="polite" data-status></span>
    <button class="btn small ghost" type="button" data-discard hidden><?= h(t('data.discard')) ?></button>
    <button class="btn primary" type="button" data-save disabled><?= h(t('data.save')) ?></button>
  </div>

  <div class="dform" data-form></div>
  <div class="draw" data-rawbox hidden>
    <p class="msg error" data-raw-error hidden></p>
    <textarea class="mono" data-raw spellcheck="false" aria-label="JSON"></textarea>
  </div>

  <section class="card">
    <h2><?= h(t('page.history')) ?></h2>
    <p class="muted"><?= h(t('data.historyLead', (int) sk_config()['backups_per_page'])) ?></p>
    <?php if (!$backups): ?>
      <p><?= h(t('data.noHistory')) ?></p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($backups as $b): ?>
          <li>
            <span class="when"><?= h(sk_date($b['time'])) ?></span>
            <span class="muted"><?= h(sk_size($b['size'])) ?></span>
            <span class="grow"></span>
            <button class="btn small" type="button" data-data-restore="<?= h($b['name']) ?>" data-file="<?= h($rel) ?>"
              data-confirm="<?= h(t('page.restoreConfirm')) ?>"><?= h(t('page.restore')) ?></button>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>
<script type="application/json" id="sk-data"><?= str_replace(['<', '>', '&'], ['<', '>', '&'], $error === '' ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) : (string) json_encode($s)) ?></script>
</body>
</html>
