<?php
// Apple Health en el servidor: lee el export.zip tal como lo entrega la app Salud
// y devuelve promedios mensuales (mismas reglas que tools/apple_health_to_json.py).
//
// El hosting no trae la extensión zip de PHP, así que se lee el directorio central
// del zip a mano y export.xml (cientos de MB descomprimido) se descomprime en
// streaming con zlib, sin cargarlo entero en memoria. Cada <Record> de Apple va en
// su propia línea, así que se procesa por bloques de líneas completas.

declare(strict_types=1);

const SALUD_APPLE_TYPES = ['BodyMass', 'AppleExerciseTime', 'RestingHeartRate', 'StepCount'];

// Ubica export.xml dentro del zip: [offset de los datos, tamaño comprimido, método].
function salud_apple_zip_find(string $path): array {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('No se pudo abrir el archivo subido.');
    try {
        $size = (int)filesize($path);
        $tailLen = min($size, 65557);
        fseek($fh, $size - $tailLen);
        $tail = (string)fread($fh, $tailLen);
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false) throw new RuntimeException('El archivo no es un zip válido.');
        $end = unpack('vdisk/vcddisk/ventriesdisk/ventries/Vcdsize/Vcdoff', substr($tail, $eocd + 4, 16));
        if ($end['cdoff'] === 0xFFFFFFFF || $end['entries'] === 0xFFFF) {
            throw new RuntimeException('Zip64 no soportado: genera un export.zip más chico o usa tools/apple_health_to_json.py.');
        }

        fseek($fh, $end['cdoff']);
        $cd = (string)fread($fh, $end['cdsize']);
        $pos = 0;
        $len = strlen($cd);
        while ($pos + 46 <= $len && substr($cd, $pos, 4) === "PK\x01\x02") {
            $e = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff',
                        substr($cd, $pos, 46));
            $name = substr($cd, $pos + 46, $e['nlen']);
            if (basename($name) === 'export.xml') {
                fseek($fh, $e['off']);
                $lh = unpack('Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', (string)fread($fh, 30));
                return [$e['off'] + 30 + $lh['nlen'] + $lh['velen'], $e['csize'], $e['method']];
            }
            $pos += 46 + $e['nlen'] + $e['velen'] + $e['clen'];
        }
        throw new RuntimeException('No se encontró export.xml dentro del zip.');
    } finally {
        fclose($fh);
    }
}

// Acumula los <Record> de un bloque de líneas completas en $acc.
function salud_apple_scan(string $block, array &$acc): void {
    $re = '/<Record type="HKQuantityTypeIdentifier(' . implode('|', SALUD_APPLE_TYPES) . ')"[^>]*>/';
    if (!preg_match_all($re, $block, $m, PREG_SET_ORDER)) return;
    foreach ($m as [$tag, $type]) {
        if (!preg_match('/ startDate="(\d{4}-\d{2})-(\d{2})/', $tag, $d)) continue;
        if (!preg_match('/ value="([^"]*)"/', $tag, $v) || !is_numeric($v[1])) continue;
        $value = (float)$v[1];
        $month = $d[1];
        $day = $d[1] . '-' . $d[2];
        if ($type === 'AppleExerciseTime') {
            $acc['ex'][$day] = ($acc['ex'][$day] ?? 0.0) + $value;
        } elseif ($type === 'StepCount') {
            $src = preg_match('/ sourceName="([^"]*)"/', $tag, $s) ? $s[1] : '?';
            $acc['steps'][$day][$src] = ($acc['steps'][$day][$src] ?? 0.0) + $value;
        } else {
            $metric = $type === 'BodyMass' ? 'weight' : 'resting_hr';
            if ($type === 'BodyMass' && str_contains($tag, ' unit="lb"')) $value *= 0.453592;
            $k = $metric . '|' . $month;
            $acc['sum'][$k] = ($acc['sum'][$k] ?? 0.0) + $value;
            $acc['cnt'][$k] = ($acc['cnt'][$k] ?? 0) + 1;
        }
    }
}

