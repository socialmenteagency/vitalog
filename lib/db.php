<?php
// SQLite: apertura, esquema, migración a multiusuario y seed de datos dummy (solo
// si la base está vacía y SEED_DUMMY está activo en la config).
//
// Multiusuario (1-oct-2026): tabla people (una fila por persona) y person_id en
// exams y health_monthly. results cuelga de exams, así que hereda la persona.

declare(strict_types=1);

function salud_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(SALUD_DATA_DIR)) mkdir(SALUD_DATA_DIR, 0755, true);
    $pdo = new PDO('sqlite:' . SALUD_DATA_DIR . '/salud.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $pdo->exec("CREATE TABLE IF NOT EXISTS exams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,
        lab TEXT,
        notes TEXT,
        pdf_file TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS results (
        exam_id INTEGER NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
        analyte_code TEXT NOT NULL,
        value REAL NOT NULL,
        UNIQUE(exam_id, analyte_code)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_monthly (
        month TEXT NOT NULL,
        metric TEXT NOT NULL,
        value REAL NOT NULL,
        UNIQUE(month, metric)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)");

    salud_migrate_multiuser($pdo);
    $pdo->exec('PRAGMA foreign_keys = ON');

    salud_seed_if_empty($pdo);
    return $pdo;
}

// Crea people/summaries y, una sola vez, pasa los datos existentes a la persona 1
// (con respaldo previo de la base en data/backups/). Idempotente.
function salud_migrate_multiuser(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS people (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        sex TEXT,
        birth_date TEXT,
        height_cm REAL,
        location TEXT,
        prefs TEXT,
        allergies TEXT,
        meds TEXT,
        password_hash TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS summaries (
        person_id INTEGER PRIMARY KEY,
        draft_json TEXT,
        draft_at TEXT,
        draft_model TEXT,
        published_json TEXT,
        published_at TEXT
    )");

    $cols = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (in_array('person_id', $cols, true)) return;

    // respaldo consistente antes de tocar el esquema
    $dir = SALUD_DATA_DIR . '/backups';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . '/salud-pre-multiusuario-' . date('Ymd-His') . '.sqlite';
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($dest));
    } catch (Throwable $e) {
        if (!@copy(SALUD_DATA_DIR . '/salud.sqlite', $dest)) throw new RuntimeException('No se pudo respaldar la base antes de migrar.');
    }

    $pdo->beginTransaction();
    if ((int)$pdo->query('SELECT COUNT(*) FROM people')->fetchColumn() === 0) {
        $meta = $pdo->query("SELECT k, v FROM meta WHERE k LIKE 'profile\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $pdo->prepare('INSERT INTO people (id, name, sex, birth_date, height_cm, location, prefs, password_hash)
                       VALUES (1, ?, ?, ?, ?, ?, ?, ?)')->execute([
            defined('SALUD_PATIENT_NAME') ? SALUD_PATIENT_NAME : 'Mi perfil',
            $meta['profile_sex'] ?? null,
            $meta['profile_birth'] ?? null,
            isset($meta['profile_height_cm']) ? (float)$meta['profile_height_cm'] : null,
            null,
            null,
            password_hash(SALUD_PASSWORD, PASSWORD_DEFAULT),
        ]);
        $pdo->exec("DELETE FROM meta WHERE k LIKE 'profile\\_%' ESCAPE '\\'");
    }
    $pdo->exec('ALTER TABLE exams ADD COLUMN person_id INTEGER NOT NULL DEFAULT 1');
    $pdo->exec("CREATE TABLE health_monthly_new (
        person_id INTEGER NOT NULL DEFAULT 1,
        month TEXT NOT NULL,
        metric TEXT NOT NULL,
        value REAL NOT NULL,
        UNIQUE(person_id, month, metric)
    )");
    $pdo->exec('INSERT INTO health_monthly_new (person_id, month, metric, value)
                SELECT 1, month, metric, value FROM health_monthly');
    $pdo->exec('DROP TABLE health_monthly');
    $pdo->exec('ALTER TABLE health_monthly_new RENAME TO health_monthly');
    $pdo->commit();
}

