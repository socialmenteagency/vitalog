<?php
// Catálogo canónico de analitos: categoría, nombres ES/PT-BR, unidad,
// rango de referencia estándar (adulto masculino) y decimales para mostrar.
// low/high en null = sin límite de ese lado (ej. HDL solo tiene mínimo).
// Los rangos son estándar fijos (decisión 27-ago-2026), no los del PDF de cada lab.
// Ampliado el 28-ago-2026 con los analitos de los 10 exámenes reales 2021–2026.

const SALUD_CATEGORIES = [
    'hemo_roja'   => ['es' => 'Hemograma — serie roja',      'pt' => 'Hemograma — série vermelha'],
    'hemo_blanca' => ['es' => 'Hemograma — serie blanca',    'pt' => 'Hemograma — série branca'],
    'hemo_plaq'   => ['es' => 'Plaquetas',                   'pt' => 'Plaquetas'],
    'inflamacion' => ['es' => 'Inflamación y coagulación',   'pt' => 'Inflamação e coagulação'],
    'metabolismo' => ['es' => 'Metabolismo',                 'pt' => 'Metabolismo'],
    'lipidos'     => ['es' => 'Perfil lipídico',             'pt' => 'Perfil lipídico'],
    'renal'       => ['es' => 'Función renal',               'pt' => 'Função renal'],
    'ionograma'   => ['es' => 'Ionograma y minerales',       'pt' => 'Ionograma e minerais'],
    'hepatico'    => ['es' => 'Función hepática',            'pt' => 'Função hepática'],
    'proteinas'   => ['es' => 'Proteínas',                   'pt' => 'Proteínas'],
    'tiroides'    => ['es' => 'Tiroides',                    'pt' => 'Tireoide'],
    'hormonas'    => ['es' => 'Hormonas',                    'pt' => 'Hormônios'],
    'vitaminas'   => ['es' => 'Vitaminas y hierro',          'pt' => 'Vitaminas e ferro'],
    'prostata'    => ['es' => 'Próstata',                    'pt' => 'Próstata'],
];

