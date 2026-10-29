<?php
/**
 * Biblioteca de apresentações de infográficos (por usuário)
 *   GET  library.php?action=list
 *   GET  library.php?action=image&id=N      → PNG de itens antigos (só para o dono)
 *   POST library.php?action=save            {presentation:{model,total,fields:[…],filters:[…]}, file}
 *   POST library.php?action=update          {id, title, note}
 *   POST library.php?action=share           {id}  → cria (ou devolve) o link público
 *   POST library.php?action=unshare         {id}  → desativa o link público
 *   POST library.php?action=delete          {id}
 *   POST library.php?action=team            {id, team_id|null}  → compartilha o item com uma equipe (null = tira)
 *   POST library.php?action=clear           → apaga só os itens do próprio usuário
 *   GET  library.php?action=comments&id=N   → comentários do item
 *   POST library.php?action=comment         {id, body}
 *   POST library.php?action=uncomment       {comment_id}  → autor do comentário, dono do item ou admin da equipe
 *
 * Itens da equipe: todos os membros veem; editor/admin editam e criam link; só o dono ou um admin exclui.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/presentation.php';
require __DIR__ . '/teamlib.php';
$user = requireUser();
$uid  = (int)$user['id'];
$dir  = rtrim($CONFIG['storage_dir'], '/\\') . '/infograficos';

$teams = userTeams($uid); // [team_id => equipe com o papel do usuário]
// Filtro SQL "itens que posso ver": meus + das minhas equipes
$teamIds = array_keys($teams) ?: [0];
$VISIBLE = '(l.user_id = ? OR l.team_id IN (' . implode(',', array_fill(0, count($teamIds), '?')) . '))';
function visibleArgs(): array { global $uid, $teamIds; return [$uid, ...$teamIds]; }

function imagePath(string $dir, string $filename): string {
    return $dir . '/' . basename($filename);
}

/** Equipe (minha) a que o item pertence, ou null. */
function itemTeam(array $r): ?array {
    global $teams;
    return $r['team_id'] !== null ? ($teams[(int)$r['team_id']] ?? null) : null;
}
function inMyTeam(array $r): bool { return itemTeam($r) !== null; }
function canEditItem(array $r): bool {
    global $uid;
    return (int)$r['user_id'] === $uid || teamCanEdit(itemTeam($r));
}
function canDeleteItem(array $r): bool {
    global $uid;
    $t = itemTeam($r);
    return (int)$r['user_id'] === $uid || ($t && $t['role'] === 'admin');
}

function rowOut(array $r): array {
    global $uid;
    $pres = $r['presentation'] ? json_decode($r['presentation'], true) : null;
    return [
        'id'           => (int)$r['id'],
        'model'        => $r['model_name'],
        'file'         => $r['source_file'],
        'records'      => (int)$r['records'],
        'date'         => str_replace(' ', 'T', $r['created_at']) . 'Z',
        'title'        => (string)($r['title'] ?? ''),
        'note'         => (string)($r['note'] ?? ''),
        'share'        => $r['share_token'] ?: null,
        'presentation' => is_array($pres) ? $pres : null,
        'thumb'        => $r['image_file'] !== '' ? 'api/library.php?action=image&id=' . (int)$r['id'] : null,
        'mine'         => (int)$r['user_id'] === $uid,
        'owner'        => (string)($r['owner_name'] ?? ''),
        'team'         => inMyTeam($r) ? (int)$r['team_id'] : null,
        'teamName'     => inMyTeam($r) ? itemTeam($r)['name'] : null,
        'canEdit'      => canEditItem($r),
        'canDelete'    => canDeleteItem($r),
        'comments'     => (int)($r['ncomments'] ?? 0),
    ];
}
const ITEM_SELECT = 'SELECT l.*, u.name AS owner_name, (SELECT COUNT(*) FROM library_comments c WHERE c.item_id = l.id) AS ncomments
                     FROM library l JOIN users u ON u.id = l.user_id';
function itemTitle(array $r): string { return (string)($r['title'] ?: $r['source_file']); }
/** Registra na atividade da equipe quando a ação é sobre um item da equipe. */
function logItem(array $r, string $action, string $detail = ''): void {
    global $uid;
    if ($t = itemTeam($r)) logActivity($t['id'], $uid, $action, $detail !== '' ? $detail : itemTitle($r), (int)$r['id']);
}

