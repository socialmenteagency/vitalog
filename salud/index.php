<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/payload.php';
require dirname(__DIR__) . '/lib/i18n.php';

$lang = ($_GET['lang'] ?? '') === 'pt' ? 'pt' : (($_GET['lang'] ?? '') === 'es' ? 'es' : null);
$langAttr = $lang ?? 'es';
$t = SALUD_I18N[$langAttr];

if (isset($_GET['logout'])) {
    salud_logout();
    header('Location: ./');
    exit;
}

$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (salud_login_blocked()) {
        $loginError = $t['login_blocked'];
    } elseif (salud_try_login((string)$_POST['password'], 'viewer')) {
        header('Location: ./' . ($lang ? "?lang=$lang" : ''));
        exit;
    } else {
        $loginError = salud_login_blocked() ? $t['login_blocked'] : $t['login_error'];
    }
}

$assets = __DIR__ . '/assets';
$cssV = @filemtime("$assets/styles.css") ?: 1;
$jsV  = @filemtime("$assets/app.js") ?: 1;

if (!salud_has_role('viewer')): ?>
<!doctype html>
<html lang="<?= e($langAttr) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($t['login_title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/styles.css?v=<?= $cssV ?>">
</head>
<body class="login-body">
<main class="login-card">
  <p class="login-eyebrow">Vitalog</p>
  <h1 class="login-title"><?= e($t['login_title']) ?></h1>
  <p class="login-prompt"><?= e($t['login_prompt']) ?></p>
  <?php if ($loginError): ?><p class="login-error" role="alert"><?= e($loginError) ?></p><?php endif; ?>
  <form method="post" class="login-form">
    <label class="visually-hidden" for="password"><?= e($t['password']) ?></label>
    <input id="password" name="password" type="password" autocomplete="current-password"
           placeholder="<?= e($t['password']) ?>" required autofocus>
    <button type="submit"><?= e($t['login_button']) ?></button>
  </form>
  <p class="login-langs">
    <a href="?lang=es" <?= $langAttr === 'es' ? 'class="active"' : '' ?>>Español</a>
    <span aria-hidden="true">·</span>
    <a href="?lang=pt" <?= $langAttr === 'pt' ? 'class="active"' : '' ?>>Português</a>
  </p>
</main>
</body>
</html>
<?php exit; endif;

$pdo = salud_db();
$pid = salud_current_person_id($pdo);
if (!salud_person($pdo, $pid)) {   // sesión de viewer sin persona válida (p. ej. persona eliminada)
    salud_logout();
    header('Location: ./');
    exit;
}
$payload = salud_build_payload($pdo, $pid);
$isAdmin = ($_SESSION['role'] ?? null) === 'admin';
?>
<!doctype html>
<html lang="<?= e($langAttr) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($t['title']) ?> — <?= e($payload['patient']['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/styles.css?v=<?= $cssV ?>">
</head>
<body>
<div id="app" class="app"></div>
<script>
window.SALUD = {
  data: <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  i18n: <?= json_encode(SALUD_I18N, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  lang: <?= json_encode($lang, JSON_UNESCAPED_UNICODE) ?>,
  links: { logout: '?logout=1', print: true<?= $isAdmin ? ", backend: '../backend/?p=" . $pid . "'" : '' ?> }
};
</script>
<script src="assets/app.js?v=<?= $jsV ?>"></script>
</body>
</html>