// export.zip → lista [{month:'YYYY-MM', weight?, exercise?, resting_hr?, steps?}, ...]
function salud_apple_parse_zip(string $path): array {
    [$start, $csize, $method] = salud_apple_zip_find($path);
    if ($method !== 8 && $method !== 0) throw new RuntimeException('Método de compresión del zip no soportado.');
    if ($method === 8 && !function_exists('inflate_init')) throw new RuntimeException('El servidor no tiene zlib.');

    $acc = ['sum' => [], 'cnt' => [], 'ex' => [], 'steps' => []];
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('No se pudo abrir el archivo subido.');
    try {
        fseek($fh, $start);
        $ctx = $method === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
        $left = $csize;
        $carry = '';
        while ($left > 0) {
            $chunk = fread($fh, min(262144, $left));
            if ($chunk === false || $chunk === '') break;
            $left -= strlen($chunk);
            $data = $ctx ? inflate_add($ctx, $chunk) : $chunk;
            if ($data === false) throw new RuntimeException('El zip está dañado (no se pudo descomprimir).');
            $buf = $carry . $data;
            $cut = strrpos($buf, "\n");
            if ($cut === false) { $carry = $buf; continue; }
            $carry = substr($buf, $cut + 1);
            salud_apple_scan(substr($buf, 0, $cut), $acc);
        }
        if ($carry !== '') salud_apple_scan($carry, $acc);
    } finally {
        fclose($fh);
    }

    // weight / resting_hr: promedio de lecturas del mes
    $months = [];
    foreach ($acc['sum'] as $k => $total) {
        [$metric, $month] = explode('|', $k);
        $avg = $total / $acc['cnt'][$k];
        $months[$month][$metric] = $metric === 'resting_hr' ? (int)round($avg) : round($avg, 1);
    }
    // exercise: minutos por día → promedio diario del mes
    $byMonth = [];
    foreach ($acc['ex'] as $day => $minutes) $byMonth[substr($day, 0, 7)][] = $minutes;
    foreach ($byMonth as $month => $days) $months[$month]['exercise'] = (int)round(array_sum($days) / count($days));
    // steps: por día, la fuente que más registró (iPhone y Watch duplican pasos)
    $byMonth = [];
    foreach ($acc['steps'] as $day => $sources) $byMonth[substr($day, 0, 7)][] = max($sources);
    foreach ($byMonth as $month => $days) $months[$month]['steps'] = (int)round(array_sum($days) / count($days));

    ksort($months);
    $out = [];
    foreach ($months as $month => $row) $out[] = ['month' => $month] + $row;
    return $out;
}

// Archivo subido (export.zip o el JSON de tools/apple_health_to_json.py) → lista de meses.
function salud_apple_months_from_upload(string $tmpPath): array {
    $magic = (string)file_get_contents($tmpPath, false, null, 0, 2);
    if ($magic === 'PK') return salud_apple_parse_zip($tmpPath);
    $data = json_decode((string)file_get_contents($tmpPath), true);
    $months = is_array($data) ? ($data['months'] ?? null) : null;
    if (!is_array($months)) {
        throw new RuntimeException('Sube el export.zip de Salud, o el JSON generado con tools/apple_health_to_json.py.');
    }
    return $months;
}

// Guarda los meses de una persona (idempotente por persona+mes+métrica). Devuelve
// cuántos valores escribió.
function salud_apple_store(PDO $pdo, array $months, int $personId = 1): int {
    $ins = $pdo->prepare('INSERT OR REPLACE INTO health_monthly (person_id, month, metric, value) VALUES (?, ?, ?, ?)');
    $count = 0;
    $pdo->beginTransaction();
    foreach ($months as $row) {
        if (!is_array($row) || !preg_match('/^\d{4}-\d{2}$/', $row['month'] ?? '')) continue;
        foreach (array_keys(SALUD_HEALTH_METRICS) as $metric) {
            if (isset($row[$metric]) && is_numeric($row[$metric])) {
                $ins->execute([$personId, $row['month'], $metric, (float)$row[$metric]]);
                $count++;
            }
        }
    }
    $pdo->commit();
    return $count;
}
