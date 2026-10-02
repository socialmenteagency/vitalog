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
// Fuerza HTTPS (redirección + HSTS). Poner false solo si el servidor no tiene certificado.
if (!defined('SALUD_FORCE_HTTPS')) define('SALUD_FORCE_HTTPS', true);
if (!defined('ANTHROPIC_API_KEY')) define('ANTHROPIC_API_KEY', '');
// Resumen y recomendaciones con IA (Gemini). Usar una key de cuenta de pago: el tier
// gratuito de Google puede usar los datos enviados para mejorar sus productos.
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', '');
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash');

require __DIR__ . '/analytes.php';
require __DIR__ . '/ranges.php';

date_default_timezone_set('America/Montevideo');
mb_internal_encoding('UTF-8');

// Tras un proxy (p. ej. Cloudflare) el servidor ve HTTP aunque el visitante use HTTPS.
function salud_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', '"scheme":"https"');
}

// Cabeceras de seguridad en todas las páginas. La CSP no permite scripts en línea ni orígenes externos:
// aunque un texto guardado llegara a la página sin escapar, el navegador no lo ejecutaría.
function salud_security_headers(): void {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
         . "img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

// Vida de la sesión: el admin expira a las 2 h sin actividad o 12 h desde el acceso; una persona, 12 h sin actividad
// o 30 días desde el acceso.
const SALUD_IDLE_ADMIN = 7200, SALUD_ABS_ADMIN = 43200, SALUD_IDLE_VIEWER = 43200, SALUD_ABS_VIEWER = 2592000;

if (PHP_SAPI !== 'cli') {
    salud_security_headers();

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $isLocal = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$|\.localhost(:\d+)?$/', $host);
    if (SALUD_FORCE_HTTPS && !$isLocal && !salud_is_https()) {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
            header('Location: https://' . preg_replace('/:\d+$/', '', $host) . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        } else {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "HTTPS required.\n";
        }
        exit;
    }
    if (salud_is_https() && !SALUD_DEMO_MODE) header('Strict-Transport-Security: max-age=31536000');

    ini_set('session.use_strict_mode', '1');
    session_name('saludsid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => salud_is_https(),
    ]);
    // Sin cookie de sesión (visitas anónimas, escáneres) no se crea sesión; se crea al enviar un formulario (login).
    if (isset($_COOKIE['saludsid']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || SALUD_DEMO_MODE) {
        session_start();
        if (!empty($_SESSION['role']) && !SALUD_DEMO_MODE) {
            $now = time();
            $admin = $_SESSION['role'] === 'admin';
            if ($now - ($_SESSION['last'] ?? $now) > ($admin ? SALUD_IDLE_ADMIN : SALUD_IDLE_VIEWER)
                || $now - ($_SESSION['login_at'] ?? $now) > ($admin ? SALUD_ABS_ADMIN : SALUD_ABS_VIEWER)) {
                $_SESSION = [];
                session_regenerate_id(true);
            } else {
                $_SESSION['last'] = $now;
            }
        }
    }
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
