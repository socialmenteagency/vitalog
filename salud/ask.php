<?php
// POST /salud/ask.php — responde una pregunta libre con los datos de la persona (JSON).
// La llamada a la IA es lenta: se suelta el bloqueo de la sesión antes de hacerla.
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/payload.php';
require dirname(__DIR__) . '/lib/gemini.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ask_out(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$lang = ($_POST['lang'] ?? '') === 'pt' ? 'pt' : 'es';
$pt = $lang === 'pt';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ask_out(405, ['error' => 'Método no permitido.']);
if (!salud_has_role('viewer')) ask_out(401, ['error' => $pt ? 'Sua sessão expirou. Recarregue a página.' : 'Tu sesión expiró. Recarga la página.']);
$sent = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
    ask_out(400, ['error' => $pt ? 'Recarregue a página e tente de novo.' : 'Recarga la página e intenta de nuevo.']);
}

$pdo = salud_db();
$pid = salud_current_person_id($pdo);
if (!salud_person($pdo, $pid)) ask_out(401, ['error' => $pt ? 'Sua sessão expirou. Recarregue a página.' : 'Tu sesión expiró. Recarga la página.']);
if (!salud_ask_enabled()) ask_out(503, ['error' => $pt ? 'A IA ainda não está configurada.' : 'La IA todavía no está configurada.']);

$isAdmin = ($_SESSION['role'] ?? null) === 'admin';
session_write_close();
set_time_limit(120);

try {
    $item = salud_ask($pdo, $pid, (string)($_POST['question'] ?? ''), $lang);
    ask_out(200, ['ok' => true, 'item' => $item, 'left' => max(0, SALUD_ASK_DAILY_LIMIT - salud_ask_today($pdo, $pid))]);
} catch (InvalidArgumentException $e) {
    ask_out(422, ['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    error_log('[salud ask] ' . $e->getMessage());
    $generic = $pt ? 'Não consegui responder agora. Tente de novo em um momento.' : 'No pude responder ahora. Intenta de nuevo en un momento.';
    ask_out(502, ['error' => $isAdmin ? $generic . ' (' . $e->getMessage() . ')' : $generic]);
}
