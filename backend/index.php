<?php
// Vitalog /backend — panel de carga de la historia clínica.
// Flujo PDF: subir → extracción con Claude → revisión editable → guardar.
// También: importar Apple Health (export.zip o JSON), alta/edición manual, borrar exámenes.
// Multiusuario: arriba se elige la persona; todo lo que se carga va a la persona elegida.
// Resumen con IA (Gemini): se genera como borrador y solo se ve en /salud al publicarlo.

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/db.php';
require dirname(__DIR__) . '/lib/claude.php';
require dirname(__DIR__) . '/lib/apple.php';
require dirname(__DIR__) . '/lib/payload.php';
require dirname(__DIR__) . '/lib/gemini.php';

$pdo = salud_db();

// ---------- login / logout ----------

if (isset($_GET['logout'])) { salud_logout(); header('Location: ./'); exit; }

$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (salud_login_blocked('admin')) {
        $loginError = 'Demasiados intentos. Espera 15 minutos.';
    } elseif (salud_try_login((string)($_POST['password'] ?? ''), 'admin')) {
        header('Location: ./'); exit;
    } else {
        $loginError = 'Contraseña incorrecta.';
    }
}

function b_layout_top(string $title): void { ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — backend salud</title>
<link rel="icon" href="../salud/assets/favicon.svg" type="image/svg+xml">
<link rel="icon" href="../salud/assets/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="../salud/assets/favicon-32.png">
<link rel="apple-touch-icon" href="../salud/assets/apple-touch-icon.png">
<link rel="stylesheet" href="../salud/assets/fonts.css?v=1">
<style>
:root{--paper:#F7F9F8;--surface:#fff;--ink:#172523;--ink2:#546360;--ink3:#8AA09B;
--line:#E2EAE7;--accent:#0D9488;--accentd:#0B6E63;--bad:#B91C1C;--badw:#FBECEC;
--mono:"IBM Plex Mono",monospace;--sans:"IBM Plex Sans",sans-serif}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--paper);color:var(--ink);font:15px/1.5 var(--sans);-webkit-font-smoothing:antialiased}
.wrap{max-width:880px;margin:0 auto;padding:28px 20px 64px}
h1{font-family:"Fraunces",serif;font-weight:600;font-size:26px;letter-spacing:-.01em}
h2{font-family:"Fraunces",serif;font-weight:600;font-size:19px;margin-bottom:12px}
.eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--accentd);margin-bottom:6px}
.top{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;padding-bottom:18px;border-bottom:1px solid var(--line);margin-bottom:24px}
.top a{color:var(--ink2);font-size:13px;text-decoration:none;border:1px solid var(--line);border-radius:999px;padding:5px 13px;background:var(--surface)}
.top a:hover{color:var(--ink)}
.card{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:20px 22px;margin-bottom:18px;
box-shadow:0 1px 2px rgba(23,37,35,.05)}
.msg{border-radius:8px;padding:10px 14px;font-size:13.5px;margin-bottom:18px;background:#EBF5F3;color:var(--accentd);border:1px solid #CBE5E1}
.msg.err{background:var(--badw);color:var(--bad);border-color:#F2D2D2}
label{display:block;font-size:13px;font-weight:500;color:var(--ink2);margin-bottom:4px}
input[type=text],input[type=date],input[type=password],input[type=number],select,textarea{
font:inherit;padding:8px 11px;border:1px solid var(--line);border-radius:7px;background:var(--paper);color:var(--ink);width:100%}
textarea{resize:vertical}
input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
input[type=file]{font-size:13px}
button{font:inherit;font-weight:600;border:0;border-radius:8px;padding:9px 16px;background:var(--accentd);color:#fff;cursor:pointer}
button:hover{background:var(--accent)}
button.ghost{background:var(--surface);color:var(--ink2);border:1px solid var(--line)}
button.ghost:hover{color:var(--ink)}
button.danger{background:var(--surface);color:var(--bad);border:1px solid #F2D2D2}
button.danger:hover{background:var(--badw)}
button:focus-visible{outline:2px solid var(--ink);outline-offset:2px}
table{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums}
th{font-family:var(--mono);font-size:11px;font-weight:500;color:var(--ink2);text-align:left;padding:6px 10px;border-bottom:1px solid var(--line)}
td{padding:7px 10px;border-bottom:1px solid #EEF3F1;font-size:13.5px;vertical-align:middle}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.row>div{flex:1;min-width:140px}
.hint{font-size:12px;color:var(--ink3);margin-top:6px}
.pill{font-family:var(--mono);font-size:11px;color:var(--ink3)}
.right{text-align:right}
.actions{display:flex;gap:8px;justify-content:flex-end}
form.inline{display:inline}
.cat-hd td{font-size:11px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--ink2);background:var(--paper);border-top:1px solid var(--line)}
nav.people{display:flex;gap:8px;flex-wrap:wrap;margin:-8px 0 20px}
nav.people a{font-size:13.5px;text-decoration:none;color:var(--ink2);border:1px solid var(--line);border-radius:999px;padding:5px 14px;background:var(--surface)}
nav.people a.on{background:var(--accentd);border-color:var(--accentd);color:#fff}
.draft{background:var(--paper);border:1px solid var(--line);border-radius:8px;padding:14px 16px;font-size:14px}
.draft ol,.draft ul{padding-left:20px;margin-top:8px}.draft li{margin-bottom:5px}
@media(max-width:640px){.top{flex-direction:column;align-items:flex-start}}
</style>
<script src="backend.js" defer></script>
</head>
<body><div class="wrap">
<?php if (SALUD_DEMO_MODE): ?>
<p class="msg" role="note"><strong>Demo del backend</strong> · datos inventados; puedes mirar todo, pero los botones no guardan nada, no suben archivos y la IA está desactivada. <a href="https://socialmente.agency/vitalog/instalar/" style="color:inherit">Cómo instalarlo</a> · <a href="https://socialmente.agency/vitalog/" style="color:inherit">Volver a Vitalog</a> · <em>Demo of the admin panel: sample data, nothing is saved.</em></p>
<?php endif; ?>
<?php }

function b_layout_bottom(): void { echo '</div></body></html>'; }

if (!salud_has_role('admin') || ($_SESSION['role'] ?? '') !== 'admin') {
    b_layout_top('Acceso');
    ?>
    <div style="max-width:380px;margin:10vh auto 0" class="card">
      <p class="eyebrow">Vitalog</p>
      <h1 style="font-size:24px">Backend de salud</h1>
      <p class="hint" style="margin:8px 0 14px">Panel de carga de exámenes y datos de Apple Health.</p>
      <?php if ($loginError): ?><p class="msg err" role="alert"><?= e($loginError) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <label for="password">Contraseña del backend</label>
        <input id="password" type="password" name="password" required autofocus>
        <button type="submit" style="margin-top:12px;width:100%">Entrar</button>
      </form>
    </div>
    <?php
    b_layout_bottom();
    exit;
}

// ---------- acciones (admin autenticado) ----------

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;
$csrf = salud_csrf_token();

// Persona elegida (se recuerda en la sesión); todo lo que se carga va a ella
$people = salud_people($pdo);
$pid = (int)($_POST['p'] ?? $_GET['p'] ?? $_SESSION['admin_person'] ?? 1);
if (!salud_person($pdo, $pid)) $pid = $people ? (int)$people[0]['id'] : 1;
$_SESSION['admin_person'] = $pid;

function b_redirect(?string $msg = null, ?string $err = null, string $extra = '', ?int $person = null): never {
    global $pid;
    $q = ['p=' . ($person ?? $pid)];
    if ($extra !== '') $q[] = ltrim($extra, '?');
    if ($msg) $q[] = 'msg=' . urlencode($msg);
    if ($err) $q[] = 'err=' . urlencode($err);
    header('Location: ./?' . implode('&', $q));
    exit;
}

function b_pdf_dir(): string {
    $dir = SALUD_DATA_DIR . '/pdfs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'login') {
    if (SALUD_DEMO_MODE) b_redirect('Esto es una demo: no se guarda nada, no se suben archivos y la IA está desactivada. En tu instalación estos botones funcionan.');
    salud_csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_pdf') {
        $f = $_FILES['pdf'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) b_redirect(null, 'No se pudo subir el archivo (¿supera el límite del servidor?).');
        $mime = mime_content_type($f['tmp_name']);
        if ($mime !== 'application/pdf' || file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') b_redirect(null, 'El archivo tiene que ser un PDF.');
        $tmp = b_pdf_dir() . '/pending_' . session_id() . '.pdf';
        move_uploaded_file($f['tmp_name'], $tmp);
        $res = salud_extract_pdf($tmp);
        if (!$res['ok']) { @unlink($tmp); b_redirect(null, 'Extracción fallida: ' . $res['error']); }
        $_SESSION['pending'] = ['pdf' => $tmp, 'data' => $res['data'], 'orig_name' => $f['name']];
        b_redirect(null, null, '?review=1');
    }

    if ($action === 'save_exam') {
        $pending = $_SESSION['pending'] ?? null;
        $date = $_POST['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) b_redirect(null, 'Fecha inválida.');
        $lab = trim((string)($_POST['lab'] ?? '')) ?: null;
        $values = [];
        foreach ((array)($_POST['include'] ?? []) as $i => $_on) {
            $code = $_POST['code'][$i] ?? '';
            $val  = $_POST['value'][$i] ?? '';
            if (!isset(SALUD_ANALYTES[$code]) || !is_numeric($val)) continue;
            $values[$code] = (float)$val;
        }
        if (!$values) b_redirect(null, 'No hay valores seleccionados para guardar.');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO exams (person_id, date, lab) VALUES (?, ?, ?)')->execute([$pid, $date, $lab]);
        $examId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT OR REPLACE INTO results (exam_id, analyte_code, value) VALUES (?, ?, ?)');
        foreach ($values as $code => $v) $ins->execute([$examId, $code, $v]);
        if ($pending && is_file($pending['pdf'])) {
            $dest = 'exam_' . $examId . '.pdf';
            rename($pending['pdf'], b_pdf_dir() . '/' . $dest);
            $pdo->prepare('UPDATE exams SET pdf_file = ? WHERE id = ?')->execute([$dest, $examId]);
        }
        $pdo->commit();
        unset($_SESSION['pending']);
        b_redirect("Examen del $date guardado con " . count($values) . ' valores.');
    }

    if ($action === 'discard_pending') {
        $pending = $_SESSION['pending'] ?? null;
        if ($pending && is_file($pending['pdf'])) @unlink($pending['pdf']);
        unset($_SESSION['pending']);
        b_redirect('Extracción descartada.');
    }

    if ($action === 'new_exam') {
        $date = $_POST['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) b_redirect(null, 'Fecha inválida.');
        $lab = trim((string)($_POST['lab'] ?? '')) ?: null;
        $pdo->prepare('INSERT INTO exams (person_id, date, lab) VALUES (?, ?, ?)')->execute([$pid, $date, $lab]);
        b_redirect(null, null, '?edit=' . (int)$pdo->lastInsertId());
    }

    if ($action === 'save_edit') {
        $id = (int)($_POST['exam_id'] ?? 0);
        $date = $_POST['date'] ?? '';
        if (!$id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) b_redirect(null, 'Datos inválidos.');
        $lab = trim((string)($_POST['lab'] ?? '')) ?: null;
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE exams SET date = ?, lab = ? WHERE id = ?')->execute([$date, $lab, $id]);
        $pdo->prepare('DELETE FROM results WHERE exam_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO results (exam_id, analyte_code, value) VALUES (?, ?, ?)');
        foreach ((array)($_POST['values'] ?? []) as $code => $val) {
            if (isset(SALUD_ANALYTES[$code]) && $val !== '' && is_numeric($val)) $ins->execute([$id, $code, (float)$val]);
        }
        $pdo->commit();
        b_redirect("Examen del $date actualizado.");
    }

    if ($action === 'delete_exam') {
        $id = (int)($_POST['exam_id'] ?? 0);
        $pdf = $pdo->prepare('SELECT pdf_file FROM exams WHERE id = ?');
        $pdf->execute([$id]);
        $file = $pdf->fetchColumn();
        $pdo->prepare('DELETE FROM results WHERE exam_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM exams WHERE id = ?')->execute([$id]);
        if ($file) @unlink(b_pdf_dir() . '/' . $file);
        b_redirect('Examen eliminado.');
    }

    if ($action === 'apple_import') {
        // export.zip de Salud (se procesa acá) o el JSON de tools/apple_health_to_json.py
        $f = $_FILES['file'] ?? null;
        if ($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            b_redirect(null, 'El archivo supera el límite de subida del servidor (' . ini_get('upload_max_filesize') . ').');
        }
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) b_redirect(null, 'No se pudo subir el archivo.');
        set_time_limit(900);      // export.xml pesa cientos de MB: puede tardar unos segundos (tope 15 min)
        ignore_user_abort(true);
        try {
            $months = salud_apple_months_from_upload($f['tmp_name']);
        } catch (RuntimeException $ex) {
            b_redirect(null, $ex->getMessage());
        }
        $count = salud_apple_store($pdo, $months, $pid);
        b_redirect("Apple Health importado: $count valores mensuales actualizados.");
    }

    if ($action === 'save_person') {
        $r = salud_person_save($pdo, $pid, $_POST);
        if (isset($r['error'])) b_redirect(null, $r['error']);
        b_redirect('Perfil guardado.');
    }

    if ($action === 'new_person') {
        $r = salud_person_save($pdo, null, $_POST);
        if (isset($r['error'])) b_redirect(null, $r['error']);
        $_SESSION['admin_person'] = $r['id'];
        b_redirect('Persona creada. Completa su perfil y carga sus datos.', null, '', $r['id']);
    }

    if ($action === 'delete_person') {
        if ($pid === 1) b_redirect(null, 'La persona principal no se puede eliminar.');
        if (trim((string)($_POST['confirm_name'] ?? '')) !== salud_person($pdo, $pid)['name']) {
            b_redirect(null, 'Para eliminar, escribe el nombre exacto de la persona.');
        }
        salud_person_delete($pdo, $pid);
        $_SESSION['admin_person'] = 1;
        b_redirect('Persona eliminada con todos sus datos.', null, '', 1);
    }

    if ($action === 'gen_summary') {
        set_time_limit(0);
        ignore_user_abort(true);
        try {
            salud_ai_generate($pdo, $pid);
        } catch (RuntimeException $ex) {
            b_redirect(null, 'No se pudo generar: ' . $ex->getMessage());
        }
        b_redirect('Borrador generado. Léelo con calma y publícalo si está bien.');
    }

    if ($action === 'publish_summary') {
        b_redirect(salud_ai_publish($pdo, $pid) ? 'Resumen publicado: ya se ve en /salud.' : null,
                   salud_ai_row($pdo, $pid) ? null : 'No hay borrador para publicar.');
    }

    if ($action === 'unpublish_summary') {
        salud_ai_unpublish($pdo, $pid);
        b_redirect('Resumen retirado de /salud.');
    }

    if ($action === 'save_keys') {
        foreach (['gemini_api_key' => 'gemini_key', 'anthropic_api_key' => 'anthropic_key'] as $setting => $field) {
            $v = trim((string)($_POST[$field] ?? ''));
            if (!empty($_POST['clear_' . $field])) {
                salud_setting_set($setting, null);
            } elseif ($v !== '') {
                if (!preg_match('/^[\x21-\x7E]{20,300}$/', $v)) b_redirect(null, 'Esa clave no parece válida: va sin espacios y tiene entre 20 y 300 caracteres. Cópiala de nuevo completa.');
                salud_setting_set($setting, $v);
            }
        }
        $model = trim((string)($_POST['gemini_model'] ?? ''));
        if ($model !== '' && !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $model)) b_redirect(null, 'El nombre del modelo no es válido (ej.: gemini-2.5-flash).');
        salud_setting_set('gemini_model', $model === GEMINI_MODEL ? null : $model);
        salud_setting_set('ask_search', isset($_POST['ask_search']) ? '1' : '0');
        salud_setting_set('ask_search_off_until', null);   // guardar vuelve a intentar la búsqueda
        b_redirect('Ajustes de IA guardados.');
    }

    if ($action === 'import_exams') {
        $f = $_FILES['json'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) b_redirect(null, 'No se pudo subir el archivo JSON.');
        if (filesize($f['tmp_name']) > SALUD_JSON_MAX_BYTES) b_redirect(null, 'El JSON es demasiado grande (máximo 8 MB).');
        $data = json_decode((string)file_get_contents($f['tmp_name']), true);
        if (!is_array($data) || !isset($data['exams'])) {
            b_redirect(null, 'El JSON no tiene el formato esperado: {"exams":[{"date":"YYYY-MM-DD","lab":"...","results":{"hdl":55}}]}.');
        }
        $r = salud_import_exams($pdo, $data, $pid);
        $msg = "Importados {$r['imported']} exámenes ({$r['values']} valores).";
        if ($r['skipped']) $msg .= ' Omitidos por fecha ya existente: ' . implode(', ', $r['skipped']) . '.';
        b_redirect($msg);
    }

    if ($action === 'wipe_dummy') {
        salud_wipe_dummy($pdo);
        b_redirect('Datos de ejemplo eliminados. La base quedó vacía para cargar datos reales.');
    }

    b_redirect(null, 'Acción desconocida.');
}

// ---------- servir PDF ----------

if (isset($_GET['pdf'])) {
    $st = $pdo->prepare('SELECT pdf_file, date FROM exams WHERE id = ?');
    $st->execute([(int)$_GET['pdf']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $path = $row && $row['pdf_file'] ? b_pdf_dir() . '/' . basename($row['pdf_file']) : null;
    if (!$path || !is_file($path)) { http_response_code(404); exit('PDF no encontrado.'); }
    header_remove('Content-Security-Policy');   // el visor de PDF del navegador no funciona con default-src 'none'
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="examen_' . $row['date'] . '.pdf"');
    readfile($path);
    exit;
}

// ---------- vistas ----------

// -- revisión de extracción --
if (isset($_GET['review']) && !empty($_SESSION['pending'])) {
    $p = $_SESSION['pending'];
    $d = $p['data'];
    b_layout_top('Revisar extracción');
    ?>
    <div class="top">
      <div><p class="eyebrow">Paso 2 de 2 · revisión</p><h1>Revisar extracción</h1>
      <p class="hint">Valores leídos de <strong><?= e($p['orig_name']) ?></strong>. Corrige lo que haga falta antes de guardar.</p></div>
      <a href="./?p=<?= $pid ?>">Volver</a>
    </div>
    <form method="post" class="card">
      <input type="hidden" name="action" value="save_exam">
      <input type="hidden" name="p" value="<?= $pid ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="row" style="margin-bottom:16px">
        <div><label for="f-date">Fecha del examen</label>
          <input id="f-date" type="date" name="date" required value="<?= e($d['exam_date'] ?? '') ?>"></div>
        <div><label for="f-lab">Laboratorio</label>
          <input id="f-lab" type="text" name="lab" value="<?= e($d['lab'] ?? '') ?>" placeholder="opcional"></div>
      </div>
      <table>
        <thead><tr><th></th><th>En el PDF</th><th>Valor</th><th>Unidad PDF</th><th>Analito del catálogo</th></tr></thead>
        <tbody>
        <?php foreach ($d['results'] as $i => $r): ?>
          <tr>
            <td><input type="checkbox" name="include[<?= $i ?>]" <?= $r['code'] ? 'checked' : '' ?> aria-label="Incluir fila"></td>
            <td><?= e($r['name_raw']) ?></td>
            <td style="width:110px"><input type="number" step="any" name="value[<?= $i ?>]" value="<?= e((string)$r['value']) ?>" aria-label="Valor"></td>
            <td class="pill"><?= e($r['unit_raw']) ?></td>
            <td style="width:230px">
              <select name="code[<?= $i ?>]" aria-label="Analito">
                <option value="">— sin asignar —</option>
                <?php foreach (SALUD_ANALYTES as $code => [$cat, $nameEs, , $unit]): ?>
                  <option value="<?= e($code) ?>" <?= $r['code'] === $code ? 'selected' : '' ?>><?= e("$nameEs ($unit)") ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="actions" style="margin-top:18px">
        <button type="submit" class="ghost" form="discard-form">Descartar</button>
        <button type="submit">Guardar examen</button>
      </div>
    </form>
    <form method="post" id="discard-form">
      <input type="hidden" name="action" value="discard_pending">
      <input type="hidden" name="p" value="<?= $pid ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    </form>
    <?php
    b_layout_bottom();
    exit;
}

// -- edición manual --
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $st = $pdo->prepare('SELECT * FROM exams WHERE id = ? AND person_id = ?');
    $st->execute([$id, $pid]);
    $exam = $st->fetch(PDO::FETCH_ASSOC);
    if (!$exam) b_redirect(null, 'Examen no encontrado.');
    $st = $pdo->prepare('SELECT analyte_code, value FROM results WHERE exam_id = ?');
    $st->execute([$id]);
    $vals = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    b_layout_top('Editar examen');
    ?>
    <div class="top">
      <div><p class="eyebrow">Edición manual</p><h1>Examen del <?= e($exam['date']) ?></h1></div>
      <a href="./?p=<?= $pid ?>">Volver</a>
    </div>
    <form method="post" class="card">
      <input type="hidden" name="action" value="save_edit">
      <input type="hidden" name="p" value="<?= $pid ?>">
      <input type="hidden" name="exam_id" value="<?= $id ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="row" style="margin-bottom:16px">
        <div><label for="e-date">Fecha</label><input id="e-date" type="date" name="date" required value="<?= e($exam['date']) ?>"></div>
        <div><label for="e-lab">Laboratorio</label><input id="e-lab" type="text" name="lab" value="<?= e($exam['lab'] ?? '') ?>"></div>
      </div>
      <table>
        <thead><tr><th>Analito</th><th>Referencia</th><th style="width:130px">Valor</th></tr></thead>
        <tbody>
        <?php foreach (SALUD_CATEGORIES as $catCode => $catNames): ?>
          <tr class="cat-hd"><td colspan="3"><?= e($catNames['es']) ?></td></tr>
          <?php foreach (SALUD_ANALYTES as $code => [$cat, $nameEs, , $unit, $low, $high]):
            if ($cat !== $catCode) continue;
            $ref = $low !== null && $high !== null ? "$low–$high" : ($low !== null ? "≥ $low" : ($high !== null ? "≤ $high" : ''));
          ?>
          <tr>
            <td><?= e($nameEs) ?></td>
            <td class="pill"><?= e(trim("$ref $unit")) ?></td>
            <td><input type="number" step="any" name="values[<?= e($code) ?>]"
                       value="<?= isset($vals[$code]) ? e((string)$vals[$code]) : '' ?>" aria-label="<?= e($nameEs) ?>"></td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="actions" style="margin-top:18px"><button type="submit">Guardar cambios</button></div>
    </form>
    <?php
    b_layout_bottom();
    exit;
}

// -- dashboard --
$st = $pdo->prepare('SELECT e.*, (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id) AS n
                     FROM exams e WHERE e.person_id = ? ORDER BY date DESC');
$st->execute([$pid]);
$exams = $st->fetchAll(PDO::FETCH_ASSOC);
$st = $pdo->prepare('SELECT COUNT(DISTINCT month), MAX(month) FROM health_monthly WHERE person_id = ?');
$st->execute([$pid]);
[$healthCount, $lastMonth] = $st->fetch(PDO::FETCH_NUM);
$healthCount = (int)$healthCount;
$isDummy = $pid === 1 && salud_is_dummy($pdo);
$person = salud_person($pdo, $pid);
$ai = salud_ai_row($pdo, $pid);
$draft = $ai && $ai['draft_json'] ? json_decode($ai['draft_json'], true) : null;
$draftIsPublished = $ai && $ai['draft_json'] && $ai['draft_json'] === $ai['published_json'];

b_layout_top('Backend');
?>
<div class="top">
  <div><p class="eyebrow">Vitalog</p><h1>Historia clínica — carga</h1></div>
  <div style="display:flex;gap:8px">
    <a href="../salud/?p=<?= $pid ?>" target="_blank" rel="noopener">Ver /salud</a>
    <a href="?logout=1">Salir</a>
  </div>
</div>

<nav class="people" aria-label="Personas">
  <?php foreach ($people as $pp): ?>
    <a href="./?p=<?= (int)$pp['id'] ?>" class="<?= (int)$pp['id'] === $pid ? 'on' : '' ?>"><?= e($pp['name']) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($msg): ?><p class="msg" role="status"><?= e($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="msg err" role="alert"><?= e($err) ?></p><?php endif; ?>

<?php if ($isDummy): ?>
<div class="card" style="border-left:3px solid var(--accent)">
  <strong>La base tiene datos de ejemplo.</strong>
  <p class="hint" style="margin:4px 0 10px">Sirven para ver el diseño de /salud. Cuando quieras cargar tus datos reales, elimínalos.</p>
  <form method="post" data-confirm="¿Eliminar TODOS los datos de ejemplo?">
    <input type="hidden" name="action" value="wipe_dummy">
    <input type="hidden" name="p" value="<?= $pid ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="danger">Eliminar datos de ejemplo</button>
  </form>
</div>
<?php endif; ?>

<?php
$geminiKey = salud_gemini_key();
$claudeKey = salud_anthropic_key();
$keySource = fn(string $setting, string $fromConfig): string => salud_setting($setting) !== null ? 'guardada aquí' : ($fromConfig !== '' ? 'viene de data/config.php' : '');
?>
<details class="card" <?= ($geminiKey === '' || $claudeKey === '') ? 'open' : '' ?>>
  <summary style="cursor:pointer"><h2 style="display:inline">Claves de IA</h2>
    <span class="pill" style="margin-left:8px"><?= $geminiKey !== '' ? 'Gemini ✓' : 'Gemini: falta' ?> · <?= $claudeKey !== '' ? 'Claude ✓' : 'Claude: falta' ?></span></summary>
  <p class="hint" style="margin:10px 0">Pega aquí las claves de las IA: no hace falta editar ningún archivo. Se guardan en la base del servidor (carpeta protegida <code class="pill">data/</code>)
  y nunca se muestran completas. Sin ninguna clave la página funciona igual, solo sin las funciones de IA.</p>
  <form method="post" autocomplete="off">
    <input type="hidden" name="action" value="save_keys">
    <input type="hidden" name="p" value="<?= $pid ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div style="flex:2"><label for="k-gemini">Clave de Gemini (Google)</label>
        <input id="k-gemini" type="password" name="gemini_key" autocomplete="off" spellcheck="false"
               placeholder="<?= $geminiKey !== '' ? e(salud_mask_key($geminiKey)) . ' · ' . e($keySource('gemini_api_key', GEMINI_API_KEY)) . ' — pega otra para cambiarla' : 'Pega tu clave aquí' ?>">
        <p class="hint">Para el resumen, las recomendaciones y «Pregúntale a la IA». Se crea gratis en
        <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>;
        usa una cuenta <strong>con facturación activa</strong>, porque en el plan gratuito Google puede usar lo que se envía.
        <?php if (salud_setting('gemini_api_key') !== null): ?><label style="display:inline;font-weight:400"><input type="checkbox" name="clear_gemini_key" value="1"> borrar la clave guardada</label><?php endif; ?></p></div>
      <div><label for="k-model">Modelo de Gemini</label>
        <input id="k-model" type="text" name="gemini_model" maxlength="60" value="<?= e(salud_gemini_model()) ?>"></div>
    </div>
    <div style="margin-top:12px"><label for="k-claude">Clave de Claude (Anthropic)</label>
      <input id="k-claude" type="password" name="anthropic_key" autocomplete="off" spellcheck="false"
             placeholder="<?= $claudeKey !== '' ? e(salud_mask_key($claudeKey)) . ' · ' . e($keySource('anthropic_api_key', ANTHROPIC_API_KEY)) . ' — pega otra para cambiarla' : 'Pega tu clave aquí' ?>">
      <p class="hint">Solo para leer los PDF de laboratorio automáticamente. Se crea en
      <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com/settings/keys</a>.
      <?php if (salud_setting('anthropic_api_key') !== null): ?><label style="display:inline;font-weight:400"><input type="checkbox" name="clear_anthropic_key" value="1"> borrar la clave guardada</label><?php endif; ?></p></div>
    <div style="margin-top:12px"><label style="display:flex;gap:8px;align-items:flex-start;font-weight:400">
      <input type="checkbox" name="ask_search" value="1" <?= salud_ask_search() ? 'checked' : '' ?> style="margin-top:3px">
      <span>Dejar que «Pregúntale a la IA» busque en Google la etiqueta de un producto concreto (mejora las respuestas sobre alimentos de marca; Google cobra un extra por búsqueda pasado su cupo gratuito).</span></label>
      <?php if ((int)(salud_setting('ask_search_off_until') ?? 0) > time()): ?>
        <p class="hint" style="margin-top:6px"><strong style="color:var(--bad)">La búsqueda está en pausa:</strong> Google respondió que esta clave no tiene cupo de búsqueda (suele hacer falta facturación activa en el proyecto de la clave).
        Mientras tanto la IA responde sin buscar; se reintenta sola pasadas unas horas, o al guardar estos ajustes.</p>
      <?php endif; ?></div>
    <div class="actions" style="margin-top:12px;justify-content:flex-start"><button type="submit">Guardar ajustes de IA</button></div>
  </form>
</details>

<div class="card">
  <h2>Subir examen (PDF)</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload_pdf">
    <input type="hidden" name="p" value="<?= $pid ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div style="flex:2"><label for="u-pdf">PDF del laboratorio</label>
        <input id="u-pdf" type="file" name="pdf" accept="application/pdf" required></div>
      <div><button type="submit">Extraer valores</button></div>
    </div>
    <p class="hint">La IA lee el PDF y te muestra los valores para revisar antes de guardar (paso 2).
    <?php if (salud_anthropic_key() === ''): ?><strong style="color:var(--bad)">Falta la clave de Claude: pégala en «Claves de IA», arriba — sin ella la extracción no funciona.</strong><?php endif; ?></p>
  </form>
  <form method="post" style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">
    <input type="hidden" name="action" value="new_exam">
    <input type="hidden" name="p" value="<?= $pid ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div><label for="n-date">…o carga manual: fecha</label><input id="n-date" type="date" name="date" required></div>
      <div><label for="n-lab">Laboratorio</label><input id="n-lab" type="text" name="lab" placeholder="opcional"></div>
      <div><button type="submit" class="ghost">Crear y cargar a mano</button></div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Perfil de <?= e($person['name']) ?></h2>
  <p class="hint" style="margin-bottom:10px">Con estos datos /salud ajusta rangos y consejos (sexo, edad, estatura) y la IA adapta la comida y la actividad
  (lugar, gustos, alergias). A la página solo viaja la edad, no la fecha de nacimiento. Cada persona entra a /salud con su propia contraseña y ve solo lo suyo.</p>
  <form method="post">
    <input type="hidden" name="action" value="save_person">
    <input type="hidden" name="p" value="<?= $pid ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div style="flex:2"><label for="p-name">Nombre</label>
        <input id="p-name" type="text" name="name" required maxlength="80" value="<?= e($person['name']) ?>"></div>
      <div><label for="p-sex">Sexo</label>
        <select id="p-sex" name="sex">
          <option value="" <?= empty($person['sex']) ? 'selected' : '' ?>>— sin definir —</option>
          <option value="male" <?= $person['sex'] === 'male' ? 'selected' : '' ?>>Masculino</option>
          <option value="female" <?= $person['sex'] === 'female' ? 'selected' : '' ?>>Femenino</option>
        </select></div>
      <div><label for="p-birth">Fecha de nacimiento</label>
        <input id="p-birth" type="date" name="birth" value="<?= e($person['birth_date'] ?? '') ?>"></div>
      <div><label for="p-height">Estatura (cm)</label>
        <input id="p-height" type="number" name="height_cm" step="0.1" min="50" max="250" value="<?= e((string)($person['height_cm'] ?? '')) ?>"></div>
    </div>
    <div class="row" style="margin-top:12px">
      <div style="flex:2"><label for="p-loc">Dónde vive ahora</label>
        <input id="p-loc" type="text" name="location" maxlength="120" placeholder="Ej.: Montevideo, Uruguay · São Paulo, Brasil" value="<?= e($person['location'] ?? '') ?>">
        <p class="hint">Cámbialo cuando se mude de ciudad o país: la IA adapta los alimentos al lugar.</p></div>
      <div><label for="p-allerg">Alergias o restricciones</label>
        <input id="p-allerg" type="text" name="allergies" maxlength="300" placeholder="Ej.: intolerancia a la lactosa" value="<?= e($person['allergies'] ?? '') ?>"></div>
    </div>
    <div style="margin-top:12px"><label for="p-prefs">Gustos, preferencias y hábitos</label>
      <textarea id="p-prefs" name="prefs" rows="3" maxlength="1500" placeholder="Ej.: no me gusta el pescado; prefiero caminar a correr; no me gustan las naranjas pero comería mango todos los días"><?= e($person['prefs'] ?? '') ?></textarea></div>
    <div style="margin-top:12px"><label for="p-meds">Medicación o suplementos (opcional)</label>
      <input id="p-meds" type="text" name="meds" maxlength="500" placeholder="Solo para que la IA lo respete; no da consejos sobre ello" value="<?= e($person['meds'] ?? '') ?>"></div>
    <div class="row" style="margin-top:12px">
      <div><label for="p-pass">Contraseña de /salud</label>
        <input id="p-pass" type="password" name="password" autocomplete="new-password" minlength="12" placeholder="Vacío = no cambiarla">
        <p class="hint">Mínimo 8 caracteres y distinta a la de las demás personas.</p></div>
      <div class="actions" style="align-self:flex-start;padding-top:22px"><button type="submit">Guardar perfil</button></div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Resumen y recomendaciones (IA)</h2>
  <p class="hint" style="margin-bottom:10px">Gemini redacta un resumen del estado actual y recomendaciones de hábitos a partir de los datos de <?= e($person['name']) ?>
  (sin enviar su nombre). Queda como <strong>borrador</strong>: solo se ve en /salud cuando lo publicas. Vuelve a generarlo cuando cargues exámenes nuevos o cambie el perfil.
  <?php if (salud_gemini_key() === ''): ?><strong style="color:var(--bad)">Falta la clave de Gemini: pégala en «Claves de IA», arriba — sin ella no se puede generar.</strong><?php endif; ?></p>
  <?php if ($draft): $d = $draft['es']; ?>
    <div class="draft">
      <p class="pill" style="margin-bottom:6px">Borrador del <?= e(substr((string)$ai['draft_at'], 0, 16)) ?> · <?= e((string)$ai['draft_model']) ?>
        · <?= $draftIsPublished ? '<strong style="color:var(--accentd)">publicado</strong>' : ($ai['published_json'] ? 'hay otra versión publicada' : 'sin publicar') ?></p>
      <p><?= e($d['summary']) ?></p>
      <ol>
        <?php foreach ($d['recommendations'] as $rec): ?><li><strong><?= e($rec['title']) ?>.</strong> <?= e($rec['body']) ?></li><?php endforeach; ?>
      </ol>
      <?php if ($d['doctor_questions']): ?>
        <p style="margin-top:8px"><strong>Para el médico:</strong></p>
        <ul><?php foreach ($d['doctor_questions'] as $q): ?><li><?= e($q) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <details style="margin-top:8px"><summary class="pill">Ver versión en portugués</summary>
        <p style="margin-top:6px"><?= e($draft['pt']['summary']) ?></p>
        <ol><?php foreach ($draft['pt']['recommendations'] as $rec): ?><li><strong><?= e($rec['title']) ?>.</strong> <?= e($rec['body']) ?></li><?php endforeach; ?></ol>
      </details>
    </div>
  <?php else: ?><p class="hint">Todavía no hay borrador.</p><?php endif; ?>
  <div class="actions" style="margin-top:14px;justify-content:flex-start;flex-wrap:wrap">
    <form method="post" class="inline" data-busy="Generando… (hasta 1 minuto)">
      <input type="hidden" name="action" value="gen_summary"><input type="hidden" name="p" value="<?= $pid ?>"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="<?= $draft ? 'ghost' : '' ?>"><?= $draft ? 'Generar de nuevo' : 'Generar borrador' ?></button>
    </form>
    <?php if ($draft && !$draftIsPublished): ?>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="publish_summary"><input type="hidden" name="p" value="<?= $pid ?>"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button type="submit">Publicar en /salud</button>
    </form>
    <?php endif; ?>
    <?php if ($ai && $ai['published_json']): ?>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="unpublish_summary"><input type="hidden" name="p" value="<?= $pid ?>"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="danger">Retirar de /salud</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Apple Health</h2>
  <p class="hint" style="margin-bottom:10px">
    <?= $healthCount ? "$healthCount meses cargados (último: " . e((string)$lastMonth) . ")." : 'Sin datos todavía.' ?>
    Sube el <strong>export.zip</strong> de la app Salud tal cual (Salud → tu perfil → Exportar todos los datos de salud);
    el servidor calcula los promedios mensuales y puede tardar un minuto. También acepta el JSON de
    <code class="pill">tools/apple_health_to_json.py</code>.
  </p>
  <form method="post" enctype="multipart/form-data" data-busy="Procesando…">
    <input type="hidden" name="action" value="apple_import">
    <input type="hidden" name="p" value="<?= $pid ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div style="flex:2"><label for="a-file">export.zip (o JSON)</label>
        <input id="a-file" type="file" name="file" accept=".zip,application/zip,application/json,.json" required></div>
      <div><button type="submit">Importar</button></div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Importar exámenes (JSON)</h2>
  <p class="hint" style="margin-bottom:10px">Carga masiva de exámenes históricos. Formato:
  <code class="pill">{"exams":[{"date":"YYYY-MM-DD","lab":"...","results":{"hdl":55}}]}</code>.
  Las fechas que ya existen se omiten (se puede subir el mismo archivo dos veces sin duplicar).</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="import_exams">
    <input type="hidden" name="p" value="<?= $pid ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div style="flex:2"><label for="i-json">Archivo JSON</label>
        <input id="i-json" type="file" name="json" accept="application/json,.json" required></div>
      <div><button type="submit">Importar</button></div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Exámenes cargados</h2>
  <?php if (!$exams): ?><p class="hint">Todavía no hay exámenes.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Fecha</th><th>Laboratorio</th><th class="right">Valores</th><th>PDF</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($exams as $ex): ?>
      <tr>
        <td><?= e($ex['date']) ?></td>
        <td><?= e($ex['lab'] ?? '—') ?></td>
        <td class="right"><?= (int)$ex['n'] ?></td>
        <td><?php if ($ex['pdf_file']): ?><a href="?pdf=<?= (int)$ex['id'] ?>" target="_blank" rel="noopener">ver</a><?php else: ?><span class="pill">—</span><?php endif; ?></td>
        <td class="actions">
          <a href="?edit=<?= (int)$ex['id'] ?>" style="font-size:13px">editar</a>
          <form method="post" class="inline" data-confirm="¿Eliminar el examen del <?= e($ex['date']) ?>?">
            <input type="hidden" name="action" value="delete_exam">
            <input type="hidden" name="exam_id" value="<?= (int)$ex['id'] ?>">
            <input type="hidden" name="p" value="<?= $pid ?>">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="danger" style="padding:3px 10px;font-size:12px">eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Agregar persona</h2>
  <p class="hint" style="margin-bottom:10px">Un familiar adulto con su propia contraseña. Después de crearla, completa su perfil y carga sus exámenes y su Apple Health.</p>
  <form method="post">
    <input type="hidden" name="action" value="new_person">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div><label for="np-name">Nombre</label><input id="np-name" type="text" name="name" required maxlength="80"></div>
      <div><label for="np-pass">Contraseña de /salud</label><input id="np-pass" type="password" name="password" required minlength="12" autocomplete="new-password"></div>
      <div><button type="submit" class="ghost">Crear</button></div>
    </div>
  </form>
  <?php if ($pid !== 1): ?>
  <form method="post" style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px" data-confirm="¿Eliminar a <?= e($person['name']) ?> y TODOS sus datos? No se puede deshacer.">
    <input type="hidden" name="action" value="delete_person"><input type="hidden" name="p" value="<?= $pid ?>"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="row">
      <div><label for="dp-name">Eliminar a <?= e($person['name']) ?>: escribe su nombre exacto</label><input id="dp-name" type="text" name="confirm_name" autocomplete="off"></div>
      <div><button type="submit" class="danger">Eliminar persona y sus datos</button></div>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php b_layout_bottom(); ?>