function salud_seed_if_empty(PDO $pdo): void {
    if (!SEED_DUMMY) return;
    // si el dueño ya eliminó los datos de ejemplo, la base vacía es intencional
    $wiped = $pdo->query("SELECT v FROM meta WHERE k = 'dummy_wiped'")->fetchColumn();
    if ($wiped === '1') return;
    $count = (int)$pdo->query('SELECT COUNT(*) FROM exams')->fetchColumn();
    if ($count > 0) return;

    $seedFile = SALUD_APP_DIR . '/lib/seed/dummy-data.json';
    if (!is_file($seedFile)) return;
    $seed = json_decode(file_get_contents($seedFile), true);
    if (!$seed) return;

    $pdo->beginTransaction();
    $insExam = $pdo->prepare('INSERT INTO exams (date, lab) VALUES (?, ?)');
    $insRes  = $pdo->prepare('INSERT INTO results (exam_id, analyte_code, value) VALUES (?, ?, ?)');
    foreach ($seed['exams'] as $exam) {
        $insExam->execute([$exam['date'], $exam['lab'] ?? null]);
        $examId = (int)$pdo->lastInsertId();
        foreach ($exam['results'] as $code => $value) {
            if (isset(SALUD_ANALYTES[$code])) $insRes->execute([$examId, $code, $value]);
        }
    }
    $insHealth = $pdo->prepare('INSERT OR REPLACE INTO health_monthly (month, metric, value) VALUES (?, ?, ?)');
    foreach ($seed['health'] as $row) {
        foreach (array_keys(SALUD_HEALTH_METRICS) as $metric) {
            if (isset($row[$metric])) $insHealth->execute([$row['month'], $metric, $row[$metric]]);
        }
    }
    $pdo->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('seeded_dummy', '1')")->execute();
    $pdo->commit();
}

