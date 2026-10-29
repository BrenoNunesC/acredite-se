<?php
/**
 * Equipes do usuário (uma pessoa pode estar em várias)
 *   GET  team.php?action=list                         → {teams:[…], invites:[convites pendentes para mim]}
 *   GET  team.php?action=detail&id=N                  → {team, members, invites (admin), activity}
 *   POST team.php?action=create   {name, description}
 *   POST team.php?action=join     {code}              → entra como leitor
 *   POST team.php?action=leave    {team_id}
 *   POST team.php?action=update   {team_id, name, description}     (admin)
 *   POST team.php?action=code     {team_id}                         (admin) novo código de convite
 *   POST team.php?action=role     {team_id, user_id, role}          (admin)
 *   POST team.php?action=remove   {team_id, user_id}                (admin)
 *   POST team.php?action=settings {team_id, alerts_on, report_monthly} (admin)
 *   POST team.php?action=report_now {team_id}                       (admin)
 *   POST team.php?action=delete   {team_id}                         (admin) apaga a equipe
 *   POST team.php?action=invite   {team_id, email, role}            (admin) convite por e-mail
 *   POST team.php?action=invite_cancel {team_id, invite_id}         (admin)
 *   POST team.php?action=accept   {invite_id}  /  decline {invite_id}
 *   POST team.php?action=check_goals {item_id, team_id?}  → avisa os admins se meta do item estiver fora do verde
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/teamlib.php';
$user = requireUser();
$uid  = (int)$user['id'];
$myEmail = mb_strtolower($user['email']);

function needTeam(int $teamId): array {
    global $uid;
    $t = teamMembership($uid, $teamId);
    if (!$t) fail('Equipe não encontrada.', 404);
    return $t;
}
function needAdmin(int $teamId): array {
    $t = needTeam($teamId);
    if ($t['role'] !== 'admin') fail('Só administradores da equipe podem fazer isso.', 403);
    return $t;
}
function adminCount(int $teamId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND role = 'admin'");
    $st->execute([$teamId]);
    return (int)$st->fetchColumn();
}
function memberCount(int $teamId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = ?');
    $st->execute([$teamId]);
    return (int)$st->fetchColumn();
}
function memberOf(int $teamId, int $userId): ?array {
    $st = db()->prepare('SELECT m.user_id, m.role, u.name FROM team_members m JOIN users u ON u.id = m.user_id WHERE m.team_id = ? AND m.user_id = ?');
    $st->execute([$teamId, $userId]);
    return $st->fetch() ?: null;
}
function iso(?string $d): ?string { return $d ? str_replace(' ', 'T', $d) . 'Z' : null; }
function teamSummary(array $t): array {
    $c = fn(string $sql) => (function () use ($sql, $t) { $st = db()->prepare($sql); $st->execute([$t['id']]); return (int)$st->fetchColumn(); })();
    return [
        'id' => $t['id'], 'name' => $t['name'], 'description' => (string)($t['description'] ?? ''), 'role' => $t['role'],
        'members' => $c('SELECT COUNT(*) FROM team_members WHERE team_id = ?'),
        'items'   => $c('SELECT COUNT(*) FROM library WHERE team_id = ?'),
        'models'  => $c('SELECT COUNT(*) FROM team_models WHERE team_id = ?'),
    ];
}
function myInvites(): array {
    global $myEmail;
    $st = db()->prepare('SELECT i.id, i.role, i.created_at, t.name AS team, u.name AS by_name FROM team_invites i
                         JOIN teams t ON t.id = i.team_id LEFT JOIN users u ON u.id = i.invited_by WHERE i.email = ? ORDER BY i.id');
    $st->execute([$myEmail]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'team' => $r['team'], 'role' => $r['role'], 'by' => (string)$r['by_name'], 'date' => iso($r['created_at'])], $st->fetchAll());
}
function teamDetail(array $t): array {
    global $uid;
    $st = db()->prepare('SELECT u.id, u.name, u.email, m.role, m.created_at FROM team_members m JOIN users u ON u.id = m.user_id WHERE m.team_id = ? ORDER BY u.name');
    $st->execute([$t['id']]);
    $members = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'],
                                    'since' => iso($r['created_at']), 'me' => (int)$r['id'] === $uid], $st->fetchAll());
    $st = db()->prepare('SELECT a.action, a.detail, a.created_at, u.name FROM team_activity a LEFT JOIN users u ON u.id = a.user_id
                         WHERE a.team_id = ? ORDER BY a.id DESC LIMIT 50');
    $st->execute([$t['id']]);
    $activity = array_map(fn($r) => ['action' => $r['action'], 'detail' => $r['detail'], 'who' => $r['name'] ?? '', 'date' => iso($r['created_at'])], $st->fetchAll());
    $team = teamSummary($t) + ['alerts_on' => (bool)$t['alerts_on'], 'report_monthly' => (bool)$t['report_monthly'], 'report_last' => iso($t['report_last'])];
    $invites = [];
    if ($t['role'] === 'admin') {
        $team['code'] = $t['join_code'];
        $team['link'] = appUrl() . '?equipe=' . $t['join_code'];
        $st = db()->prepare('SELECT i.id, i.email, i.role, i.created_at, u.name AS by_name FROM team_invites i LEFT JOIN users u ON u.id = i.invited_by WHERE i.team_id = ? ORDER BY i.id DESC');
        $st->execute([$t['id']]);
        $invites = array_map(fn($r) => ['id' => (int)$r['id'], 'email' => $r['email'], 'role' => $r['role'], 'by' => (string)$r['by_name'], 'date' => iso($r['created_at'])], $st->fetchAll());
    }
    return ['team' => $team, 'members' => $members, 'invites' => $invites, 'activity' => $activity];
}
function addMember(int $teamId, int $userId, string $role): void {
    global $uid;
    if (count(userTeams($userId)) >= MAX_TEAMS_PER_USER) fail('Limite de ' . MAX_TEAMS_PER_USER . ' equipes por pessoa atingido.');
    if (memberCount($teamId) >= MAX_MEMBERS_PER_TEAM) fail('Esta equipe atingiu o limite de ' . MAX_MEMBERS_PER_TEAM . ' membros.');
    db()->prepare('INSERT INTO team_members (team_id, user_id, role, created_at) VALUES (?, ?, ?, ?)')->execute([$teamId, $userId, $role, now()]);
}
function teamIdFromBody(): int { return (int)(body()['team_id'] ?? 0); }

switch (action()) {

case 'list':
    respond(['teams' => array_values(array_map('teamSummary', userTeams($uid))), 'invites' => myInvites()]);

case 'detail':
    respond(teamDetail(needTeam((int)($_GET['id'] ?? 0))));

case 'create':
    requireMethod('POST');
    $b = body();
    $name = str($b, 'name', 120);
    if (mb_strlen($name) < 2) fail('Dê um nome à equipe.');
    if (count(userTeams($uid)) >= MAX_TEAMS_PER_USER) fail('Limite de ' . MAX_TEAMS_PER_USER . ' equipes por pessoa atingido.');
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO teams (name, description, join_code, created_by, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$name, str($b, 'description', 500), newJoinCode(), $uid, now()]);
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO team_members (team_id, user_id, role, created_at) VALUES (?, ?, 'admin', ?)")->execute([$tid, $uid, now()]);
    logActivity($tid, $uid, 'team_create', $name);
    $pdo->commit();
    respond(teamDetail(needTeam($tid)), 201);

case 'join':
    requireMethod('POST');
    $key = 'join:' . $uid;
    if (tooManyAttempts($key, 10, 900)) fail('Muitas tentativas. Aguarde alguns minutos.', 429);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)(body()['code'] ?? '')));
    $st = db()->prepare('SELECT id FROM teams WHERE join_code = ?');
    $st->execute([$code]);
    $tid = (int)$st->fetchColumn();
    if (!$tid) { recordAttempt($key); fail('Código de convite inválido.', 404); }
    if (teamMembership($uid, $tid)) fail('Você já faz parte desta equipe.');
    addMember($tid, $uid, 'leitor');
    db()->prepare('DELETE FROM team_invites WHERE team_id = ? AND email = ?')->execute([$tid, $myEmail]);
    logActivity($tid, $uid, 'member_join');
    respond(teamDetail(needTeam($tid)));

case 'accept':
case 'decline':
    requireMethod('POST');
    $iid = (int)(body()['invite_id'] ?? 0);
    $st = db()->prepare('SELECT * FROM team_invites WHERE id = ? AND email = ?');
    $st->execute([$iid, $myEmail]);
    $inv = $st->fetch();
    if (!$inv) fail('Convite não encontrado.', 404);
    db()->prepare('DELETE FROM team_invites WHERE id = ?')->execute([$iid]);
    if (action() === 'decline') { logActivity((int)$inv['team_id'], $uid, 'invite_decline', $user['name']); respond(['ok' => true, 'invites' => myInvites()]); }
    if (!teamMembership($uid, (int)$inv['team_id'])) addMember((int)$inv['team_id'], $uid, $inv['role']);
    logActivity((int)$inv['team_id'], $uid, 'member_join');
    respond(teamDetail(needTeam((int)$inv['team_id'])) + ['invites_left' => myInvites()]);

case 'leave':
    requireMethod('POST');
    $t = needTeam(teamIdFromBody());
    $count = memberCount($t['id']);
    if ($count > 1 && $t['role'] === 'admin' && adminCount($t['id']) === 1) {
        fail('Você é o único administrador. Promova outra pessoa a administrador antes de sair.');
    }
    if ($count === 1) { deleteTeam($t['id']); respond(['ok' => true, 'deleted' => true]); }
    db()->prepare('UPDATE library SET team_id = NULL WHERE user_id = ? AND team_id = ?')->execute([$uid, $t['id']]);
    db()->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?')->execute([$t['id'], $uid]);
    logActivity($t['id'], $uid, 'member_leave');
    respond(['ok' => true]);

case 'update':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    $b = body();
    $name = array_key_exists('name', $b) ? str($b, 'name', 120) : $t['name'];
    if (mb_strlen($name) < 2) fail('Dê um nome à equipe.');
    $desc = array_key_exists('description', $b) ? str($b, 'description', 500) : (string)$t['description'];
    db()->prepare('UPDATE teams SET name = ?, description = ? WHERE id = ?')->execute([$name, $desc, $t['id']]);
    if ($name !== $t['name']) logActivity($t['id'], $uid, 'team_rename', $name);
    respond(teamDetail(needTeam($t['id'])));

case 'code':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    db()->prepare('UPDATE teams SET join_code = ? WHERE id = ?')->execute([newJoinCode(), $t['id']]);
    logActivity($t['id'], $uid, 'code_new');
    respond(teamDetail(needTeam($t['id'])));

case 'role':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    $b = body();
    $target = (int)($b['user_id'] ?? 0);
    $role = (string)($b['role'] ?? '');
    if (!in_array($role, TEAM_ROLES, true)) fail('Papel inválido.');
    $m = memberOf($t['id'], $target);
    if (!$m) fail('Pessoa não encontrada na equipe.', 404);
    if ($m['role'] === 'admin' && $role !== 'admin' && adminCount($t['id']) === 1) fail('A equipe precisa de pelo menos um administrador.');
    db()->prepare('UPDATE team_members SET role = ? WHERE team_id = ? AND user_id = ?')->execute([$role, $t['id'], $target]);
    logActivity($t['id'], $uid, 'member_role', "{$m['name']}|$role");
    respond(teamDetail(needTeam($t['id'])));

case 'remove':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    $target = (int)(body()['user_id'] ?? 0);
    if ($target === $uid) fail('Para sair da equipe, use "Sair da equipe".');
    $m = memberOf($t['id'], $target);
    if (!$m) fail('Pessoa não encontrada na equipe.', 404);
    db()->prepare('UPDATE library SET team_id = NULL WHERE user_id = ? AND team_id = ?')->execute([$target, $t['id']]);
    db()->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?')->execute([$t['id'], $target]);
    logActivity($t['id'], $uid, 'member_remove', $m['name']);
    respond(teamDetail(needTeam($t['id'])));

case 'delete':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    deleteTeam($t['id']);
    respond(['ok' => true]);

case 'invite':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    $b = body();
    $email = mb_strtolower(str($b, 'email', 190));
    $role = (string)($b['role'] ?? 'leitor');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Informe um e-mail válido.');
    if (!in_array($role, TEAM_ROLES, true)) fail('Papel inválido.');
    $st = db()->prepare('SELECT 1 FROM team_members m JOIN users u ON u.id = m.user_id WHERE m.team_id = ? AND u.email = ?');
    $st->execute([$t['id'], $email]);
    if ($st->fetchColumn()) fail('Esta pessoa já faz parte da equipe.');
    $st = db()->prepare('SELECT COUNT(*) FROM team_invites WHERE team_id = ?');
    $st->execute([$t['id']]);
    if ((int)$st->fetchColumn() >= 100) fail('Limite de 100 convites pendentes atingido.');
    db()->prepare('DELETE FROM team_invites WHERE team_id = ? AND email = ?')->execute([$t['id'], $email]);
    db()->prepare('INSERT INTO team_invites (team_id, email, role, invited_by, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$t['id'], $email, $role, $uid, now()]);
    $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $st->execute([$email]);
    $exists = (bool)$st->fetchColumn();
    $roleName = ['admin' => 'administrador(a)', 'editor' => 'editor(a)', 'leitor' => 'leitor(a)'][$role];
    $body = "{$user['name']} convidou você para a equipe \"{$t['name']}\" no {$CONFIG['app_name']}, como $roleName.\n\n"
          . ($exists ? "Entre no sistema para aceitar o convite: " : "Crie sua conta com este e-mail ($email) e o convite aparecerá ao entrar: ")
          . appUrl() . "\n\nSe você não esperava este convite, é só ignorar.";
    $sent = sendAppMail($email, "Convite para a equipe {$t['name']}", $body);
    logActivity($t['id'], $uid, 'invite_sent', $email);
    respond(teamDetail(needTeam($t['id'])) + ['mailed' => $sent, 'registered' => $exists]);

case 'invite_cancel':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    $st = db()->prepare('SELECT email FROM team_invites WHERE id = ? AND team_id = ?');
    $st->execute([(int)(body()['invite_id'] ?? 0), $t['id']]);
    $email = $st->fetchColumn();
    if ($email === false) fail('Convite não encontrado.', 404);
    db()->prepare('DELETE FROM team_invites WHERE id = ? AND team_id = ?')->execute([(int)body()['invite_id'], $t['id']]);
    logActivity($t['id'], $uid, 'invite_cancel', (string)$email);
    respond(teamDetail(needTeam($t['id'])));

case 'settings':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    $b = body();
    $alerts = array_key_exists('alerts_on', $b) ? (int)!empty($b['alerts_on']) : (int)$t['alerts_on'];
    $report = array_key_exists('report_monthly', $b) ? (int)!empty($b['report_monthly']) : (int)$t['report_monthly'];
    db()->prepare('UPDATE teams SET alerts_on = ?, report_monthly = ? WHERE id = ?')->execute([$alerts, $report, $t['id']]);
    if ($alerts !== (int)$t['alerts_on']) logActivity($t['id'], $uid, $alerts ? 'alerts_on' : 'alerts_off');
    if ($report !== (int)$t['report_monthly']) logActivity($t['id'], $uid, $report ? 'report_on' : 'report_off');
    respond(teamDetail(needTeam($t['id'])));

case 'report_now':
    requireMethod('POST');
    $t = needAdmin(teamIdFromBody());
    $rep = buildTeamReport($t);
    if (!$rep) fail('Ainda não há itens compartilhados com a equipe para o relatório.');
    $sent = 0;
    foreach (teamEmails($t['id']) as $email) $sent += sendAppMail($email, $rep['subject'], $rep['body']) ? 1 : 0;
    logActivity($t['id'], $uid, 'report_sent', (string)$sent);
    $resp = ['sent' => $sent];
    if (!empty($CONFIG['debug'])) $resp['preview'] = $rep;
    respond($resp);

case 'check_goals':
    requireMethod('POST');
    $b = body();
    $itemId = (int)($b['item_id'] ?? 0);
    // O item precisa ser da pessoa ou de uma equipe dela (verificado antes de qualquer outra resposta)
    $teams = userTeams($uid);
    $st = db()->prepare('SELECT * FROM library WHERE id = ?');
    $st->execute([$itemId]);
    $item = $st->fetch();
    if (!$item || ((int)$item['user_id'] !== $uid && !isset($teams[(int)$item['team_id']]))) fail('Item não encontrado.', 404);
    // Equipe avisada: a do item; se o item for pessoal, a equipe do modelo usado (informada pelo navegador, se a pessoa for membro)
    $tid = $item['team_id'] ? (int)$item['team_id'] : (int)($b['team_id'] ?? 0);
    $t = $teams[$tid] ?? null;
    if (!$t) respond(['sent' => 0, 'reason' => 'sem equipe']);
    if (!$t['alerts_on']) respond(['sent' => 0, 'reason' => 'alertas desligados']);
    // Um alerta por item, e no máximo 20 por dia por equipe
    $st = db()->prepare("SELECT COUNT(*) FROM team_activity WHERE team_id = ? AND action = 'goal_alert' AND ref_id = ?");
    $st->execute([$tid, $itemId]);
    if ($st->fetchColumn()) respond(['sent' => 0, 'reason' => 'já avisado']);
    $st = db()->prepare("SELECT COUNT(*) FROM team_activity WHERE team_id = ? AND action = 'goal_alert' AND created_at > ?");
    $st->execute([$tid, gmdate('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$st->fetchColumn() >= 20) respond(['sent' => 0, 'reason' => 'limite diário']);
    $pres = json_decode((string)$item['presentation'], true);
    $off = array_values(array_filter(goalStates(is_array($pres) ? $pres : []), fn($g) => $g['state'] !== 'ok'));
    if (!$off) respond(['sent' => 0, 'reason' => 'metas no verde']);
    $title = $item['title'] ?: $item['source_file'];
    $lines = ["{$user['name']} salvou \"$title\" e há meta(s) fora do verde:", ''];
    foreach ($off as $g) $lines[] = ($g['state'] === 'bad' ? '✖ Não atingida: ' : '▲ Quase lá: ') . $g['text'];
    $lines[] = '';
    $lines[] = 'Abra o sistema: ' . appUrl();
    $lines[] = '';
    $lines[] = 'Você recebe este aviso por ser administrador da equipe "' . $t['name'] . '". Os alertas podem ser desligados na tela Equipes.';
    $sent = 0;
    foreach (teamEmails($tid, true) as $email) $sent += sendAppMail($email, 'Meta fora do verde: ' . $off[0]['text'], implode("\n", $lines)) ? 1 : 0;
    logActivity($tid, $uid, 'goal_alert', $off[0]['text'], $itemId);
    respond(['sent' => $sent, 'goals' => $off]);

default:
    fail('Ação desconhecida.', 404);
}