const SALUD_ANALYTES = [
    // code => [cat, name_es, name_pt, unit, low, high, decimals]

    // Serie roja
    'gr'         => ['hemo_roja', 'Glóbulos rojos',          'Hemácias',                 'mill/µL', 4.5,  5.9,  2],
    'hb'         => ['hemo_roja', 'Hemoglobina',             'Hemoglobina',              'g/dL',    13.5, 17.5, 1],
    'hto'        => ['hemo_roja', 'Hematocrito',             'Hematócrito',              '%',       41,   53,   1],
    'vcm'        => ['hemo_roja', 'VCM',                     'VCM',                      'fL',      80,   100,  1],
    'hcm'        => ['hemo_roja', 'HCM',                     'HCM',                      'pg',      27,   33,   1],
    'chcm'       => ['hemo_roja', 'CHCM',                    'CHCM',                     'g/dL',    30,   37,   1],
    'rdw'        => ['hemo_roja', 'RDW',                     'RDW',                      '%',       11,   15,   1],

    // Serie blanca
    'gb'         => ['hemo_blanca', 'Glóbulos blancos',      'Leucócitos',               'mil/µL',  4.0,  11.0, 1],
    'neut_pct'   => ['hemo_blanca', 'Neutrófilos (%)',       'Neutrófilos (%)',          '%',       40,   75,   1],
    'neut_abs'   => ['hemo_blanca', 'Neutrófilos absolutos', 'Neutrófilos absolutos',    'mil/µL',  1.5,  8.5,  1],
    'linf_pct'   => ['hemo_blanca', 'Linfocitos (%)',        'Linfócitos (%)',           '%',       18,   45,   1],
    'linf_abs'   => ['hemo_blanca', 'Linfocitos absolutos',  'Linfócitos absolutos',     'mil/µL',  1.2,  4.8,  1],
    'mono_abs'   => ['hemo_blanca', 'Monocitos absolutos',   'Monócitos absolutos',      'mil/µL',  0.2,  1.0,  1],
    'eos_abs'    => ['hemo_blanca', 'Eosinófilos absolutos', 'Eosinófilos absolutos',    'mil/µL',  null, 0.5,  1],
    'baso_abs'   => ['hemo_blanca', 'Basófilos absolutos',   'Basófilos absolutos',      'mil/µL',  null, 0.2,  1],

    // Plaquetas
    'plaq'       => ['hemo_plaq', 'Plaquetas',               'Plaquetas',                'mil/µL',  150,  450,  0],
    'vpm'        => ['hemo_plaq', 'Volumen plaquetario medio','Volume plaquetário médio','fL',      5.0,  20.0, 1],

    // Inflamación y coagulación
    'vhs'        => ['inflamacion', 'VHS (eritrosedimentación)', 'VHS (hemossedimentação)', 'mm/h',      null, 20,  0],
    'pcr'        => ['inflamacion', 'Proteína C reactiva',   'Proteína C reativa',       'mg/L',      null, 5.0, 1],
    'ddimero'    => ['inflamacion', 'Dímero-D',              'Dímero-D',                 'ng/mL FEU', null, 600, 0],

    // Metabolismo
    'glucemia'   => ['metabolismo', 'Glucemia en ayunas',    'Glicemia em jejum',        'mg/dL',   70,   100,  0],
    'hba1c'      => ['metabolismo', 'Hemoglobina glicosilada','Hemoglobina glicada',     '%',       null, 5.7,  1],
    'insulina'   => ['metabolismo', 'Insulina basal',        'Insulina basal',           'µUI/mL',  2.6,  24.9, 1],
    // PTOG con protocolos distintos = analitos separados (no comparables entre sí)
    'ptog_120'   => ['metabolismo', 'PTOG — glucemia 120 min','TOTG — glicemia 120 min', 'mg/dL',   70,   140,  0],
    'ptog_60'    => ['metabolismo', 'PTOG — glucemia 60 min', 'TOTG — glicemia 60 min',  'mg/dL',   null, 155,  0],
    'glucosuria' => ['metabolismo', 'Glucosuria',            'Glicosúria',               'g/L',     null, 0,    2],

    // Perfil lipídico
    'col_total'  => ['lipidos', 'Colesterol total',          'Colesterol total',         'mg/dL',   null, 200,  0],
    'hdl'        => ['lipidos', 'Colesterol HDL',            'Colesterol HDL',           'mg/dL',   40,   null, 0],
    'ldl'        => ['lipidos', 'Colesterol LDL',            'Colesterol LDL',           'mg/dL',   null, 130,  0],
    'no_hdl'     => ['lipidos', 'Colesterol no-HDL',         'Colesterol não-HDL',       'mg/dL',   null, 160,  0],
    'vldl'       => ['lipidos', 'Colesterol VLDL',           'Colesterol VLDL',          'mg/dL',   5,    30,   0],
    'tg'         => ['lipidos', 'Triglicéridos',             'Triglicerídeos',           'mg/dL',   null, 150,  0],
    'indice_col' => ['lipidos', 'Índice col. total/HDL',     'Índice CT/HDL',            '',        null, 4.5,  1],

    // Función renal
    'creatinina' => ['renal', 'Creatinina',                  'Creatinina',               'mg/dL',   0.7,  1.3,  2],
    'egfr'       => ['renal', 'Filtrado glomerular',         'Filtração glomerular',     'mL/min/1.73m²', 60, null, 0],
    'urea'       => ['renal', 'Urea',                        'Ureia',                    'mg/dL',   15,   50,   0],
    'ac_urico'   => ['renal', 'Ácido úrico',                 'Ácido úrico',              'mg/dL',   3.5,  7.2,  1],

    // Ionograma y minerales
    'na'         => ['ionograma', 'Sodio',                   'Sódio',                    'mEq/L',   134,  145,  0],
    'k'          => ['ionograma', 'Potasio',                 'Potássio',                 'mEq/L',   3.5,  5.4,  1],
    'cl'         => ['ionograma', 'Cloro',                   'Cloro',                    'mEq/L',   96,   108,  0],
    // Magnesio sin rango: la única medición (2024) usó método Mann & Yoe con rango
    // propio 1,0–3,3; los rangos habituales (~1,6–2,6) marcarían falso fuera de rango.
    'magnesio'   => ['ionograma', 'Magnesio',                'Magnésio',                 'mg/dL',   null, null, 2],

    // Función hepática
    'alt'        => ['hepatico', 'ALT (GPT)',                'ALT (TGP)',                'U/L',     7,    56,   0],
    'ast'        => ['hepatico', 'AST (GOT)',                'AST (TGO)',                'U/L',     5,    40,   0],
    'ggt'        => ['hepatico', 'GGT',                      'Gama GT',                  'U/L',     8,    61,   0],
    'fal'        => ['hepatico', 'Fosfatasa alcalina',       'Fosfatase alcalina',       'U/L',     44,   147,  0],
    'bili_total' => ['hepatico', 'Bilirrubina total',        'Bilirrubina total',        'mg/dL',   0.1,  1.2,  2],
    'bili_dir'   => ['hepatico', 'Bilirrubina directa',      'Bilirrubina direta',       'mg/dL',   null, 0.5,  2],
    'bili_ind'   => ['hepatico', 'Bilirrubina indirecta',    'Bilirrubina indireta',     'mg/dL',   null, 0.8,  2],
    'ldh'        => ['hepatico', 'LDH',                      'LDH (DHL)',                'U/L',     120,  246,  0],
    'cpk'        => ['hepatico', 'CPK',                      'CPK',                      'U/L',     39,   308,  0],

    // Proteínas
    'prot_tot'   => ['proteinas', 'Proteínas totales',       'Proteínas totais',         'g/dL',    6.4,  8.3,  2],
    'albumina'   => ['proteinas', 'Albúmina',                'Albumina',                 'g/dL',    3.4,  5.4,  1],
    'globulinas' => ['proteinas', 'Globulinas',              'Globulinas',               'g/dL',    1.9,  3.5,  1],

    // Tiroides
    'tsh'        => ['tiroides', 'TSH',                      'TSH',                      'µUI/mL',  0.4,  4.94, 2],
    't4l'        => ['tiroides', 'T4 libre',                 'T4 livre',                 'ng/dL',   0.8,  1.8,  2],

    // Hormonas
    'testo_total'  => ['hormonas', 'Testosterona total',        'Testosterona total',         'ng/dL',  240,   870,   0],
    'testo_libre'  => ['hormonas', 'Testosterona libre',        'Testosterona livre',         'nmol/L', 0.198, 0.619, 3],
    'testo_biodisp'=> ['hormonas', 'Testosterona biodisponible','Testosterona biodisponível', 'nmol/L', 4.36,  14.30, 2],
    'shbg'         => ['hormonas', 'SHBG',                      'SHBG',                       'nmol/L', 13.5,  71.4,  1],
    'cortisol8'    => ['hormonas', 'Cortisol (hora 8)',         'Cortisol (8h)',              'µg/dL',  3.7,   19.4,  1],

    // Vitaminas y hierro
    'vitd'       => ['vitaminas', 'Vitamina D (25-OH)',      'Vitamina D (25-OH)',       'ng/mL',   30,   100,  0],
    'b12'        => ['vitaminas', 'Vitamina B12',            'Vitamina B12',             'pg/mL',   200,  900,  0],
    'hierro'     => ['vitaminas', 'Hierro sérico',           'Ferro sérico',             'µg/dL',   60,   170,  0],
    'tibc'       => ['vitaminas', 'Capacidad de fijación (TIBC)', 'Capacidade de fixação (TIBC)', 'µg/dL', 250, 425, 0],
    'sat_transf' => ['vitaminas', 'Saturación de transferrina',   'Saturação de transferrina',    '%',     20,  50,  0],
    'ferritina'  => ['vitaminas', 'Ferritina',               'Ferritina',                'ng/mL',   30,   400,  0],

    // Próstata
    'psa'        => ['prostata', 'PSA total',                'PSA total',                'ng/mL',   null, 4.0,  2],
];

const SALUD_HEALTH_METRICS = [
    'weight'     => ['es' => 'Peso',         'pt' => 'Peso',            'unit_es' => 'kg',        'unit_pt' => 'kg',         'decimals' => 1],
    'steps'      => ['es' => 'Pasos',        'pt' => 'Passos',          'unit_es' => 'pasos/día', 'unit_pt' => 'passos/dia', 'decimals' => 0],
    'exercise'   => ['es' => 'Ejercicio',    'pt' => 'Exercício',       'unit_es' => 'min/día',   'unit_pt' => 'min/dia',    'decimals' => 0],
    'resting_hr' => ['es' => 'FC en reposo', 'pt' => 'FC em repouso',   'unit_es' => 'lpm',       'unit_pt' => 'bpm',        'decimals' => 0],
];

function salud_analyte_flag(string $code, ?float $value): ?string {
    // null = en rango; 'high' / 'low' = fuera de rango
    if ($value === null || !isset(SALUD_ANALYTES[$code])) return null;
    [, , , , $low, $high] = SALUD_ANALYTES[$code];
    if ($low !== null && $value < $low) return 'low';
    if ($high !== null && $value > $high) return 'high';
    return null;
}
