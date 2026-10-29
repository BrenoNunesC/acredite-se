<?php
/**
 * Funções da biblioteca de apresentações usadas por library.php e share.php.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'presentation.php') {
    http_response_code(404);
    exit;
}

const MAX_FIELDS     = 20;
const MAX_CATEGORIES = 100;
const MAX_FILTERS    = 10;
const MAX_PRES_BYTES = 512 * 1024;

/** Compatibilidade com chamadas antigas. */
function ensureLibraryColumns(): void {
    db(); // o banco já é atualizado na conexão (schema.php)
}

/** Valida e normaliza os dados da apresentação enviados pelo navegador. */
function cleanPresentation($p): ?array {
    if (!is_array($p) || !is_array($p['fields'] ?? null)) return null;
    $fields = [];
    foreach (array_slice($p['fields'], 0, MAX_FIELDS) as $f) {
        if (!is_array($f) || !is_array($f['counts'] ?? null)) continue;
        $color = (string)($f['color'] ?? '');
        $counts = [];
        foreach (array_slice($f['counts'], 0, MAX_CATEGORIES) as $c) {
            if (!is_array($c) || count($c) < 2) continue;
            $counts[] = [mb_substr(trim((string)$c[0]), 0, 200), max(0, (int)$c[1])];
        }
        $field = [
            'key'    => mb_substr(trim((string)($f['key'] ?? '')), 0, 60),
            'name'   => mb_substr(trim((string)($f['name'] ?? '')), 0, 120),
            'color'  => preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : '#7C3AED',
            'cats'   => max(count($counts), (int)($f['cats'] ?? 0)),
            'counts' => $counts,
        ];
        $g = $f['goal'] ?? null;
        if (is_array($g) && trim((string)($g['cat'] ?? '')) !== '') {
            $field['goal'] = [
                'cat' => mb_substr(trim((string)$g['cat']), 0, 200),
                'op'  => ($g['op'] ?? '') === 'max' ? 'max' : 'min',
                'val' => round(min(100, max(0, (float)($g['val'] ?? 0))), 1),
            ];
        }
        $fields[] = $field;
    }
    $filters = [];
    foreach (array_slice(is_array($p['filters'] ?? null) ? $p['filters'] : [], 0, MAX_FILTERS) as $f) {
        if (!is_array($f)) continue;
        $filters[] = [
            'field' => mb_substr(trim((string)($f['field'] ?? '')), 0, 120),
            'value' => mb_substr(trim((string)($f['value'] ?? '')), 0, 200),
        ];
    }
    $out = [
        'model'   => mb_substr(trim((string)($p['model'] ?? '')), 0, 120) ?: 'Modelo',
        'total'   => max(0, (int)($p['total'] ?? 0)),
        'fields'  => $fields,
        'filters' => $filters,
    ];
    // Planilha de origem (agrupa versões na biblioteca)
    $src = cleanText($p['source'] ?? '', 255);
    if ($src !== '') $out['source'] = $src;
    // Resumo em texto (escrito pela IA ou pelo navegador)
    $sum = cleanText(preg_replace('/\s+/u', ' ', (string)($p['summary'] ?? '')), 700);
    if ($sum !== '') $out['summary'] = $sum;
    if ($tl = cleanTimeline($p['timeline'] ?? null)) $out['timeline'] = $tl;
    if ($cr = cleanCross($p['cross'] ?? null)) $out['cross'] = $cr;
    return $out;
}

function cleanText($v, int $max): string {
    return mb_substr(trim((string)$v), 0, $max);
}

/** Evolução no tempo: períodos (rótulo + total) e até 5 séries em % (null = período sem registros). */
function cleanTimeline($t): ?array {
    if (!is_array($t) || !is_array($t['periods'] ?? null) || !$t['periods']) return null;
    $periods = [];
    foreach (array_slice($t['periods'], 0, 120) as $q) {
        if (!is_array($q)) continue;
        $periods[] = ['label' => cleanText($q['label'] ?? '', 20), 'total' => max(0, (int)($q['total'] ?? 0))];
    }
    $n = count($periods);
    $series = [];
    foreach (array_slice(is_array($t['series'] ?? null) ? $t['series'] : [], 0, 5) as $s) {
        if (!is_array($s) || !is_array($s['values'] ?? null)) continue;
        $color = (string)($s['color'] ?? '');
        $values = [];
        for ($i = 0; $i < $n; $i++) {
            $v = $s['values'][$i] ?? null;
            $values[] = $v === null ? null : round(min(100, max(0, (float)$v)), 1);
        }
        $series[] = [
            'name'   => cleanText($s['name'] ?? '', 200),
            'color'  => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#7C3AED',
            'values' => $values,
        ];
    }
    $gran = (string)($t['gran'] ?? '');
    return [
        'title'   => cleanText($t['title'] ?? '', 120),
        'gran'    => in_array($gran, ['month', 'quarter', 'year'], true) ? $gran : 'month',
        'by'      => cleanText($t['by'] ?? '', 120),
        'periods' => $periods,
        'series'  => $series,
    ];
}

/** Cruzamento: até 12 linhas × 8 colunas de contagens. */
function cleanCross($c): ?array {
    if (!is_array($c) || !is_array($c['rows'] ?? null) || !is_array($c['cols'] ?? null) || !is_array($c['m'] ?? null)) return null;
    $rows = array_map(fn($r) => cleanText($r, 200), array_slice($c['rows'], 0, 12));
    $cols = array_map(fn($r) => cleanText($r, 200), array_slice($c['cols'], 0, 8));
    if (!$rows || !$cols) return null;
    $m = [];
    $totals = [];
    foreach ($rows as $i => $_) {
        $line = is_array($c['m'][$i] ?? null) ? $c['m'][$i] : [];
        $m[] = array_map(fn($j) => max(0, (int)($line[$j] ?? 0)), array_keys($cols));
        $totals[] = max(0, (int)($c['rowTotals'][$i] ?? 0));
    }
    return [
        'a'         => cleanText($c['a'] ?? '', 120),
        'b'         => cleanText($c['b'] ?? '', 120),
        'mode'      => ($c['mode'] ?? '') === 'count' ? 'count' : 'row',
        'rows'      => $rows,
        'cols'      => $cols,
        'm'         => $m,
        'rowTotals' => $totals,
        'moreRows'  => max(0, (int)($c['moreRows'] ?? 0)),
        'moreCols'  => max(0, (int)($c['moreCols'] ?? 0)),
    ];
}
