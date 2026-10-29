<?php
/**
 * Relatório mensal por e-mail das equipes que ativaram a opção (Configurações → Equipe)
 * e lembrete diário do CRM (próximos contatos de hoje e atrasados) para cada pessoa.
 * Agende na hospedagem para rodar uma vez por dia — cada equipe recebe no máximo um por mês:
 *   linha de comando:  php /caminho/do/site/api/cron.php
 *   ou por endereço:   https://seusite.com.br/api/cron.php?key=SUA_CHAVE
 * Para usar o endereço, defina 'cron_key' em api/config.php (veja LEIA-ME.md).
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/teamlib.php';

if (PHP_SAPI !== 'cli') {
    $key = (string)($CONFIG['cron_key'] ?? '');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) fail('Não autorizado.', 403);
}
ensureTeamSchema();

$month = gmdate('Y-m');
$teams = db()->query('SELECT * FROM teams WHERE report_monthly = 1')->fetchAll();
$done = [];
foreach ($teams as $t) {
    if ($t['report_last'] && substr((string)$t['report_last'], 0, 7) === $month) continue; // já enviado neste mês
    $t['id'] = (int)$t['id'];
    $rep = buildTeamReport($t);
    $sent = 0;
    if ($rep) foreach (teamEmails($t['id']) as $email) $sent += sendAppMail($email, $rep['subject'], $rep['body']) ? 1 : 0;
    db()->prepare('UPDATE teams SET report_last = ? WHERE id = ?')->execute([now(), $t['id']]);
    logActivity($t['id'], null, 'report_sent', (string)$sent);
    $done[] = ['team' => $t['name'], 'sent' => $sent];
}
// Lembrete diário do CRM: quem tem próximo contato hoje ou atrasado recebe um e-mail por dia (dá para desligar no CRM)
$today = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$cand = db()->prepare('SELECT DISTINCT COALESCE(r.assignee_id, s.user_id) FROM crm_rows r JOIN crm_sheets s ON s.id = r.sheet_id
                       WHERE r.due_date IS NOT NULL AND r.due_date <= ?');
$cand->execute([$today]);
$reminded = 0;
foreach ($cand->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $u = db()->prepare('SELECT u.id, u.name, u.email, u.blocked, p.crm_mail, p.crm_mail_last FROM users u
                        LEFT JOIN user_prefs p ON p.user_id = u.id WHERE u.id = ?');
    $u->execute([(int)$id]);
    $u = $u->fetch();
    if (!$u || (int)$u['blocked'] || ($u['crm_mail'] !== null && !(int)$u['crm_mail'])) continue;
    if ($u['crm_mail_last'] && substr((string)$u['crm_mail_last'], 0, 10) === $today) continue; // já recebeu hoje
    $items = crmReminders((int)$u['id'], $today, 50);
    if (!$items) continue;
    $late = count(array_filter($items, fn($i) => $i['late']));
    $lines = ["Olá, {$u['name']}!", '', 'Contatos do CRM para hoje' . ($late ? " e $late atrasado(s)" : '') . ':', ''];
    foreach ($items as $i) {
        $d = implode('/', array_reverse(explode('-', $i['due'])));
        $lines[] = ($i['late'] ? "✖ Atrasado ($d)" : '● Hoje') . " — {$i['title']} · {$i['sheetName']} · {$i['status']}";
    }
    $lines[] = '';
    $lines[] = 'Abra o CRM: ' . appUrl();
    $lines[] = '';
    $lines[] = 'Para não receber mais este lembrete, desligue a opção "Lembrete por e-mail" no CRM de planilhas.';
    $subject = 'Lembretes do CRM: ' . count($items) . ' contato(s)' . ($late ? " ($late atrasado(s))" : '');
    if (sendAppMail($u['email'], $subject, implode("\n", $lines))) $reminded++;
    db()->prepare('INSERT INTO user_prefs (user_id, crm_mail_last, updated_at) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE crm_mail_last = VALUES(crm_mail_last)')->execute([(int)$u['id'], $today, now()]);
}

setSetting('cron_last', now());
respond(['month' => $month, 'teams' => $done, 'crmReminders' => $reminded]);
