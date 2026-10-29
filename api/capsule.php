<?php
/**
 * Aparência da Cápsula de cada pessoa (guardada na conta, vale em todos os computadores)
 *   GET  capsule.php?action=get            → {capsule: {skin, glasses, mouth, hair, hairColor, hat, mustache, mustacheColor, halo}}
 *   POST capsule.php?action=save {capsule} → {capsule}  (só valores da lista; o resto volta ao padrão)
 * As listas abaixo têm de ficar iguais às do index.html (CAPSULE_OPTIONS).
 */
require __DIR__ . '/bootstrap.php';
$user = requireUser();
$uid  = (int)$user['id'];

const CAP_OPTIONS = [
    'skin'          => ['classic', 'menta', 'sol', 'rosa', 'noite', 'lima', 'branco', 'ceu', 'grafite'],
    'glasses'       => ['none', 'redondos', 'quadrados', 'sol'],
    'mouth'         => ['padrao', 'sorriso', 'surpresa', 'neutro'],
    'hair'          => ['none', 'curto', 'topete', 'cachinhos', 'coque', 'longo', 'moicano', 'bob'],
    'hairColor'     => ['#5B3A1A', '#111827', '#F59E0B', '#DC2626', '#EC4899', '#3B82F6', '#9CA3AF'],
    'hat'           => ['none', 'cartola', 'bone', 'coroa', 'festa', 'capacete', 'palha', 'gorro'],
    'mustache'      => ['none', 'simples', 'fino', 'curvado', 'guidao', 'grosso', 'barba', 'cavanhaque'],
    'mustacheColor' => ['#111827', '#78350F', '#F59E0B', '#9CA3AF', '#DC2626', '#7C3AED', '#3B82F6'],
    'halo'          => ['#FFFFFF', '#FBBF24', '#67E8F9', 'nenhum'],
];
const CAP_DEFAULT = [
    'skin' => 'classic', 'glasses' => 'none', 'mouth' => 'padrao', 'hair' => 'none', 'hairColor' => '#5B3A1A',
    'hat' => 'none', 'mustache' => 'none', 'mustacheColor' => '#111827', 'halo' => '#FFFFFF',
];

/** Só fica o que está na lista; o resto volta ao padrão. */
function capClean($in): array {
    $in = is_array($in) ? $in : [];
    $out = [];
    foreach (CAP_OPTIONS as $key => $allowed) {
        $v = $in[$key] ?? null;
        $out[$key] = in_array($v, $allowed, true) ? $v : CAP_DEFAULT[$key];
    }
    return $out;
}

switch (action()) {

case 'get':
    $st = db()->prepare('SELECT capsule FROM user_prefs WHERE user_id = ?');
    $st->execute([$uid]);
    $raw = $st->fetchColumn();
    $saved = $raw ? (json_decode((string)$raw, true) ?: []) : [];
    respond(['capsule' => capClean($saved)]);

case 'save':
    requireMethod('POST');
    $cfg = capClean(body()['capsule'] ?? []);
    // Atualiza só a Cápsula (REPLACE apagaria as outras preferências da linha)
    db()->prepare('INSERT INTO user_prefs (user_id, capsule, updated_at) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE capsule = VALUES(capsule), updated_at = VALUES(updated_at)')
        ->execute([$uid, json_encode($cfg, JSON_UNESCAPED_UNICODE), now()]);
    respond(['capsule' => $cfg]);

default:
    fail('Ação desconhecida.', 404);
}
