<?php
// Autenticación por contraseña: 'viewer' para /salud (una contraseña por persona),
// 'admin' para /backend (una sola). Una sesión admin también puede ver /salud.
// Rate limit por IP en archivo (8 fallos / 15 min → bloqueo 15 min).

declare(strict_types=1);

const SALUD_MAX_FAILS = 8;
const SALUD_FAIL_WINDOW = 900; // segundos

function salud_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

function salud_attempts_file(): string {
    return SALUD_DATA_DIR . '/login_attempts.json';
}

function salud_login_blocked(): bool {
    $file = salud_attempts_file();
    if (!is_file($file)) return false;
    $all = json_decode((string)file_get_contents($file), true) ?: [];
    $rec = $all[salud_client_ip()] ?? null;
    if (!$rec) return false;
    if (time() - $rec['first'] > SALUD_FAIL_WINDOW) return false;
    return $rec['count'] >= SALUD_MAX_FAILS;
}

function salud_record_attempt(bool $success): void {
    $file = salud_attempts_file();
    if (!is_dir(SALUD_DATA_DIR)) mkdir(SALUD_DATA_DIR, 0755, true);
    $all = json_decode(is_file($file) ? (string)file_get_contents($file) : '', true) ?: [];
    $ip = salud_client_ip();
    if ($success) {
        unset($all[$ip]);
    } else {
        $rec = $all[$ip] ?? ['first' => time(), 'count' => 0];
        if (time() - $rec['first'] > SALUD_FAIL_WINDOW) $rec = ['first' => time(), 'count' => 0];
        $rec['count']++;
        $all[$ip] = $rec;
    }
    // limpiar registros viejos
    foreach ($all as $k => $rec) {
        if (time() - $rec['first'] > SALUD_FAIL_WINDOW * 2) unset($all[$k]);
    }
    file_put_contents($file, json_encode($all), LOCK_EX);
}

// Login: 'admin' = contraseña única del backend (config); 'viewer' = la contraseña
// propia de una persona (cada una ve solo sus datos). Las contraseñas de las personas
// se guardan con hash en la tabla people y se cambian desde el backend.
function salud_try_login(string $password, string $role): bool {
    if (salud_login_blocked()) return false;
    $personId = null;
    if ($role === 'admin') {
        $ok = hash_equals(BACKEND_PASSWORD, $password);
    } else {
        $personId = salud_find_person_by_password(salud_db(), $password);
        $ok = $personId !== null;
    }
    if (!$ok) {
        // segundo de castigo: los ataques por fuerza bruta se vuelven inviables
        sleep(1);
    }
    salud_record_attempt($ok);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['role'] = $role === 'admin' ? 'admin' : 'viewer';
        $_SESSION['person_id'] = $personId;
        $_SESSION['login_at'] = time();
    }
    return $ok;
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
