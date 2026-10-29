<?php
/**
 * Modelos de análise personalizados (por usuário)
 *   GET  models.php?action=list
 *   POST models.php?action=sync    {models:[{id,name,desc,fields:[{key,label,color}],hidden?}]}
 *        → substitui todos os modelos personalizados do usuário pela lista enviada
 *        Modelos prontos (ex.: id "breno") também podem vir aqui: com campos = versão editada,
 *        com hidden:true = excluído pelo usuário (guardado como fields_json {"hidden":true}).
 *   POST models.php?action=clear
 *   POST models.php?action=team_save   {team_id, model:{id,name,desc,fields}} → cria/atualiza modelo da equipe (editor/admin)
 *   POST models.php?action=team_delete {team_id, id}                         → exclui modelo da equipe (editor/admin)
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/teamlib.php';
$user = requireUser();
$uid  = (int)$user['id'];
$teams = userTeams($uid);

function teamModelOut(array $r, array $team): array {
    return [
        'id' => $r['client_id'], 'name' => $r['name'], 'desc' => $r['description'], 'available' => true,
        'fields' => json_decode($r['fields_json'], true) ?: [], 'team' => (int)$team['id'], 'teamName' => $team['name'],
        'canEdit' => teamCanEdit($team),
    ];
}
/** Equipe informada no pedido, onde a pessoa é editora ou administradora. */
function editableTeam(): array {
    global $teams;
    $t = $teams[(int)(body()['team_id'] ?? 0)] ?? null;
    if (!$t) fail('Equipe não encontrada.', 404);
    if (!teamCanEdit($t)) fail('Só editores e administradores da equipe podem alterar modelos da equipe.', 403);
    return $t;
}

function cleanFields($fields): array {
    if (!is_array($fields)) return [];
    $out = [];
    foreach (array_slice($fields, 0, 30) as $f) {
        if (!is_array($f)) continue;
        $key   = mb_substr(trim((string)($f['key'] ?? '')), 0, 120);
        $label = mb_substr(trim((string)($f['label'] ?? $key)), 0, 120);
        $color = (string)($f['color'] ?? '#7C3AED');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#7C3AED';
        if ($key === '') continue;
        $field = ['key' => $key, 'label' => $label, 'color' => $color];
        // Meta opcional do quadro: {cat, op: min|max, val: 0–100}
        $g = $f['goal'] ?? null;
        if (is_array($g) && trim((string)($g['cat'] ?? '')) !== '') {
            $field['goal'] = [
                'cat' => mb_substr(trim((string)$g['cat']), 0, 200),
                'op'  => ($g['op'] ?? '') === 'max' ? 'max' : 'min',
                'val' => round(min(100, max(0, (float)($g['val'] ?? 0))), 1),
            ];
        }
        $out[] = $field;
    }
    return $out;
}

switch (action()) {

case 'list':
    $st = db()->prepare('SELECT client_id, name, description, fields_json FROM models WHERE user_id = ? ORDER BY id');
    $st->execute([$uid]);
    $models = array_map(function ($r) {
        $fields = json_decode($r['fields_json'], true) ?: [];
        $hidden = isset($fields['hidden']);
        return [
            'id'        => $r['client_id'],
            'name'      => $r['name'],
            'desc'      => $r['description'],
            'available' => true,
            'hidden'    => $hidden,
            'fields'    => $hidden ? [] : $fields,
        ];
    }, $st->fetchAll());
    foreach ($teams as $team) {
        $st = db()->prepare('SELECT * FROM team_models WHERE team_id = ? ORDER BY id');
        $st->execute([$team['id']]);
        foreach ($st->fetchAll() as $r) $models[] = teamModelOut($r, $team);
    }
    respond(['models' => $models]);

case 'team_save':
    requireMethod('POST');
    $team = editableTeam();
    $m = body()['model'] ?? null;
    if (!is_array($m)) fail('Modelo inválido.');
    $cid  = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($m['id'] ?? '')), 0, 64);
    $name = mb_substr(trim((string)($m['name'] ?? '')), 0, 120);
    if (strpos($cid, 'team_') !== 0 || $name === '') fail('Modelo inválido.');
    $fields = cleanFields($m['fields'] ?? []);
    if (!$fields) fail('O modelo precisa de pelo menos um campo.');
    $args = [$name, mb_substr((string)($m['desc'] ?? ''), 0, 500), json_encode($fields, JSON_UNESCAPED_UNICODE), $uid, now()];
    $st = db()->prepare('SELECT id FROM team_models WHERE team_id = ? AND client_id = ?');
    $st->execute([$team['id'], $cid]);
    if ($st->fetchColumn()) {
        db()->prepare('UPDATE team_models SET name = ?, description = ?, fields_json = ?, updated_by = ?, updated_at = ? WHERE team_id = ? AND client_id = ?')
            ->execute([...$args, $team['id'], $cid]);
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM team_models WHERE team_id = ?');
        $st->execute([$team['id']]);
        if ((int)$st->fetchColumn() >= 100) fail('Limite de 100 modelos da equipe atingido.');
        db()->prepare('INSERT INTO team_models (name, description, fields_json, updated_by, updated_at, team_id, client_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([...$args, $team['id'], $cid]);
    }
    $st = db()->prepare('SELECT * FROM team_models WHERE team_id = ? AND client_id = ?');
    $st->execute([$team['id'], $cid]);
    logActivity($team['id'], $uid, 'model_save', $name);
    respond(['model' => teamModelOut($st->fetch(), $team)]);

case 'team_delete':
    requireMethod('POST');
    $team = editableTeam();
    $cid = (string)(body()['id'] ?? '');
    $st = db()->prepare('SELECT name FROM team_models WHERE team_id = ? AND client_id = ?');
    $st->execute([$team['id'], $cid]);
    $name = $st->fetchColumn();
    db()->prepare('DELETE FROM team_models WHERE team_id = ? AND client_id = ?')->execute([$team['id'], $cid]);
    if ($name !== false) logActivity($team['id'], $uid, 'model_delete', (string)$name);
    respond(['ok' => true]);

case 'sync':
    requireMethod('POST');
    $list = body()['models'] ?? null;
    if (!is_array($list)) fail('Lista de modelos inválida.');
    if (count($list) > 100) fail('Limite de 100 modelos atingido.');

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM models WHERE user_id = ?')->execute([$uid]);
    $ins = $pdo->prepare('INSERT INTO models (user_id, client_id, name, description, fields_json, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $seen = [];
    foreach ($list as $m) {
        if (!is_array($m)) continue;
        $cid  = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($m['id'] ?? ''));
        $name = mb_substr(trim((string)($m['name'] ?? '')), 0, 120);
        if ($cid === '' || $name === '' || isset($seen[$cid])) continue;
        $seen[$cid] = true;
        $fields = !empty($m['hidden']) ? ['hidden' => true] : cleanFields($m['fields'] ?? []);
        $ins->execute([$uid, substr($cid, 0, 64), $name,
            mb_substr((string)($m['desc'] ?? ''), 0, 500),
            json_encode($fields, JSON_UNESCAPED_UNICODE), now()]);
    }
    $pdo->commit();
    respond(['ok' => true, 'count' => count($seen)]);

case 'clear':
    requireMethod('POST');
    db()->prepare('DELETE FROM models WHERE user_id = ?')->execute([$uid]);
    respond(['ok' => true]);

default:
    fail('Ação desconhecida.', 404);
}
