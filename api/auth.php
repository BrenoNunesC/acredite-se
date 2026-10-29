<?php
/**
 * Autenticação
 *   GET  auth.php?action=me              → usuário logado (ou null)
 *   POST auth.php?action=register        {name, email, password}
 *   POST auth.php?action=login           {email, password}
 *   POST auth.php?action=logout
 *   POST auth.php?action=forgot          {email}               → envia código por e-mail
 *   POST auth.php?action=reset           {email, code, password}
 *   POST auth.php?action=update_profile  {name}
 *   POST auth.php?action=change_password {current, password}
 */
require __DIR__ . '/bootstrap.php';

function validEmail(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }
function validPassword(string $p): bool { return mb_strlen($p) >= 6 && mb_strlen($p) <= 200; }

switch (action()) {

case 'me':
    $u = currentUser();
    respond(['user' => $u ? publicUser($u) : null]);

case 'register':
    requireMethod('POST');
    $b = body();
    $name  = str($b, 'name', 120);
    $email = mb_strtolower(str($b, 'email', 190));
    $pass  = (string)($b['password'] ?? '');
    if (setting('allow_register', '1') !== '1') {
        // Cadastro fechado pelo administrador — exceto para quem tem convite de equipe
        $st = db()->prepare('SELECT 1 FROM team_invites WHERE email = ?');
        $st->execute([$email]);
        if (!$st->fetchColumn()) fail('Novos cadastros estão fechados. Peça um convite a um administrador.', 403);
    }
    if ($name === '')          fail('Informe seu nome.');
    if (!validEmail($email))   fail('E-mail inválido.');
    if (!validPassword($pass)) fail('A senha deve ter pelo menos 6 caracteres.');

    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) fail('Este e-mail já está cadastrado.', 409);

    // Sistema sem administrador (instalação nova): a primeira conta vira administradora
    $isAdmin = (int)db()->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() === 0 ? 1 : 0;
    db()->prepare('INSERT INTO users (name, email, password_hash, created_at, is_admin) VALUES (?, ?, ?, ?, ?)')
        ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), now(), $isAdmin]);
    respond(['ok' => true], 201);

case 'login':
    requireMethod('POST');
    $b = body();
    $email = mb_strtolower(str($b, 'email', 190));
    $pass  = (string)($b['password'] ?? '');
    if ($email === '' || $pass === '') fail('Preencha e-mail e senha.');

    $key = 'login:' . $email . '|' . clientIp();
    if (tooManyAttempts($key)) fail('Muitas tentativas. Aguarde 15 minutos e tente de novo.', 429);

    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        recordAttempt($key);
        fail('E-mail ou senha incorretos.', 401);
    }
    clearAttempts($key);
    if ((int)$u['blocked']) fail('Esta conta foi bloqueada. Fale com o administrador do sistema.', 403);
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $u['id']]);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    respond(['user' => publicUser($u)]);

case 'logout':
    requireMethod('POST');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    respond(['ok' => true]);

case 'forgot':
    requireMethod('POST');
    $b = body();
    $email = mb_strtolower(str($b, 'email', 190));
    if (!validEmail($email)) fail('Informe um e-mail válido.');

    $key = 'forgot:' . clientIp();
    if (tooManyAttempts($key, 5, 900)) fail('Muitas solicitações. Aguarde alguns minutos.', 429);
    recordAttempt($key);

    $st = db()->prepare('SELECT id, name FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    $resp = ['ok' => true, 'message' => 'Se o e-mail estiver cadastrado, enviamos um código de 6 dígitos.'];
    if ($u) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$u['id']]);
        db()->prepare('INSERT INTO password_resets (user_id, code_hash, expires_at, tries, created_at) VALUES (?, ?, ?, 0, ?)')
            ->execute([$u['id'], password_hash($code, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() + 900), now()]);

        $subject = '=?UTF-8?B?' . base64_encode($CONFIG['app_name'] . ' — código para redefinir sua senha') . '?=';
        $msg = "Olá, {$u['name']}.\r\n\r\nSeu código para redefinir a senha é: {$code}\r\n\r\n"
             . "Ele vale por 15 minutos. Se você não pediu isso, ignore este e-mail.\r\n";
        $headers = "From: {$CONFIG['app_name']} <{$CONFIG['mail_from']}>\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = @mail($email, $subject, $msg, $headers);
        if (!$sent) error_log('[acredite] Falha ao enviar e-mail de recuperação para ' . $email);
        if ($CONFIG['debug']) $resp['debug_code'] = $code;
    }
    respond($resp);

case 'reset':
    requireMethod('POST');
    $b = body();
    $email = mb_strtolower(str($b, 'email', 190));
    $code  = preg_replace('/\D/', '', (string)($b['code'] ?? ''));
    $pass  = (string)($b['password'] ?? '');
    if (!validPassword($pass)) fail('A senha deve ter pelo menos 6 caracteres.');
    if (strlen($code) !== 6)   fail('Digite o código de 6 dígitos enviado por e-mail.');

    $st = db()->prepare('SELECT r.id, r.user_id, r.code_hash, r.expires_at, r.tries
                         FROM password_resets r JOIN users u ON u.id = r.user_id WHERE u.email = ?');
    $st->execute([$email]);
    $r = $st->fetch();
    if (!$r || $r['expires_at'] < now() || (int)$r['tries'] >= 5) {
        fail('Código expirado ou inválido. Peça um novo código.', 400);
    }
    if (!password_verify($code, $r['code_hash'])) {
        db()->prepare('UPDATE password_resets SET tries = tries + 1 WHERE id = ?')->execute([$r['id']]);
        fail('Código incorreto.', 400);
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $r['user_id']]);
    db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$r['user_id']]);
    respond(['ok' => true]);

case 'update_profile':
    requireMethod('POST');
    $u = requireUser();
    $name = str(body(), 'name', 120);
    if ($name === '') fail('Informe seu nome.');
    db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $u['id']]);
    respond(['user' => publicUser(['id' => $u['id'], 'name' => $name, 'email' => $u['email'], 'is_admin' => $u['is_admin']])]);

case 'change_password':
    requireMethod('POST');
    $u = requireUser();
    $b = body();
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($b['current'] ?? ''), (string)$st->fetchColumn())) fail('Senha atual incorreta.', 401);
    $pass = (string)($b['password'] ?? '');
    if (!validPassword($pass)) fail('A nova senha deve ter pelo menos 6 caracteres.');
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    respond(['ok' => true]);

default:
    fail('Ação desconhecida.', 404);
}
