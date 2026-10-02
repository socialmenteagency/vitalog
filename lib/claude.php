<?php
// Extracción de valores desde el PDF del examen con la API de Claude.
// HTTP crudo vía cURL: en el hosting compartido no hay Composer, así que no
// se usa el SDK oficial de PHP. Modelo: claude-opus-5.

declare(strict_types=1);

const SALUD_CLAUDE_MODEL = 'claude-opus-5';
const SALUD_CLAUDE_MAX_PDF_MB = 30;

// Devuelve ['ok' => true, 'data' => [...]] o ['ok' => false, 'error' => '...'].
// data = ['exam_date' => 'YYYY-MM-DD'|null, 'lab' => string|null,
//         'results' => [['code' => string|null, 'name_raw' => string,
//                        'value' => float, 'unit_raw' => string], ...]]
function salud_extract_pdf(string $pdfPath): array {
    if (ANTHROPIC_API_KEY === '') {
        return ['ok' => false, 'error' => 'Falta ANTHROPIC_API_KEY en data/config.php.'];
    }
    $size = filesize($pdfPath);
    if ($size === false || $size > SALUD_CLAUDE_MAX_PDF_MB * 1024 * 1024) {
        return ['ok' => false, 'error' => 'El PDF supera los ' . SALUD_CLAUDE_MAX_PDF_MB . ' MB.'];
    }

    $catalog = [];
    foreach (SALUD_ANALYTES as $code => [$cat, $nameEs, $namePt, $unit]) {
        $catalog[] = "$code: $nameEs ($unit)";
    }
    $catalogText = implode("\n", $catalog);

    $prompt = <<<PROMPT
Este PDF es un examen de laboratorio clínico (sangre y/u orina) de un paciente.
Extrae todos los valores numéricos de analitos que encuentres.

Catálogo de analitos conocidos (código: nombre (unidad esperada)):
$catalogText

Responde SOLO con un objeto JSON válido, sin texto adicional ni bloques de código:
{
 "exam_date": "YYYY-MM-DD o null si no aparece la fecha de extracción/toma de muestra",
 "lab": "nombre del laboratorio o null",
 "results": [
   {"code": "código del catálogo si el analito corresponde a uno, si no null",
    "name_raw": "nombre tal como aparece en el PDF",
    "value": número (convertido a la unidad esperada del catálogo cuando el code no es null; ej. si el catálogo espera mil/µL y el PDF reporta /µL, divide entre 1000),
    "unit_raw": "unidad tal como aparece en el PDF"}
 ]
}

Reglas:
- Un resultado por analito. Si el mismo analito aparece dos veces, usa el más reciente.
- No inventes valores: solo lo que está impreso en el PDF.
- Valores no numéricos (ej. "negativo", "amarillo") se omiten.
- "value" siempre número JSON, con punto decimal.
PROMPT;

    $body = [
        'model' => SALUD_CLAUDE_MODEL,
        'max_tokens' => 16000,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'document', 'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode((string)file_get_contents($pdfPath)),
                ]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'error' => "Error de red hacia la API: $err"];
    $resp = json_decode((string)$raw, true);
    if ($status !== 200) {
        $msg = $resp['error']['message'] ?? "HTTP $status";
        return ['ok' => false, 'error' => "La API respondió con error: $msg"];
    }
    if (($resp['stop_reason'] ?? '') === 'refusal') {
        return ['ok' => false, 'error' => 'El modelo declinó procesar este PDF.'];
    }

    $text = '';
    foreach ($resp['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    // tolerar un eventual bloque ```json ... ```
    if (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
        return ['ok' => false, 'error' => 'No se pudo interpretar la respuesta de extracción. Intenta de nuevo o carga los valores a mano.'];
    }

    $clean = ['exam_date' => null, 'lab' => null, 'results' => []];
    $d = $data['exam_date'] ?? null;
    if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $clean['exam_date'] = $d;
    if (is_string($data['lab'] ?? null) && $data['lab'] !== '') $clean['lab'] = mb_substr($data['lab'], 0, 120);
    foreach ($data['results'] as $r) {
        if (!is_array($r) || !is_numeric($r['value'] ?? null)) continue;
        $code = $r['code'] ?? null;
        if (!is_string($code) || !isset(SALUD_ANALYTES[$code])) $code = null;
        $clean['results'][] = [
            'code' => $code,
            'name_raw' => mb_substr((string)($r['name_raw'] ?? ''), 0, 120),
            'value' => (float)$r['value'],
            'unit_raw' => mb_substr((string)($r['unit_raw'] ?? ''), 0, 40),
        ];
    }
    if (!$clean['results']) {
        return ['ok' => false, 'error' => 'No se encontraron valores numéricos en el PDF.'];
    }
    return ['ok' => true, 'data' => $clean];
}