/** Busca um item que o usuário pode ver: dele ou de uma equipe dele (senão 404). */
function itemRow(int $id): array {
    global $VISIBLE;
    $st = db()->prepare(ITEM_SELECT . " WHERE l.id = ? AND $VISIBLE");
    $st->execute([$id, ...visibleArgs()]);
    $row = $st->fetch();
    if (!$row) fail('Item não encontrado.', 404);
    return $row;
}
function editableRow(int $id): array {
    $row = itemRow($id);
    if (!canEditItem($row)) fail('Você só pode ver este item da equipe.', 403);
    return $row;
}

switch (action()) {

case 'list':
    $st = db()->prepare(ITEM_SELECT . " WHERE $VISIBLE ORDER BY l.id DESC");
    $st->execute(visibleArgs());
    respond(['items' => array_map('rowOut', $st->fetchAll()),
             'teams' => array_values(array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'role' => $t['role']], $teams))]);

case 'image':
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare("SELECT l.image_file FROM library l WHERE l.id = ? AND $VISIBLE");
    $st->execute([$id, ...visibleArgs()]);
    $file = $st->fetchColumn();
    $path = $file ? imagePath($dir, (string)$file) : '';
    if (!$file || !is_file($path)) fail('Imagem não encontrada.', 404);
    header_remove('Cache-Control');
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;

case 'save':
    requireMethod('POST');
    $b = body();
    $pres = cleanPresentation($b['presentation'] ?? null);
    if (!$pres || !$pres['fields']) fail('Apresentação inválida.');
    $json = json_encode($pres, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > MAX_PRES_BYTES) fail('Apresentação muito grande.', 413);

    $pdo = db();
    $pdo->prepare('INSERT INTO library (user_id, model_name, source_file, records, image_file, presentation, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$uid, $pres['model'], str($b, 'file', 255) ?: 'Sem título',
                   $pres['total'], '', $json, now()]);
    $newId = (int)$pdo->lastInsertId();

    // Mantém só os N mais recentes
    $st = $pdo->prepare('SELECT id, image_file FROM library WHERE user_id = ? ORDER BY id DESC');
    $st->execute([$uid]);
    $all = $st->fetchAll();
    foreach (array_slice($all, (int)$CONFIG['max_library_items']) as $old) {
        if ($old['image_file'] !== '') @unlink(imagePath($dir, $old['image_file']));
        $pdo->prepare('DELETE FROM library WHERE id = ?')->execute([$old['id']]);
    }

    respond(['item' => rowOut(itemRow($newId))], 201);

case 'update':
    requireMethod('POST');
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    $row = editableRow($id);
    db()->prepare('UPDATE library SET title = ?, note = ? WHERE id = ?')
        ->execute([str($b, 'title', 120), str($b, 'note', 1000), $id]);
    $new = itemRow($id);
    if (itemTitle($new) !== itemTitle($row)) logItem($new, 'item_rename', itemTitle($row) . '|' . itemTitle($new));
    respond(['item' => rowOut($new)]);

