<?php
/**
 * Administração do sistema (só para administradores gerais: users.is_admin = 1)
 *   GET  admin.php?action=overview                → números, informações do servidor e configurações
 *   GET  admin.php?action=users&q=&page=          → usuários (busca por nome/e-mail)
 *   POST admin.php?action=user_update  {user_id, blocked?, is_admin?}
 *   POST admin.php?action=user_password {user_id} → gera uma senha temporária (mostrada uma vez)
 *   POST admin.php?action=user_delete  {user_id}
 *   GET  admin.php?action=teams&q=                → todas as equipes
 *   GET  admin.php?action=team&id=N               → membros de uma equipe
 *   POST admin.php?action=team_delete  {team_id}
 *   POST admin.php?action=settings     {allow_register}
 *   GET  admin.php?action=log                     → registro das ações dos administradores
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/teamlib.php';
$admin = requireSysAdmin();
$aid   = (int)$admin['id'];

const PAGE_SIZE = 25;

function iso(?string $d): ?string { return $d ? str_replace(' ', 'T', $d) . 'Z' : null; }
function count1(string $sql, array $args = []): int {
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}
function userRow(int $id): array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) fail('Usuário não encontrado.', 404);
    return $u;
}
function activeAdmins(): int { return count1('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND blocked = 0'); }
function userOut(array $u): array {
    global $aid;
    $id = (int)$u['id'];
    return [
        'id' => $id, 'name' => $u['name'], 'email' => $u['email'],
        'created' => iso($u['created_at']), 'lastLogin' => iso($u['last_login']),
        'isAdmin' => (bool)(int)$u['is_admin'], 'blocked' => (bool)(int)$u['blocked'], 'me' => $id === $aid,
        'items' => (int)($u['n_items'] ?? 0), 'teams' => (int)($u['n_teams'] ?? 0),
    ];
}

switch (action()) {

case 'overview':
    $since30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $since7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
    $storage = rtrim($CONFIG['storage_dir'], '/\\');
    $size = 0;
    if (is_dir($storage)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS)) as $f) $size += $f->getSize();
    }
    respond([
        'stats' => [
            'users'        => count1('SELECT COUNT(*) FROM users'),
            'usersNew30'   => count1('SELECT COUNT(*) FROM users WHERE created_at > ?', [$since30]),
            'active7'      => count1('SELECT COUNT(*) FROM users WHERE last_login > ?', [$since7]),
            'blocked'      => count1('SELECT COUNT(*) FROM users WHERE blocked = 1'),
            'admins'       => count1('SELECT COUNT(*) FROM users WHERE is_admin = 1'),
            'teams'        => count1('SELECT COUNT(*) FROM teams'),
            'items'        => count1('SELECT COUNT(*) FROM library'),
            'items30'      => count1('SELECT COUNT(*) FROM library WHERE created_at > ?', [$since30]),
            'shared'       => count1('SELECT COUNT(*) FROM library WHERE share_token IS NOT NULL'),
            'comments'     => count1('SELECT COUNT(*) FROM library_comments'),
            'models'       => count1('SELECT COUNT(*) FROM models') + count1('SELECT COUNT(*) FROM team_models'),
        ],
        'system' => [
            'php'        => PHP_VERSION,
            'db'         => 'MariaDB / MySQL',
            'debug'      => (bool)$CONFIG['debug'],
            'mailFrom'   => $CONFIG['mail_from'],
            'cronKey'    => !empty($CONFIG['cron_key']),
            'cronLast'   => iso(setting('cron_last')),
            'storageMB'  => round($size / 1048576, 1),
            'schema'     => (int)setting('schema_version', '0'),
            'appUrl'     => appUrl(),
        ],
        'settings' => ['allow_register' => setting('allow_register', '1') === '1'],
    ]);

case 'users':
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $filter = (string)($_GET['filter'] ?? '');
    $where = [];
    $args = [];
    if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
    if ($filter === 'admins') $where[] = 'u.is_admin = 1';
    if ($filter === 'blocked') $where[] = 'u.blocked = 1';
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = count1("SELECT COUNT(*) FROM users u $w", $args);
    $st = db()->prepare("SELECT u.*, (SELECT COUNT(*) FROM library l WHERE l.user_id = u.id) AS n_items,
                                (SELECT COUNT(*) FROM team_members m WHERE m.user_id = u.id) AS n_teams
                         FROM users u $w ORDER BY u.id DESC LIMIT " . PAGE_SIZE . ' OFFSET ' . (($page - 1) * PAGE_SIZE));
    $st->execute($args);
    respond(['users' => array_map('userOut', $st->fetchAll()), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / PAGE_SIZE))]);

case 'user_update':
    requireMethod('POST');
    $b = body();
    $u = userRow((int)($b['user_id'] ?? 0));
    $id = (int)$u['id'];
    if (array_key_exists('blocked', $b)) {
        $blocked = (int)!empty($b['blocked']);
        if ($id === $aid && $blocked) fail('Você não pode bloquear a própria conta.');
        if ($blocked && (int)$u['is_admin'] && activeAdmins() <= 1) fail('Não é possível bloquear o último administrador ativo.');
        db()->prepare('UPDATE users SET blocked = ? WHERE id = ?')->execute([$blocked, $id]);
        adminLog($aid, $blocked ? 'user_block' : 'user_unblock', $u['email']);
    }
    if (array_key_exists('is_admin', $b)) {
        $isAdmin = (int)!empty($b['is_admin']);
        if (!$isAdmin && (int)$u['is_admin'] && activeAdmins() <= 1) fail('O sistema precisa de pelo menos um administrador.');
        db()->prepare('UPDATE users SET is_admin = ? WHERE id = ?')->execute([$isAdmin, $id]);
        adminLog($aid, $isAdmin ? 'admin_grant' : 'admin_revoke', $u['email']);
    }
    $st = db()->prepare('SELECT u.*, (SELECT COUNT(*) FROM library l WHERE l.user_id = u.id) AS n_items,
                                (SELECT COUNT(*) FROM team_members m WHERE m.user_id = u.id) AS n_teams FROM users u WHERE u.id = ?');
    $st->execute([$id]);
    respond(['user' => userOut($st->fetch())]);

case 'user_password':
    requireMethod('POST');
    $u = userRow((int)(body()['user_id'] ?? 0));
    // Senha temporária legível (sem caracteres parecidos); a pessoa troca depois em Configurações
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pass = '';
    for ($i = 0; $i < 12; $i++) $pass .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$u['id']]);
    adminLog($aid, 'user_password', $u['email']);
    respond(['password' => $pass]);

case 'user_delete':
    requireMethod('POST');
    $u = userRow((int)(body()['user_id'] ?? 0));
    $id = (int)$u['id'];
    if ($id === $aid) fail('Você não pode excluir a própria conta por aqui.');
    if ((int)$u['is_admin'] && activeAdmins() <= 1) fail('Não é possível excluir o último administrador.');
    // Equipes em que a pessoa é a única administradora: promove o membro mais antigo (ou apaga a equipe vazia)
    $st = db()->prepare("SELECT team_id FROM team_members WHERE user_id = ? AND role = 'admin'");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        if (count1("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND role = 'admin' AND user_id <> ?", [$tid, $id])) continue;
        $st2 = db()->prepare('SELECT user_id FROM team_members WHERE team_id = ? AND user_id <> ? ORDER BY created_at LIMIT 1');
        $st2->execute([$tid, $id]);
        $next = $st2->fetchColumn();
        if ($next) db()->prepare("UPDATE team_members SET role = 'admin' WHERE team_id = ? AND user_id = ?")->execute([$tid, $next]);
        else deleteTeam((int)$tid);
    }
    // Arquivos antigos (PNG) do usuário
    $dir = rtrim($CONFIG['storage_dir'], '/\\') . '/infograficos';
    $st = db()->prepare("SELECT image_file FROM library WHERE user_id = ? AND image_file <> ''");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) @unlink($dir . '/' . basename((string)$f));
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]); // itens, modelos, comentários e participações saem em cascata
    adminLog($aid, 'user_delete', $u['email']);
    respond(['ok' => true]);

case 'teams':
    $q = trim((string)($_GET['q'] ?? ''));
    $args = [];
    $w = '';
    if ($q !== '') { $w = 'WHERE t.name LIKE ?'; $args[] = "%$q%"; }
    $st = db()->prepare("SELECT t.*, (SELECT COUNT(*) FROM team_members m WHERE m.team_id = t.id) AS n_members,
                                (SELECT COUNT(*) FROM library l WHERE l.team_id = t.id) AS n_items,
                                (SELECT COUNT(*) FROM team_models tm WHERE tm.team_id = t.id) AS n_models,
                                (SELECT MAX(a.created_at) FROM team_activity a WHERE a.team_id = t.id) AS last_activity
                         FROM teams t $w ORDER BY t.id DESC LIMIT 500");
    $st->execute($args);
    respond(['teams' => array_map(fn($t) => [
        'id' => (int)$t['id'], 'name' => $t['name'], 'description' => (string)($t['description'] ?? ''),
        'members' => (int)$t['n_members'], 'items' => (int)$t['n_items'], 'models' => (int)$t['n_models'],
        'created' => iso($t['created_at']), 'lastActivity' => iso($t['last_activity']),
    ], $st->fetchAll())]);

case 'team':
    $tid = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT u.id, u.name, u.email, m.role FROM team_members m JOIN users u ON u.id = m.user_id WHERE m.team_id = ? ORDER BY m.role, u.name');
    $st->execute([$tid]);
    respond(['members' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role']], $st->fetchAll())]);

case 'team_delete':
    requireMethod('POST');
    $tid = (int)(body()['team_id'] ?? 0);
    $st = db()->prepare('SELECT name FROM teams WHERE id = ?');
    $st->execute([$tid]);
    $name = $st->fetchColumn();
    if ($name === false) fail('Equipe não encontrada.', 404);
    deleteTeam($tid);
    adminLog($aid, 'team_delete', (string)$name);
    respond(['ok' => true]);

case 'settings':
    requireMethod('POST');
    $b = body();
    if (array_key_exists('allow_register', $b)) {
        $v = !empty($b['allow_register']) ? '1' : '0';
        setSetting('allow_register', $v);
        adminLog($aid, $v === '1' ? 'register_open' : 'register_close');
    }
    respond(['settings' => ['allow_register' => setting('allow_register', '1') === '1']]);

case 'log':
    $st = db()->query('SELECT a.*, u.name FROM admin_log a LEFT JOIN users u ON u.id = a.admin_id ORDER BY a.id DESC LIMIT 200');
    respond(['log' => array_map(fn($r) => ['action' => $r['action'], 'detail' => $r['detail'], 'who' => (string)$r['name'], 'date' => iso($r['created_at'])], $st->fetchAll())]);

case 'errors':
    respond(['errors' => readErrorLog(200)]);

case 'errors_clear':
    requireMethod('POST');
    @file_put_contents(ERR_LOG_FILE, '', LOCK_EX);
    respond(['ok' => true]);

default:
    fail('Ação desconhecida.', 404);
}
