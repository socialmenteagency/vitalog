<?php
// Autenticación por contraseña: 'viewer' para /salud (una contraseña por persona),
// 'admin' para /backend (una sola). Una sesión admin también puede ver /salud.
// Rate limit por IP en archivo (8 fallos / 15 min → bloqueo 15 min).

declare(strict_types=1);

const SALUD_MAX_FAILS = 8;          // intentos fallidos por IP
const SALUD_MAX_FAILS_ADMIN = 10;   // contraseña del backend, sumando todas las IP
const SALUD_MAX_FAILS_GLOBAL = 60;  // todos los intentos fallidos de todas las IP
const SALUD_FAIL_WINDOW = 900;      // segundos (15 min)

function salud_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

function salud_attempts_file(): string {
    return SALUD_DATA_DIR . '/login_attempts.json';
}

// Limitador de intentos con bloqueo de archivo (flock): cada intento se REGISTRA ANTES de verificar la contraseña,
// así varias peticiones en paralelo no pueden pasar todas el control. Un acceso correcto devuelve el intento.
// Estructura: {"ip": {ip: {first, count}}, "admin": {first, count}, "global": {first, count}}.
function salud_rate_open() {
    if (!is_dir(SALUD_DATA_DIR)) mkdir(SALUD_DATA_DIR, 0755, true);
    $fh = @fopen(salud_attempts_file(), 'c+');
    if ($fh) flock($fh, LOCK_EX);
    else error_log('salud: no se pudo abrir login_attempts.json; el límite de intentos no está activo');
    return $fh;
}

function salud_rate_read($fh): array {
    rewind($fh);
    $all = json_decode((string)stream_get_contents($fh), true);
    $all = is_array($all) ? $all : [];
    $all['ip'] = isset($all['ip']) && is_array($all['ip']) ? $all['ip'] : [];
    $now = time();
    foreach ($all['ip'] as $k => $rec) {   // un archivo con otra forma (editado a mano, versión vieja) se descarta, no rompe el login
        if (!is_array($rec) || $now - (int)($rec['first'] ?? 0) > SALUD_FAIL_WINDOW) unset($all['ip'][$k]);
    }
    foreach (['admin', 'global'] as $k) {
        $rec = $all[$k] ?? null;
        $all[$k] = is_array($rec) && $now - (int)($rec['first'] ?? 0) <= SALUD_FAIL_WINDOW ? $rec : null;
    }
    return $all;
}

function salud_rate_write($fh, array $all): void {
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($all));
    fflush($fh);
}

function salud_rate_count($rec): int { return is_array($rec) ? (int)($rec['count'] ?? 0) : 0; }

function salud_rate_over(array $all, string $role): bool {
    return salud_rate_count($all['ip'][salud_client_ip()] ?? null) >= SALUD_MAX_FAILS
        || salud_rate_count($all['global']) >= SALUD_MAX_FAILS_GLOBAL
        || ($role === 'admin' && salud_rate_count($all['admin']) >= SALUD_MAX_FAILS_ADMIN);
}

function salud_login_blocked(string $role = 'viewer'): bool {
    $fh = salud_rate_open();
    if (!$fh) return false;
    $blocked = salud_rate_over(salud_rate_read($fh), $role);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $blocked;
}

// Reserva un intento. Devuelve false (sin reservar) si ya se superó algún límite.
function salud_rate_reserve(string $role): bool {
    $fh = salud_rate_open();
    if (!$fh) return true;   // sin disco no hay límite: mejor que bloquear a todos
    $all = salud_rate_read($fh);
    if (salud_rate_over($all, $role)) { flock($fh, LOCK_UN); fclose($fh); return false; }
    $now = time();
    $ip = salud_client_ip();
    $keys = $role === 'admin' ? ['admin', 'global'] : ['global'];
    $all['ip'][$ip] = ['first' => $all['ip'][$ip]['first'] ?? $now, 'count' => salud_rate_count($all['ip'][$ip] ?? null) + 1];
    foreach ($keys as $k) $all[$k] = ['first' => $all[$k]['first'] ?? $now, 'count' => salud_rate_count($all[$k]) + 1];
    salud_rate_write($fh, $all);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

function salud_rate_refund(string $role): void {
    $fh = salud_rate_open();
    if (!$fh) return;
    $all = salud_rate_read($fh);
    $ip = salud_client_ip();
    // devuelve solo ESTE intento (no borra los anteriores: quien tenga una clave válida no puede reiniciar su contador)
    foreach ($role === 'admin' ? ['admin', 'global'] : ['global'] as $k) {
        if ($all[$k]) {
            $all[$k]['count'] = max(0, $all[$k]['count'] - 1);
            if ($all[$k]['count'] === 0) $all[$k] = null;
        }
    }
    if (isset($all['ip'][$ip])) {
        $all['ip'][$ip]['count'] = max(0, (int)$all['ip'][$ip]['count'] - 1);
        if ($all['ip'][$ip]['count'] === 0) unset($all['ip'][$ip]);
    }
    salud_rate_write($fh, $all);
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Login: 'admin' = contraseña única del backend (config; BACKEND_PASSWORD_HASH, un password_hash(), tiene prioridad
// si existe); 'viewer' = la contraseña propia de una persona (cada una ve solo sus datos). Las contraseñas de las
// personas se guardan con hash en la tabla people y se cambian desde el backend.
function salud_try_login(string $password, string $role): bool {
    if (!salud_rate_reserve($role)) return false;
    $personId = null;
    if ($role === 'admin') {
        $ok = defined('BACKEND_PASSWORD_HASH') && BACKEND_PASSWORD_HASH !== ''
            ? password_verify($password, BACKEND_PASSWORD_HASH)
            : hash_equals(BACKEND_PASSWORD, $password);
    } else {
        $personId = salud_find_person_by_password(salud_db(), $password);
        $ok = $personId !== null;
    }
    if (!$ok) {
        usleep(250000);   // frena un poco; el tope real lo pone el limitador, que no retiene el servidor
        return false;
    }
    salud_rate_refund($role);
    session_regenerate_id(true);
    $_SESSION['role'] = $role === 'admin' ? 'admin' : 'viewer';
    $_SESSION['person_id'] = $personId;
    $_SESSION['login_at'] = time();
    $_SESSION['last'] = time();
    return true;
}

// Persona cuyos datos se muestran: el viewer, la suya; el admin, la pedida en ?p=
// (o la última que eligió en el backend, o la 1).
function salud_current_person_id(PDO $pdo): int {
    if (($_SESSION['role'] ?? null) === 'admin') {
        $id = (int)($_GET['p'] ?? ($_SESSION['admin_person'] ?? 1));
        return salud_person($pdo, $id) ? $id : 1;
    }
    return (int)($_SESSION['person_id'] ?? 0);
}

function salud_has_role(string $role): bool {
    $current = $_SESSION['role'] ?? null;
    if ($current === 'admin') return true;          // admin ve todo
    return $role === 'viewer' && $current === 'viewer';
}

function salud_logout(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function salud_csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function salud_csrf_check(): void {
    $sent = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('CSRF token inválido — recarga la página e intenta de nuevo.');
    }
}