case 'share':
    requireMethod('POST');
    $id  = (int)(body()['id'] ?? 0);
    $row = editableRow($id);
    if (!$row['presentation']) fail('Itens antigos (só imagem) não podem ser compartilhados.');
    if (!$row['share_token']) {
        db()->prepare('UPDATE library SET share_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(16)), $id]);
        logItem($row, 'item_link');
    }
    respond(['item' => rowOut(itemRow($id))]);

case 'unshare':
    requireMethod('POST');
    $id = (int)(body()['id'] ?? 0);
    $row = editableRow($id);
    db()->prepare('UPDATE library SET share_token = NULL WHERE id = ?')->execute([$id]);
    if ($row['share_token']) logItem($row, 'item_unlink');
    respond(['item' => rowOut(itemRow($id))]);

case 'team':
    requireMethod('POST');
    $b  = body();
    $id = (int)($b['id'] ?? 0);
    $row = itemRow($id);
    // Equipe de destino: team_id (null = tirar da equipe). Compatível com {on:true} quando a pessoa tem uma só equipe.
    if (array_key_exists('team_id', $b)) $target = $b['team_id'] === null ? null : (int)$b['team_id'];
    else $target = !empty($b['on']) ? (count($teams) === 1 ? array_key_first($teams) : -1) : null;
    if ($target === -1) fail('Escolha com qual equipe compartilhar.');
    $current = itemTeam($row);
    $owner = (int)$row['user_id'] === $uid;
    if ($current && ($target === null || $target !== $current['id']) && !$owner && $current['role'] !== 'admin') {
        fail('Só o dono do item ou um administrador da equipe pode tirá-lo da equipe.', 403);
    }
    if ($target !== null) {
        $t = $teams[$target] ?? null;
        if (!$t) fail('Equipe não encontrada.', 404);
        if (!teamCanEdit($t)) fail('Leitores não podem compartilhar itens com a equipe.', 403);
        if (!$owner && (!$current || $current['id'] !== $target)) fail('Só o dono do item pode compartilhá-lo com outra equipe.', 403);
    }
    db()->prepare('UPDATE library SET team_id = ? WHERE id = ?')->execute([$target, $id]);
    if ($current && $current['id'] !== $target) logActivity($current['id'], $uid, 'item_unshare', itemTitle($row), $id);
    if ($target !== null && (!$current || $current['id'] !== $target)) logActivity($target, $uid, 'item_share', itemTitle($row), $id);
    respond(['item' => rowOut(itemRow($id))]);

case 'comments':
    $row = itemRow((int)($_GET['id'] ?? 0));
    $st = db()->prepare('SELECT c.id, c.user_id, c.body, c.created_at, u.name FROM library_comments c JOIN users u ON u.id = c.user_id
                         WHERE c.item_id = ? ORDER BY c.id');
    $st->execute([$row['id']]);
    $isAdmin = inMyTeam($row) && itemTeam($row)['role'] === 'admin';
    $owner = (int)$row['user_id'] === $uid;
    respond(['comments' => array_map(fn($c) => [
        'id' => (int)$c['id'], 'name' => $c['name'], 'body' => $c['body'],
        'date' => str_replace(' ', 'T', $c['created_at']) . 'Z',
        'mine' => (int)$c['user_id'] === $uid,
        'canDelete' => (int)$c['user_id'] === $uid || $owner || $isAdmin,
    ], $st->fetchAll())]);

case 'comment':
    requireMethod('POST');
    $b = body();
    $row = itemRow((int)($b['id'] ?? 0));
    $text = trim((string)($b['body'] ?? ''));
    if ($text === '') fail('Escreva o comentário.');
    if (mb_strlen($text) > 1000) fail('Comentário muito longo (máximo 1000 caracteres).');
    $st = db()->prepare('SELECT COUNT(*) FROM library_comments WHERE item_id = ?');
    $st->execute([$row['id']]);
    if ((int)$st->fetchColumn() >= 500) fail('Limite de comentários deste item atingido.');
    db()->prepare('INSERT INTO library_comments (item_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$row['id'], $uid, $text, now()]);
    logItem($row, 'item_comment', itemTitle($row) . '|' . mb_substr($text, 0, 120));
    respond(['ok' => true, 'item' => rowOut(itemRow((int)$row['id']))], 201);

case 'uncomment':
    requireMethod('POST');
    $cid = (int)(body()['comment_id'] ?? 0);
    $st = db()->prepare('SELECT * FROM library_comments WHERE id = ?');
    $st->execute([$cid]);
    $c = $st->fetch();
    if (!$c) fail('Comentário não encontrado.', 404);
    $row = itemRow((int)$c['item_id']); // garante que a pessoa vê o item
    $allowed = (int)$c['user_id'] === $uid || (int)$row['user_id'] === $uid || (inMyTeam($row) && itemTeam($row)['role'] === 'admin');
    if (!$allowed) fail('Você só pode apagar os seus comentários.', 403);
    db()->prepare('DELETE FROM library_comments WHERE id = ?')->execute([$cid]);
    respond(['ok' => true, 'item' => rowOut(itemRow((int)$row['id']))]);

case 'delete':
    requireMethod('POST');
    $id  = (int)(body()['id'] ?? 0);
    $row = itemRow($id);
    if (!canDeleteItem($row)) fail('Só o dono do item ou um administrador da equipe pode excluí-lo.', 403);
    logItem($row, 'item_delete');
    if ($row['image_file'] !== '') @unlink(imagePath($dir, (string)$row['image_file']));
    db()->prepare('DELETE FROM library WHERE id = ?')->execute([$id]);
    respond(['ok' => true]);

case 'clear':
    requireMethod('POST');
    $st = db()->prepare('SELECT image_file FROM library WHERE user_id = ?');
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) if ($f !== '') @unlink(imagePath($dir, (string)$f));
    db()->prepare('DELETE FROM library WHERE user_id = ?')->execute([$uid]);
    respond(['ok' => true]);

default:
    fail('Ação desconhecida.', 404);
}
