<?php
// Rangos de referencia según el sexo de la persona.
//
// El catálogo (analytes.php) trae el rango estándar de adulto masculino. Para una
// mujer adulta se reemplazan los analitos cuyo rango cambia con el sexo; [null, null]
// = sin rango (no se marca nada en vez de marcar mal; ej. PSA o testosterona libre,
// cuyos rangos masculinos darían falsos rojos). Sin sexo definido se usa el catálogo.
// Valores de uso general en adultos; no consideran embarazo, menopausia ni la edad
// (eso sigue pendiente), y no reemplazan el rango del laboratorio.

declare(strict_types=1);

const SALUD_FEMALE_RANGES = [
    'gr'            => [4.1, 5.1],
    'hb'            => [12.0, 15.5],
    'hto'           => [36, 46],
    'hdl'           => [50, null],
    'creatinina'    => [0.5, 1.1],
    'ac_urico'      => [2.4, 6.0],
    'ferritina'     => [13, 150],
    'testo_total'   => [15, 70],
    'testo_libre'   => [null, null],
    'testo_biodisp' => [null, null],
    'shbg'          => [null, null],
    'psa'           => [null, null],
];

// [low, high] de un analito para ese sexo ('male' | 'female' | null)
function salud_range_for(string $code, ?string $sex): array {
    if ($sex === 'female' && array_key_exists($code, SALUD_FEMALE_RANGES)) return SALUD_FEMALE_RANGES[$code];
    [, , , , $low, $high] = SALUD_ANALYTES[$code];
    return [$low, $high];
}

// null = en rango; 'high' / 'low' = fuera de rango (con el rango del sexo)
function salud_flag_for(string $code, ?float $value, ?string $sex): ?string {
    if ($value === null || !isset(SALUD_ANALYTES[$code])) return null;
    [$low, $high] = salud_range_for($code, $sex);
    if ($low !== null && $value < $low) return 'low';
    if ($high !== null && $value > $high) return 'high';
    return null;
}
