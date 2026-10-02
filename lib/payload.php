<?php
// Arma el payload JSON que consume la visualización de /salud
// para una persona.

declare(strict_types=1);

// Perfil que viaja a la página: sexo, edad, estatura y lugar (no la fecha de nacimiento).
function salud_profile(array $person): array {
    $age = null;
    if (!empty($person['birth_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$person['birth_date'])) {
        $age = (new DateTime($person['birth_date']))->diff(new DateTime('today'))->y;
    }
    $sex = $person['sex'] ?? null;
    return [
        'sex' => in_array($sex, ['male', 'female'], true) ? $sex : null,
        'age' => $age,
        'height_cm' => isset($person['height_cm']) && $person['height_cm'] !== null ? (float)$person['height_cm'] : null,
        'location' => $person['location'] ?: null,
    ];
}

// Resumen/recomendaciones publicados de la persona (o null)
function salud_published_ai(PDO $pdo, int $personId): ?array {
    $st = $pdo->prepare('SELECT published_json, published_at FROM summaries WHERE person_id = ?');
    $st->execute([$personId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['published_json']) return null;
    $data = json_decode($row['published_json'], true);
    if (!is_array($data)) return null;
    return ['text' => $data, 'at' => $row['published_at']];
}

function salud_build_payload(PDO $pdo, int $personId = 1): array {
    $person = salud_person($pdo, $personId) ?? ['name' => '', 'sex' => null];
    $sex = in_array($person['sex'] ?? null, ['male', 'female'], true) ? $person['sex'] : null;

    $st = $pdo->prepare('SELECT id, date, lab FROM exams WHERE person_id = ? ORDER BY date ASC');
    $st->execute([$personId]);
    $exams = $st->fetchAll(PDO::FETCH_ASSOC);
    $examIds = array_column($exams, 'id');

    $valuesByAnalyte = [];
    if ($examIds) {
        $st = $pdo->prepare('SELECT r.exam_id, r.analyte_code, r.value FROM results r
                             JOIN exams e ON e.id = r.exam_id WHERE e.person_id = ?');
        $st->execute([$personId]);
        $byExam = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byExam[(int)$r['exam_id']][$r['analyte_code']] = (float)$r['value'];
        foreach (SALUD_ANALYTES as $code => $_) {
            $series = [];
            foreach ($examIds as $id) $series[] = $byExam[(int)$id][$code] ?? null;
            if (array_filter($series, fn($v) => $v !== null)) $valuesByAnalyte[$code] = $series;
        }
    }

    $categories = [];
    foreach (SALUD_CATEGORIES as $catCode => $catNames) {
        $analytes = [];
        foreach (SALUD_ANALYTES as $code => [$cat, $nameEs, $namePt, $unit, , , $dec]) {
            if ($cat !== $catCode || !isset($valuesByAnalyte[$code])) continue;
            [$low, $high] = salud_range_for($code, $sex);
            $analytes[] = [
                'code' => $code, 'name_es' => $nameEs, 'name_pt' => $namePt,
                'unit' => $unit, 'low' => $low, 'high' => $high, 'dec' => $dec,
                'values' => $valuesByAnalyte[$code],
                'flags' => array_map(fn($v) => salud_flag_for($code, $v, $sex), $valuesByAnalyte[$code]),
            ];
        }
        if ($analytes) {
            $categories[] = ['code' => $catCode, 'name_es' => $catNames['es'], 'name_pt' => $catNames['pt'], 'analytes' => $analytes];
        }
    }

    $health = [];
    $st = $pdo->prepare('SELECT month, metric, value FROM health_monthly WHERE person_id = ? ORDER BY month ASC');
    $st->execute([$personId]);
    $months = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $months[$r['month']][$r['metric']] = (float)$r['value'];
    ksort($months);
    foreach (SALUD_HEALTH_METRICS as $metric => $metaM) {
        $series = [];
        foreach ($months as $month => $vals) $series[] = ['month' => $month, 'value' => $vals[$metric] ?? null];
        if (array_filter($series, fn($p) => $p['value'] !== null)) {
            $health[] = [
                'metric' => $metric,
                'name_es' => $metaM['es'], 'name_pt' => $metaM['pt'],
                'unit_es' => $metaM['unit_es'], 'unit_pt' => $metaM['unit_pt'],
                'dec' => $metaM['decimals'],
                'points' => $series,
            ];
        }
    }

    return [
        'patient' => ['name' => $person['name'] ?? ''],
        'profile' => salud_profile($person),
        'ai' => salud_published_ai($pdo, $personId),
        'generated_at' => date('c'),
        'is_dummy' => $personId === 1 && salud_is_dummy($pdo),
        'exams' => array_map(fn($e) => ['id' => (int)$e['id'], 'date' => $e['date'], 'lab' => $e['lab']], $exams),
        'categories' => $categories,
        'health' => $health,
    ];
}
