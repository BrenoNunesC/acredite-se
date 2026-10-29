<?php
/**
 * CRM de planilhas: importa uma planilha e anota cada linha
 * (status, responsável, próximo contato, etiquetas e histórico de anotações).
 * A planilha é pessoal ou de uma equipe.
 *   GET  crm.php?action=list
 *   GET  crm.php?action=get&id=
 *   GET  crm.php?action=notes&row_id=
 *   GET  crm.php?action=export&id=           → todas as anotações, para o Excel
 *   GET  crm.php?action=reminders            → próximos contatos de hoje e atrasados da pessoa
 *   POST crm.php?action=mail_pref    {on}    → liga/desliga o lembrete diário por e-mail
 *   POST crm.php?action=create       {name, team_id, columns, title_col, rows?, stages?, status_col?}
 *   POST crm.php?action=update_sheet {id, name?, stages?, title_col?, team_id?}
 *   POST crm.php?action=delete_sheet {id}
 *   POST crm.php?action=add_column   {id, name}
 *   POST crm.php?action=rename_column {id, index, name}
 *   POST crm.php?action=delete_column {id, index}
 *   POST crm.php?action=add_row      {sheet_id, data}
 *   POST crm.php?action=update_row   {id, status?, due?, tags?, assignee?, data?}
 *   POST crm.php?action=bulk         {sheet_id, ids, status?, assignee?, delete?}
 *   POST crm.php?action=delete_row   {id}
 *   POST crm.php?action=add_note     {row_id, kind, body}
 *   POST crm.php?action=delete_note  {id}
 * Permissões: o dono da planilha e os editores/administradores da equipe mudam tudo;
 * os leitores da equipe veem e anotam.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/teamlib.php';
$user = requireUser();
$uid  = (int)$user['id'];

const CRM_MAX_SHEETS = 100;   // por pessoa
const CRM_MAX_ROWS   = 5000;  // por planilha
const CRM_MAX_COLS   = 60;
const CRM_CELL       = 500;
const CRM_STAGES     = [
    ['name' => 'Novo', 'done' => false], ['name' => 'Em contato', 'done' => false],
    ['name' => 'Em andamento', 'done' => false], ['name' => 'Concluído', 'done' => true],
    ['name' => 'Perdido', 'done' => true],
];
const CRM_KINDS = ['nota', 'ligacao', 'email', 'reuniao', 'tarefa'];

/** Data de hoje no Brasil (para "atrasado"). */
function crmToday(): string {
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

/** Planilha com as permissões de quem pede. 404 se a pessoa não tiver acesso. */
function crmSheet(int $id): array {
    global $uid;
    $st = db()->prepare('SELECT * FROM crm_sheets WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) fail('Planilha não encontrada.', 404);
    $s['id']      = (int)$s['id'];
    $s['user_id'] = (int)$s['user_id'];
    $s['team_id'] = $s['team_id'] === null ? null : (int)$s['team_id'];
    $team  = $s['team_id'] ? teamMembership($uid, $s['team_id']) : null;
    $owner = $s['user_id'] === $uid;
    if (!$owner && !$team) fail('Planilha não encontrada.', 404);
    $s['team']      = $team;
    $s['can_edit']  = $owner || teamCanEdit($team);
    $s['can_admin'] = $owner || ($team && $team['role'] === 'admin');
    $s['stages']    = json_decode($s['stages_json'], true) ?: CRM_STAGES;
    $s['columns']   = json_decode($s['columns_json'], true) ?: [];
    return $s;
}

/** Linha + planilha dela (já com permissões). */
function crmRow(int $rowId): array {
    $st = db()->prepare('SELECT * FROM crm_rows WHERE id = ?');
    $st->execute([$rowId]);
    $r = $st->fetch();
    if (!$r) fail('Linha não encontrada.', 404);
    return [crmSheet((int)$r['sheet_id']), $r];
}

function needEdit(array $s): void {
    if (!$s['can_edit']) fail('Só o dono e os editores da equipe podem mudar esta planilha.', 403);
}

function cleanCell($v): string {
    return mb_substr(trim(preg_replace('/[\r\n\t]+/u', ' ', (string)$v)), 0, CRM_CELL);
}

function cleanStages($list): array {
    $out = [];
    $seen = [];
    foreach (is_array($list) ? array_slice($list, 0, 12) : [] as $st) {
        $name = mb_substr(trim((string)(is_array($st) ? ($st['name'] ?? '') : $st)), 0, 40);
        $key = mb_strtolower($name);
        if ($name === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['name' => $name, 'done' => is_array($st) && !empty($st['done'])];
    }
    if (count($out) < 2) fail('A planilha precisa de pelo menos 2 etapas.');
    return $out;
}

function cleanDue($v): ?string {
    if ($v === null || $v === '') return null;
    $v = (string)$v;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) fail('Data inválida.');
    return $v;
}

function cleanTags($v): string {
    $tags = [];
    foreach (preg_split('/\s*,\s*/u', (string)$v) as $t) {
        $t = mb_substr(trim($t), 0, 30);
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $tags), true)) $tags[] = $t;
    }
    return mb_substr(implode(', ', array_slice($tags, 0, 10)), 0, 255);
}