// Importa exámenes desde un array {exams:[{date, lab?, results:{code:valor}}]} para
// una persona. Idempotente por fecha: si esa persona ya tiene un examen con esa
// fecha, se omite. Devuelve ['imported' => n, 'values' => n, 'skipped' => [fechas]].
function salud_import_exams(PDO $pdo, array $data, int $personId = 1): array {
    $out = ['imported' => 0, 'values' => 0, 'skipped' => []];
    $list = $data['exams'] ?? null;
    if (!is_array($list)) return $out;

    $exists = $pdo->prepare('SELECT COUNT(*) FROM exams WHERE date = ? AND person_id = ?');
    $insExam = $pdo->prepare('INSERT INTO exams (date, lab, person_id) VALUES (?, ?, ?)');
    $insRes  = $pdo->prepare('INSERT OR REPLACE INTO results (exam_id, analyte_code, value) VALUES (?, ?, ?)');

    $pdo->beginTransaction();
    foreach ($list as $exam) {
        $date = $exam['date'] ?? '';
        if (!is_array($exam) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $exists->execute([$date, $personId]);
        if ((int)$exists->fetchColumn() > 0) { $out['skipped'][] = $date; continue; }
        $lab = isset($exam['lab']) && is_string($exam['lab']) ? mb_substr($exam['lab'], 0, 120) : null;
        $insExam->execute([$date, $lab, $personId]);
        $examId = (int)$pdo->lastInsertId();
        foreach ((array)($exam['results'] ?? []) as $code => $value) {
            if (isset(SALUD_ANALYTES[$code]) && is_numeric($value)) {
                $insRes->execute([$examId, $code, (float)$value]);
                $out['values']++;
            }
        }
        $out['imported']++;
    }
    $pdo->commit();
    return $out;
}

function salud_is_dummy(PDO $pdo): bool {
    $v = $pdo->query("SELECT v FROM meta WHERE k = 'seeded_dummy'")->fetchColumn();
    return $v === '1';
}

// Los datos de ejemplo siempre fueron de la persona 1
function salud_wipe_dummy(PDO $pdo): void {
    $pdo->exec('DELETE FROM results WHERE exam_id IN (SELECT id FROM exams WHERE person_id = 1)');
    $pdo->exec('DELETE FROM exams WHERE person_id = 1');
    $pdo->exec('DELETE FROM health_monthly WHERE person_id = 1');
    $pdo->exec("DELETE FROM meta WHERE k = 'seeded_dummy'");
    $pdo->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('dummy_wiped', '1')");
}

// ---------- personas ----------

function salud_people(PDO $pdo): array {
    return $pdo->query('SELECT * FROM people WHERE active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function salud_person(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM people WHERE id = ? AND active = 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// id de la persona cuya contraseña es esa (las contraseñas son únicas), o null
function salud_find_person_by_password(PDO $pdo, string $password): ?int {
    if ($password === '') return null;
    foreach (salud_people($pdo) as $p) {
        if ($p['password_hash'] && password_verify($password, $p['password_hash'])) return (int)$p['id'];
    }
    return null;
}

// Crea o actualiza una persona. $f: name, sex, birth, height_cm, location, prefs,
// allergies, meds, password (vacío = no cambiar). Devuelve ['id' => n] o ['error' => '...'].
function salud_person_save(PDO $pdo, ?int $id, array $f): array {
    $name = trim((string)($f['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) return ['error' => 'Escribe el nombre (hasta 80 caracteres).'];
    $sex = in_array($f['sex'] ?? '', ['male', 'female'], true) ? $f['sex'] : null;
    $birth = trim((string)($f['birth'] ?? ''));
    if ($birth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) return ['error' => 'Fecha de nacimiento inválida.'];
    $height = trim((string)($f['height_cm'] ?? ''));
    if ($height !== '' && (!is_numeric($height) || (float)$height < 50 || (float)$height > 250)) {
        return ['error' => 'Estatura inválida (en cm, entre 50 y 250).'];
    }
    $password = (string)($f['password'] ?? '');
    if ($password !== '') {
        if (mb_strlen($password) < 8) return ['error' => 'La contraseña debe tener al menos 8 caracteres.'];
        $other = salud_find_person_by_password($pdo, $password);
        if ($other !== null && $other !== $id) return ['error' => 'Esa contraseña ya la usa otra persona: elige una distinta.'];
    } elseif ($id === null) {
        return ['error' => 'Define una contraseña para que esa persona pueda entrar a /salud.'];
    }
    $vals = [
        $name, $sex, $birth !== '' ? $birth : null, $height !== '' ? (float)$height : null,
        mb_substr(trim((string)($f['location'] ?? '')), 0, 120) ?: null,
        mb_substr(trim((string)($f['prefs'] ?? '')), 0, 1500) ?: null,
        mb_substr(trim((string)($f['allergies'] ?? '')), 0, 300) ?: null,
        mb_substr(trim((string)($f['meds'] ?? '')), 0, 500) ?: null,
    ];
    if ($id === null) {
        $pdo->prepare('INSERT INTO people (name, sex, birth_date, height_cm, location, prefs, allergies, meds, password_hash)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($vals, [password_hash($password, PASSWORD_DEFAULT)]));
        return ['id' => (int)$pdo->lastInsertId()];
    }
    $pdo->prepare('UPDATE people SET name = ?, sex = ?, birth_date = ?, height_cm = ?, location = ?, prefs = ?, allergies = ?, meds = ? WHERE id = ?')
        ->execute(array_merge($vals, [$id]));
    if ($password !== '') {
        $pdo->prepare('UPDATE people SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }
    return ['id' => $id];
}

// Borra a la persona y todos sus datos (exámenes, resultados, vitales, resumen y PDFs).
function salud_person_delete(PDO $pdo, int $id): void {
    $pdfs = $pdo->prepare('SELECT pdf_file FROM exams WHERE person_id = ? AND pdf_file IS NOT NULL');
    $pdfs->execute([$id]);
    $files = $pdfs->fetchAll(PDO::FETCH_COLUMN);
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM results WHERE exam_id IN (SELECT id FROM exams WHERE person_id = ?)')->execute([$id]);
    $pdo->prepare('DELETE FROM exams WHERE person_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM health_monthly WHERE person_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM summaries WHERE person_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM people WHERE id = ?')->execute([$id]);
    $pdo->commit();
    foreach ($files as $f) @unlink(SALUD_DATA_DIR . '/pdfs/' . basename((string)$f));
}
