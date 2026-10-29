<?php
/**
 * Erros do navegador vão para o registro do servidor (storage/errors.log)
 *   POST log.php?action=client {message, source, line, url, stack} → {ok}
 * Não exige login (a tela de entrada também pode dar erro), mas tem limite por endereço.
 */
require __DIR__ . '/bootstrap.php';

if (action() !== 'client') fail('Ação desconhecida.', 404);
requireMethod('POST');

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
if (tooManyAttempts('clientlog:' . $ip, 60, 600)) respond(['ok' => false, 'error' => 'Muitos registros seguidos.'], 429);
recordAttempt('clientlog:' . $ip);

$b = body();
$msg = mb_substr(trim((string)($b['message'] ?? '')), 0, 400);
if ($msg === '') fail('Mensagem vazia.');

logError('navegador', $msg, [
    'file'  => (string)($b['source'] ?? ''),
    'line'  => (string)($b['line'] ?? ''),
    'url'   => (string)($b['url'] ?? ''),
    'stack' => (string)($b['stack'] ?? ''),
]);
respond(['ok' => true]);