function cleanData($data, int $ncols): string {
    $data = is_array($data) ? array_values($data) : [];
    $out = [];
    for ($i = 0; $i < $ncols; $i++) $out[] = cleanCell($data[$i] ?? '');
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** Pessoas que podem ser responsáveis: o dono e, se for de equipe, os membros. */
function crmMembers(array $s): array {
    if ($s['team_id']) {
        $st = db()->prepare('SELECT u.id, u.name FROM team_members m JOIN users u ON u.id = m.user_id WHERE m.team_id = ?
                             UNION SELECT u.id, u.name FROM users u WHERE u.id = ? ORDER BY name');
        $st->execute([$s['team_id'], $s['user_id']]);
    } else {
        $st = db()->prepare('SELECT id, name FROM users WHERE id = ?');
        $st->execute([$s['user_id']]);
    }
    return array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $st->fetchAll());
}

function cleanAssignee(array $s, $v): ?int {
    if ($v === null || $v === '' || (int)$v === 0) return null;
    foreach (crmMembers($s) as $m) if ($m['id'] === (int)$v) return $m['id'];
    fail('Essa pessoa não participa desta planilha.');
}

function touchSheet(int $sheetId): void {
    db()->prepare('UPDATE crm_sheets SET updated_at = ? WHERE id = ?')->execute([now(), $sheetId]);
}

function addNote(int $rowId, string $kind, string $body): int {
    global $uid;
    db()->prepare('INSERT INTO crm_notes (row_id, user_id, kind, body, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$rowId, $uid, $kind, $body, now()]);
    return (int)db()->lastInsertId();
}

function rowOut(array $r): array {
    return [
        'id' => (int)$r['id'], 'pos' => (int)$r['pos'],
        'data' => json_decode($r['data_json'], true) ?: [],
        'status' => $r['status'], 'due' => $r['due_date'] ? substr((string)$r['due_date'], 0, 10) : null,
        'tags' => $r['tags'], 'assignee' => $r['assignee_id'] === null ? null : (int)$r['assignee_id'],
        'notes' => (int)($r['notes'] ?? 0), 'lastNote' => $r['last_note'] ?? null, 'lastAt' => $r['last_at'] ?? null,
        'updated' => $r['updated_at'],
    ];
}

function sheetOut(array $s): array {
    return [
        'id' => $s['id'], 'name' => $s['name'], 'columns' => $s['columns'], 'titleCol' => (int)$s['title_col'],
        'stages' => $s['stages'], 'team' => $s['team_id'], 'teamName' => $s['team']['name'] ?? null,
        'canEdit' => $s['can_edit'], 'canAdmin' => $s['can_admin'], 'mine' => $s['user_id'] === $GLOBALS['uid'],
        'updated' => $s['updated_at'],
    ];
}

const CRM_ROW_SELECT = "SELECT r.*,
    (SELECT COUNT(*) FROM crm_notes n WHERE n.row_id = r.id AND n.kind <> 'status') AS notes,
    (SELECT n.body FROM crm_notes n WHERE n.row_id = r.id AND n.kind <> 'status' ORDER BY n.id DESC LIMIT 1) AS last_note,
    (SELECT n.created_at FROM crm_notes n WHERE n.row_id = r.id AND n.kind <> 'status' ORDER BY n.id DESC LIMIT 1) AS last_at
    FROM crm_rows r";

function fetchRow(int $id): array {
    $st = db()->prepare(CRM_ROW_SELECT . ' WHERE r.id = ?');
    $st->execute([$id]);
    return rowOut($st->fetch());
}

switch (action()) {

case 'list':
    $teams = userTeams($uid);
    $ids = array_keys($teams);
    $sql = 'SELECT * FROM crm_sheets WHERE user_id = ?' . ($ids ? ' OR team_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '') . ' ORDER BY updated_at DESC';
    $st = db()->prepare($sql);
    $st->execute([$uid, ...$ids]);
    $today = crmToday();
    $out = [];
    foreach ($st->fetchAll() as $s) {
        $team = $s['team_id'] ? ($teams[(int)$s['team_id']] ?? null) : null;
        if ((int)$s['user_id'] !== $uid && !$team) continue; // planilha de equipe da qual a pessoa saiu
        $stages = json_decode($s['stages_json'], true) ?: CRM_STAGES;
        $done = array_column(array_filter($stages, fn($x) => !empty($x['done'])), 'name');
        $c = db()->prepare('SELECT status, due_date FROM crm_rows WHERE sheet_id = ?');
        $c->execute([$s['id']]);
        $total = 0; $open = 0; $late = 0;
        foreach ($c->fetchAll() as $r) {
            $total++;
            if (in_array($r['status'], $done, true)) continue;
            $open++;
            if ($r['due_date'] && substr((string)$r['due_date'], 0, 10) < $today) $late++;
        }
        $out[] = [
            'id' => (int)$s['id'], 'name' => $s['name'], 'team' => $s['team_id'] ? (int)$s['team_id'] : null,
            'teamName' => $team['name'] ?? null, 'columns' => count(json_decode($s['columns_json'], true) ?: []),
            'rows' => $total, 'open' => $open, 'late' => $late, 'updated' => $s['updated_at'],
            'mine' => (int)$s['user_id'] === $uid,
        ];
    }
    respond(['sheets' => $out, 'today' => $today]);

case 'get':
    $s = crmSheet((int)($_GET['id'] ?? 0));
    $st = db()->prepare(CRM_ROW_SELECT . ' WHERE r.sheet_id = ? ORDER BY r.pos, r.id');
    $st->execute([$s['id']]);
    respond([
        'sheet' => sheetOut($s), 'members' => crmMembers($s), 'today' => crmToday(),
        'rows' => array_map('rowOut', $st->fetchAll()),
    ]);

case 'reminders':
    // Próximos contatos de hoje e atrasados da pessoa, em todas as planilhas
    $today = crmToday();
    $st = db()->prepare('SELECT crm_mail FROM user_prefs WHERE user_id = ?');
    $st->execute([$uid]);
    $mail = $st->fetchColumn();
    respond(['items' => crmReminders($uid, $today), 'today' => $today, 'mail' => $mail === false || (int)$mail === 1]);

case 'mail_pref':
    requireMethod('POST');
    $on = !empty(body()['on']) ? 1 : 0;
    db()->prepare('INSERT INTO user_prefs (user_id, crm_mail, updated_at) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE crm_mail = VALUES(crm_mail), updated_at = VALUES(updated_at)')
        ->execute([$uid, $on, now()]);
    respond(['mail' => (bool)$on]);

case 'notes':
    [$s, $r] = crmRow((int)($_GET['row_id'] ?? 0));
    $st = db()->prepare('SELECT n.id, n.kind, n.body, n.created_at, n.user_id, u.name FROM crm_notes n LEFT JOIN users u ON u.id = n.user_id
                         WHERE n.row_id = ? ORDER BY n.id DESC');
    $st->execute([$r['id']]);
    respond(['notes' => array_map(fn($n) => [
        'id' => (int)$n['id'], 'kind' => $n['kind'], 'body' => $n['body'], 'date' => $n['created_at'],
        'author' => $n['name'] ?? 'Conta excluída',
        'canDelete' => $n['kind'] !== 'status' && ((int)$n['user_id'] === $uid || $s['can_admin']),
    ], $st->fetchAll())]);

case 'export':
    $s = crmSheet((int)($_GET['id'] ?? 0));
    $st = db()->prepare("SELECT n.row_id, n.kind, n.body, n.created_at, u.name FROM crm_notes n JOIN crm_rows r ON r.id = n.row_id
                         LEFT JOIN users u ON u.id = n.user_id WHERE r.sheet_id = ? AND n.kind <> 'status' ORDER BY n.id");
    $st->execute([$s['id']]);
    $notes = [];
    foreach ($st->fetchAll() as $n) {
        $notes[(int)$n['row_id']][] = ['kind' => $n['kind'], 'body' => $n['body'], 'date' => $n['created_at'], 'author' => $n['name'] ?? ''];
    }
    respond(['notes' => (object)$notes]);

case 'create':
    requireMethod('POST');
    $b = body();
    $name = str($b, 'name', 120);
    if ($name === '') fail('Dê um nome para a planilha.');
    $cols = array_map('cleanCell', array_values(is_array($b['columns'] ?? null) ? $b['columns'] : []));
    if (!$cols) fail('A planilha não tem colunas.');
    if (count($cols) > CRM_MAX_COLS) fail('Limite de ' . CRM_MAX_COLS . ' colunas.');
    foreach ($cols as $i => $c) if ($c === '') $cols[$i] = 'Coluna ' . ($i + 1);
    // Sem linhas é válido: planilha em branco, preenchida depois com "Nova linha"
    $rows = is_array($b['rows'] ?? null) ? $b['rows'] : [];
    if (count($rows) > CRM_MAX_ROWS) fail('Limite de ' . CRM_MAX_ROWS . ' linhas por planilha.');
    $teamId = (int)($b['team_id'] ?? 0) ?: null;
    if ($teamId && !teamCanEdit(teamMembership($uid, $teamId))) fail('Só editores e administradores podem criar planilhas na equipe.', 403);
    $st = db()->prepare('SELECT COUNT(*) FROM crm_sheets WHERE user_id = ?');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= CRM_MAX_SHEETS) fail('Limite de ' . CRM_MAX_SHEETS . ' planilhas atingido.');
    $titleCol = max(0, min(count($cols) - 1, (int)($b['title_col'] ?? 0)));
    // Etapas próprias (ex.: funil exportado do Ramper). Sem elas, valem as etapas padrão.
    $stages = is_array($b['stages'] ?? null) && count($b['stages']) >= 2 ? cleanStages($b['stages']) : CRM_STAGES;
    $stageNames = array_column($stages, 'name');
    // Coluna da planilha que já traz a etapa de cada linha (ex.: "Etapa" do Ramper)
    $statusCol = ($b['status_col'] ?? '') === '' || $b['status_col'] === null ? null : max(0, min(count($cols) - 1, (int)$b['status_col']));

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO crm_sheets (user_id, team_id, name, columns_json, title_col, stages_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$uid, $teamId, $name, json_encode($cols, JSON_UNESCAPED_UNICODE), $titleCol, json_encode($stages, JSON_UNESCAPED_UNICODE), now(), now()]);
    $sheetId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO crm_rows (sheet_id, pos, data_json, status, tags, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $pos = 0;
    foreach ($rows as $r) {
        $json = cleanData($r, count($cols));
        $vals = json_decode($json, true);
        if (!array_filter($vals, fn($c) => $c !== '')) continue; // linha vazia
        $status = $stages[0]['name'];
        if ($statusCol !== null && in_array($vals[$statusCol], $stageNames, true)) $status = $vals[$statusCol];
        $ins->execute([$sheetId, $pos++, $json, $status, '', now()]);
    }
    $pdo->commit();
    if ($teamId) logActivity($teamId, $uid, 'crm_create', $name, $sheetId);
    respond(['id' => $sheetId, 'rows' => $pos]);

case 'update_sheet':
    requireMethod('POST');
    $b = body();
    $s = crmSheet((int)($b['id'] ?? 0));
    needEdit($s);
    $pdo = db();
    if (array_key_exists('name', $b)) {
        $name = str($b, 'name', 120);
        if ($name === '') fail('Dê um nome para a planilha.');
        $pdo->prepare('UPDATE crm_sheets SET name = ? WHERE id = ?')->execute([$name, $s['id']]);
    }
    if (array_key_exists('title_col', $b)) {
        $pdo->prepare('UPDATE crm_sheets SET title_col = ? WHERE id = ?')->execute([max(0, min(count($s['columns']) - 1, (int)$b['title_col'])), $s['id']]);
    }
    if (array_key_exists('stages', $b)) {
        $stages = cleanStages($b['stages']);
        // Etapa renomeada (mesma posição, só o nome mudou) leva as linhas junto; etapa removida → primeira etapa
        $rename = is_array($b['renames'] ?? null) ? $b['renames'] : [];
        $names = array_column($stages, 'name');
        $pdo->beginTransaction();
        foreach ($rename as $from => $to) {
            if (in_array((string)$to, $names, true)) $pdo->prepare('UPDATE crm_rows SET status = ? WHERE sheet_id = ? AND status = ?')->execute([(string)$to, $s['id'], (string)$from]);
        }
        $in = implode(',', array_fill(0, count($names), '?'));
        $pdo->prepare("UPDATE crm_rows SET status = ? WHERE sheet_id = ? AND status NOT IN ($in)")->execute([$names[0], $s['id'], ...$names]);
        $pdo->prepare('UPDATE crm_sheets SET stages_json = ? WHERE id = ?')->execute([json_encode($stages, JSON_UNESCAPED_UNICODE), $s['id']]);
        $pdo->commit();
    }
    if (array_key_exists('team_id', $b)) {
        if (!$s['can_admin']) fail('Só o dono pode mudar com quem a planilha é compartilhada.', 403);
        $teamId = (int)$b['team_id'] ?: null;
        if ($teamId && !teamCanEdit(teamMembership($uid, $teamId))) fail('Você precisa ser editor ou administrador dessa equipe.', 403);
        $pdo->prepare('UPDATE crm_sheets SET team_id = ? WHERE id = ?')->execute([$teamId, $s['id']]);
        if ($teamId) logActivity($teamId, $uid, 'crm_share', $s['name'], $s['id']);
    }
    touchSheet($s['id']);
    respond(['sheet' => sheetOut(crmSheet($s['id'])), 'members' => crmMembers(crmSheet($s['id']))]);

case 'delete_sheet':
    requireMethod('POST');
    $s = crmSheet((int)(body()['id'] ?? 0));
    if (!$s['can_admin']) fail('Só o dono ou um administrador da equipe pode excluir a planilha.', 403);
    db()->prepare('DELETE FROM crm_sheets WHERE id = ?')->execute([$s['id']]);
    respond(['ok' => true]);

case 'add_column':
    requireMethod('POST');
    $b = body();
    $s = crmSheet((int)($b['id'] ?? 0));
    needEdit($s);
    $name = cleanCell($b['name'] ?? '');
    if ($name === '') fail('Dê um nome para a coluna.');
    if (count($s['columns']) >= CRM_MAX_COLS) fail('Limite de ' . CRM_MAX_COLS . ' colunas.');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE crm_sheets SET columns_json = ? WHERE id = ?')
        ->execute([json_encode([...$s['columns'], $name], JSON_UNESCAPED_UNICODE), $s['id']]);
    // As linhas antigas ganham a célula vazia da coluna nova
    $st = $pdo->prepare('SELECT id, data_json FROM crm_rows WHERE sheet_id = ?');
    $st->execute([$s['id']]);
    $up = $pdo->prepare('UPDATE crm_rows SET data_json = ? WHERE id = ?');
    foreach ($st->fetchAll() as $r) {
        $data = json_decode($r['data_json'], true) ?: [];
        $data[] = '';
        $up->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $r['id']]);
    }
    $pdo->commit();
    touchSheet($s['id']);
    respond(['sheet' => sheetOut(crmSheet($s['id']))]);

case 'rename_column':
    requireMethod('POST');
    $b = body();
    $s = crmSheet((int)($b['id'] ?? 0));
    needEdit($s);
    $i = (int)($b['index'] ?? -1);
    if ($i < 0 || $i >= count($s['columns'])) fail('Coluna não encontrada.', 404);
    $name = cleanCell($b['name'] ?? '');
    if ($name === '') fail('Dê um nome para a coluna.');
    $cols = $s['columns'];
    $cols[$i] = $name;
    db()->prepare('UPDATE crm_sheets SET columns_json = ? WHERE id = ?')->execute([json_encode($cols, JSON_UNESCAPED_UNICODE), $s['id']]);
    touchSheet($s['id']);
    respond(['sheet' => sheetOut(crmSheet($s['id']))]);

case 'delete_column':
    requireMethod('POST');
    $b = body();
    $s = crmSheet((int)($b['id'] ?? 0));
    needEdit($s);
    $i = (int)($b['index'] ?? -1);
    if ($i < 0 || $i >= count($s['columns'])) fail('Coluna não encontrada.', 404);
    if (count($s['columns']) <= 1) fail('A planilha precisa de pelo menos uma coluna.');
    $cols = $s['columns'];
    array_splice($cols, $i, 1);
    // A coluna principal continua apontando para a mesma coluna (ou para a primeira, se ela foi excluída)
    $title = (int)$s['title_col'];
    if ($title === $i) $title = 0;
    elseif ($title > $i) $title--;
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE crm_sheets SET columns_json = ?, title_col = ? WHERE id = ?')
        ->execute([json_encode($cols, JSON_UNESCAPED_UNICODE), $title, $s['id']]);
    $st = $pdo->prepare('SELECT id, data_json FROM crm_rows WHERE sheet_id = ?');
    $st->execute([$s['id']]);
    $up = $pdo->prepare('UPDATE crm_rows SET data_json = ? WHERE id = ?');
    foreach ($st->fetchAll() as $r) {
        $data = json_decode($r['data_json'], true) ?: [];
        array_splice($data, $i, 1);
        $up->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $r['id']]);
    }
    $pdo->commit();
    touchSheet($s['id']);
    respond(['sheet' => sheetOut(crmSheet($s['id']))]);

case 'add_row':
    requireMethod('POST');
    $b = body();
    $s = crmSheet((int)($b['sheet_id'] ?? 0));
    needEdit($s);
    $st = db()->prepare('SELECT COUNT(*), MAX(pos) FROM crm_rows WHERE sheet_id = ?');
    $st->execute([$s['id']]);
    [$count, $max] = $st->fetch(PDO::FETCH_NUM);
    if ((int)$count >= CRM_MAX_ROWS) fail('Limite de ' . CRM_MAX_ROWS . ' linhas por planilha.');
    db()->prepare('INSERT INTO crm_rows (sheet_id, pos, data_json, status, tags, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$s['id'], (int)$max + 1, cleanData($b['data'] ?? [], count($s['columns'])), $s['stages'][0]['name'], '', now()]);
    $id = (int)db()->lastInsertId();
    touchSheet($s['id']);
    respond(['row' => fetchRow($id)]);

case 'update_row':
    requireMethod('POST');
    $b = body();
    [$s, $r] = crmRow((int)($b['id'] ?? 0));
    needEdit($s);
    $set = [];
    $args = [];
    if (array_key_exists('status', $b)) {
        $status = (string)$b['status'];
        if (!in_array($status, array_column($s['stages'], 'name'), true)) fail('Etapa inválida.');
        if ($status !== $r['status']) {
            $set[] = 'status = ?'; $args[] = $status;
            addNote((int)$r['id'], 'status', ($r['status'] !== '' ? $r['status'] . ' → ' : '') . $status);
        }
    }
    if (array_key_exists('due', $b))      { $set[] = 'due_date = ?';    $args[] = cleanDue($b['due']); }
    if (array_key_exists('tags', $b))     { $set[] = 'tags = ?';        $args[] = cleanTags($b['tags']); }
    if (array_key_exists('assignee', $b)) { $set[] = 'assignee_id = ?'; $args[] = cleanAssignee($s, $b['assignee']); }
    if (array_key_exists('data', $b))     { $set[] = 'data_json = ?';   $args[] = cleanData($b['data'], count($s['columns'])); }
    if ($set) {
        $set[] = 'updated_at = ?'; $args[] = now();
        db()->prepare('UPDATE crm_rows SET ' . implode(', ', $set) . ' WHERE id = ?')->execute([...$args, $r['id']]);
        touchSheet($s['id']);
    }
    respond(['row' => fetchRow((int)$r['id'])]);

case 'bulk':
    requireMethod('POST');
    $b = body();
    $s = crmSheet((int)($b['sheet_id'] ?? 0));
    needEdit($s);
    $ids = array_values(array_unique(array_map('intval', is_array($b['ids'] ?? null) ? array_slice($b['ids'], 0, CRM_MAX_ROWS) : [])));
    if (!$ids) fail('Nenhuma linha selecionada.');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $status = array_key_exists('status', $b) ? (string)$b['status'] : null;
    if ($status !== null && !in_array($status, array_column($s['stages'], 'name'), true)) fail('Etapa inválida.');
    $assignee = array_key_exists('assignee', $b) ? cleanAssignee($s, $b['assignee']) : false;
    $pdo = db();
    $pdo->beginTransaction();
    if (!empty($b['delete'])) {
        $pdo->prepare("DELETE FROM crm_rows WHERE sheet_id = ? AND id IN ($in)")->execute([$s['id'], ...$ids]);
    } else {
        if ($status !== null) {
            $st = $pdo->prepare("SELECT id, status FROM crm_rows WHERE sheet_id = ? AND id IN ($in) AND status <> ?");
            $st->execute([$s['id'], ...$ids, $status]);
            foreach ($st->fetchAll() as $r) addNote((int)$r['id'], 'status', ($r['status'] !== '' ? $r['status'] . ' → ' : '') . $status);
            $pdo->prepare("UPDATE crm_rows SET status = ?, updated_at = ? WHERE sheet_id = ? AND id IN ($in)")->execute([$status, now(), $s['id'], ...$ids]);
        }
        if ($assignee !== false) {
            $pdo->prepare("UPDATE crm_rows SET assignee_id = ?, updated_at = ? WHERE sheet_id = ? AND id IN ($in)")
                ->execute([$assignee, now(), $s['id'], ...$ids]);
        }
    }
    $pdo->commit();
    touchSheet($s['id']);
    respond(['ok' => true]);

case 'delete_row':
    requireMethod('POST');
    [$s, $r] = crmRow((int)(body()['id'] ?? 0));
    needEdit($s);
    db()->prepare('DELETE FROM crm_rows WHERE id = ?')->execute([$r['id']]);
    touchSheet($s['id']);
    respond(['ok' => true]);

case 'add_note':
    requireMethod('POST');
    $b = body();
    [$s, $r] = crmRow((int)($b['row_id'] ?? 0));
    $kind = (string)($b['kind'] ?? 'nota');
    if (!in_array($kind, CRM_KINDS, true)) $kind = 'nota';
    $text = trim(mb_substr((string)($b['body'] ?? ''), 0, 4000));
    if ($text === '') fail('Escreva a anotação.');
    addNote((int)$r['id'], $kind, $text);
    db()->prepare('UPDATE crm_rows SET updated_at = ? WHERE id = ?')->execute([now(), $r['id']]);
    touchSheet($s['id']);
    respond(['row' => fetchRow((int)$r['id'])]);

case 'delete_note':
    requireMethod('POST');
    $st = db()->prepare('SELECT * FROM crm_notes WHERE id = ?');
    $st->execute([(int)(body()['id'] ?? 0)]);
    $n = $st->fetch();
    if (!$n) fail('Anotação não encontrada.', 404);
    [$s, $r] = crmRow((int)$n['row_id']);
    if ($n['kind'] === 'status' || ((int)$n['user_id'] !== $uid && !$s['can_admin'])) fail('Só quem escreveu pode excluir esta anotação.', 403);
    db()->prepare('DELETE FROM crm_notes WHERE id = ?')->execute([$n['id']]);
    respond(['row' => fetchRow((int)$r['id'])]);

default:
    fail('Ação desconhecida.', 404);
}
