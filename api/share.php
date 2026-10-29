<?php
/**
 * Apresentação compartilhada por link (só leitura, sem login)
 *   GET share.php?t=TOKEN
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/presentation.php';

ensureLibraryColumns();

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $token)) fail('Link inválido.', 404);

$st = db()->prepare('SELECT * FROM library WHERE share_token = ?');
$st->execute([$token]);
$r = $st->fetch();
$pres = $r && $r['presentation'] ? json_decode($r['presentation'], true) : null;
if (!is_array($pres)) fail('Este link não existe ou foi desativado.', 404);

respond([
    'title'        => (string)($r['title'] ?: $r['source_file']),
    'date'         => str_replace(' ', 'T', $r['created_at']) . 'Z',
    'presentation' => $pres,
]);
