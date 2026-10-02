<?php
// Bootstrap común: config, sesión, helpers. Incluir al inicio de cada entrypoint.

declare(strict_types=1);

define('SALUD_APP_DIR', dirname(__DIR__));
define('SALUD_DATA_DIR', SALUD_APP_DIR . '/data');

// Config: vive en data/config.php (fuera de git, editable por File Manager).
// Si no existe, en desarrollo (SALUD_DEV=1) se usan valores demo; en producción
// se corta con un mensaje de instalación.
if (is_file(SALUD_DATA_DIR . '/config.php')) {
    require SALUD_DATA_DIR . '/config.php';
} elseif (getenv('SALUD_DEV') === '1') {
    define('SALUD_PASSWORD', 'demo');
    define('BACKEND_PASSWORD', 'demo-admin');
    define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
    define('SEED_DUMMY', true);
} else {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Falta data/config.php — copia config.sample.php a data/config.php y completa las contraseñas.\n");
}

if (!defined('SEED_DUMMY')) define('SEED_DUMMY', true);
// Modo demo (solo para un sitio público de demostración): entra sin contraseña, no guarda nada,
// no sube archivos y no llama a ninguna IA. En una instalación real no se define.
if (!defined('SALUD_DEMO_MODE')) define('SALUD_DEMO_MODE', false);
if (!defined('ANTHROPIC_API_KEY')) define('ANTHROPIC_API_KEY', '');
// Resumen y recomendaciones con IA (Gemini). Usar una key de cuenta de pago: el tier
// gratuito de Google puede usar los datos enviados para mejorar sus productos.
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', '');
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash');

require __DIR__ . '/analytes.php';
require __DIR__ . '/ranges.php';

date_default_timezone_set('America/Montevideo');
mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli') {
    session_name('saludsid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (SALUD_DEMO_MODE) {
        header('X-Robots-Tag: noindex, nofollow');
        if (empty($_SESSION['role'])) {
            $_SESSION['role'] = 'admin';
            $_SESSION['person_id'] = null;
            $_SESSION['login_at'] = time();
        }
    }
}

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
