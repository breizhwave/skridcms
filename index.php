<?php
declare(strict_types=1);

/** Login. Once in, go back where you came from, or browse the site's home page. */

require __DIR__ . '/lib/core.php';

// "/skrid" without its final slash: send the browser to "/skrid/" so every relative link works.
$reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($reqPath !== '' && $reqPath === rtrim(sk_skrid_url(), '/')) {
    $q = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    header('Location: ' . sk_skrid_url() . ($q !== '' ? '?' . $q : ''), true, 301);
    exit;
}

sk_private_headers();
sk_session();

/** Only paths inside this site are accepted as "next" (no open redirect). */
function login_next(string $next): string
{
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || preg_match('/[\r\n]/', $next)) {
        return sk_skrid_url() . 'view.php?p=' . rawurlencode(sk_site_url());
    }
    return $next;
}

$next = login_next((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
if (sk_logged_in()) {
    header('Location: ' . $next);
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $r = sk_login((string) ($_POST['password'] ?? ''), !empty($_POST['remember']));
    if ($r === 'ok') {
        header('Location: ' . $next);
        exit;
    }
    $error = $r === 'blocked' ? t('login.blocked') : t('login.wrong');
}
?>
<!doctype html>
<html lang="<?= h(skrid_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<base href="<?= h(sk_skrid_url()) ?>">
<title>Skrid · <?= h(t('login.title')) ?></title>
<link rel="icon" href="assets/skrid.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/admin.css?v=<?= SKRID_VERSION ?>">
</head>
<body class="login">
<main class="login-card">
  <p class="brand"><img src="assets/skrid.svg" alt="" width="36" height="36"> <span>Skrid</span></p>
  <h1><?= h(t('login.title')) ?></h1>
  <p class="muted"><?= h(t('login.lead')) ?></p>
  <form method="post" action="index.php">
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label for="pw"><?= h(t('login.password')) ?></label>
    <input id="pw" name="password" type="password" autocomplete="current-password" required autofocus>
    <label class="check"><input type="checkbox" name="remember" value="1"<?= sk_is_local() || !empty($_POST['remember']) ? ' checked' : '' ?>> <?= h(t('login.remember', (string) sk_remember_days())) ?></label>
    <?php if ($error): ?><p class="msg error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <button class="btn primary" type="submit"><?= h(t('login.submit')) ?></button>
  </form>
</main>
</body>
</html>
