<?php
/**
 * Equipes: funções comuns (usado por team.php, library.php, models.php, admin.php e cron.php).
 * Uma pessoa pode participar de várias equipes, com um papel em cada uma:
 *   admin  → gerencia membros, convites, opções e tudo da equipe
 *   editor → cria/edita modelos e itens da biblioteca da equipe
 *   leitor → só vê, comenta e apresenta o conteúdo da equipe
 * As tabelas são criadas/atualizadas por schema.php.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'teamlib.php') {
    http_response_code(404);
    exit;
}

const TEAM_ROLES = ['admin', 'editor', 'leitor'];
const MAX_TEAMS_PER_USER = 20;
const MAX_MEMBERS_PER_TEAM = 200;

/** Compatibilidade: o banco já é atualizado na conexão (schema.php). */
function ensureTeamSchema(): void {
    db();
}

/** Equipes do usuário, indexadas pelo id: [id => ['id','name','role','join_code',…]]. */
function userTeams(int $uid): array {
    $st = db()->prepare('SELECT t.*, m.role FROM team_members m JOIN teams t ON t.id = m.team_id WHERE m.user_id = ? ORDER BY t.name');
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $t) {
        $t['id'] = (int)$t['id'];
        $out[$t['id']] = $t;
    }
    return $out;
}

/** Participação do usuário numa equipe (com o papel) ou null. */
function teamMembership(int $uid, int $teamId): ?array {
    $st = db()->prepare('SELECT t.*, m.role FROM team_members m JOIN teams t ON t.id = m.team_id WHERE m.user_id = ? AND t.id = ?');
    $st->execute([$uid, $teamId]);
    $t = $st->fetch();
    if (!$t) return null;
    $t['id'] = (int)$t['id'];
    return $t;
}

function teamCanEdit(?array $team): bool {
    return $team !== null && in_array($team['role'], ['admin', 'editor'], true);
}

/** Registra uma ação no histórico de atividade da equipe. */
function logActivity(int $teamId, ?int $uid, string $action, string $detail = '', ?int $refId = null): void {
    db()->prepare('INSERT INTO team_activity (team_id, user_id, action, detail, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$teamId, $uid, $action, mb_substr($detail, 0, 255), $refId, now()]);
}

/** Apaga a equipe: itens e planilhas do CRM voltam a ser pessoais; modelos, convites e atividade da equipe são removidos. */
function deleteTeam(int $teamId): void {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE library SET team_id = NULL WHERE team_id = ?')->execute([$teamId]);
    $pdo->prepare('UPDATE crm_sheets SET team_id = NULL WHERE team_id = ?')->execute([$teamId]);
    foreach (['team_models', 'team_invites', 'team_activity', 'team_members'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE team_id = ?")->execute([$teamId]);
    }
    $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
    $pdo->commit();
}

/**
 * Envia e-mail de texto. Em modo debug não envia: grava em storage/outbox/ (para teste) e devolve true.
 */
function sendAppMail(string $to, string $subject, string $body): bool {
    global $CONFIG;
    if (!empty($CONFIG['debug'])) {
        $dir = rtrim($CONFIG['storage_dir'], '/\\') . '/outbox';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt';
        return file_put_contents($file, "Para: $to\nAssunto: $subject\n\n$body") !== false;
    }
    $headers = "From: {$CONFIG['app_name']} <{$CONFIG['mail_from']}>\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', str_replace("\n", "\r\n", $body), $headers);
    if (!$ok) error_log('[acredite] Falha ao enviar e-mail para ' . $to);
    return $ok;
}

/** Endereço do sistema para links nos e-mails (ex.: https://seusite.com.br/). */
function appUrl(): string {
    global $CONFIG;
    if (!empty($CONFIG['app_url'])) return rtrim($CONFIG['app_url'], '/') . '/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $path . '/';
}

/** Situação das metas guardadas numa apresentação (mesma regra da tela: amarelo a até 5 p.p.). */
function goalStates(array $pres): array {
    $total = (int)($pres['total'] ?? 0);
    $out = [];
    foreach ($pres['fields'] ?? [] as $f) {
        $g = $f['goal'] ?? null;
        if (!is_array($g) || !$total) continue;
        $count = 0;
        foreach ($f['counts'] ?? [] as $c) if ((string)$c[0] === (string)$g['cat']) $count = (int)$c[1];
        $pct = $count / $total * 100;
        $met = ($g['op'] ?? 'min') === 'max' ? $pct <= $g['val'] : $pct >= $g['val'];
        $state = $met ? 'ok' : (abs($pct - $g['val']) <= 5 ? 'warn' : 'bad');
        $sign = ($g['op'] ?? 'min') === 'max' ? '≤' : '≥';
        $out[] = ['field' => $f['name'], 'state' => $state, 'pct' => round($pct, 1),
                  'text' => "{$f['name']} — {$g['cat']} {$sign} {$g['val']}% (atual " . number_format($pct, 1, ',', '') . '%)'];
    }
    return $out;
}

/**
 * Relatório da equipe (texto do e-mail) com os itens compartilhados no último mês e as metas.
 * Devolve null quando não há nada para relatar.
 */
function buildTeamReport(array $team): ?array {
    $since = gmdate('Y-m-d H:i:s', time() - 31 * 86400);
    $st = db()->prepare('SELECT l.*, u.name AS owner_name FROM library l JOIN users u ON u.id = l.user_id
                         WHERE l.team_id = ? ORDER BY l.id DESC LIMIT 200');
    $st->execute([$team['id']]);
    $items = $st->fetchAll();
    // Último item de cada planilha de origem
    $latest = [];
    foreach ($items as $r) {
        $p = json_decode((string)$r['presentation'], true);
        if (!is_array($p)) continue;
        $key = $p['source'] ?? ('item' . $r['id']);
        if (!isset($latest[$key])) $latest[$key] = [$r, $p];
    }
    $recent = array_filter($items, fn($r) => $r['created_at'] >= $since);
    if (!$latest) return null;
    $mes = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'][(int)gmdate('n') - 1];
    $lines = ["Relatório mensal da equipe \"{$team['name']}\" — $mes de " . gmdate('Y'), '',
              count($recent) . ' item(ns) novo(s) na biblioteca da equipe nos últimos 30 dias.', ''];
    $bad = 0;
    foreach (array_slice($latest, 0, 10) as [$r, $p]) {
        $title = $r['title'] ?: $r['source_file'];
        $lines[] = "■ $title (modelo {$p['model']}, " . number_format((int)$p['total'], 0, ',', '.') . ' registros, por ' . $r['owner_name'] . ')';
        if (!empty($p['summary'])) $lines[] = "   Resumo: {$p['summary']}";
        foreach ($p['fields'] ?? [] as $f) {
            if (!empty($f['counts'])) {
                $top = $f['counts'][0];
                $lines[] = "   {$f['name']}: {$top[0]} " . number_format($top[1] / max(1, $p['total']) * 100, 1, ',', '') . '%';
            }
        }
        foreach (goalStates($p) as $g) {
            $icon = ['ok' => '✔ Meta atingida', 'warn' => '▲ Quase lá', 'bad' => '✖ Meta não atingida'][$g['state']];
            if ($g['state'] !== 'ok') $bad++;
            $lines[] = "   $icon: {$g['text']}";
        }
        $lines[] = '';
    }
    $lines[] = 'Abra o sistema para ver as apresentações: ' . appUrl();
    $lines[] = '';
    $lines[] = 'Você recebe este e-mail porque a equipe ativou o relatório mensal. Um administrador da equipe pode desativá-lo na tela Equipes.';
    $subject = "Relatório mensal — {$team['name']}" . ($bad ? " ($bad meta(s) fora do verde)" : '');
    return ['subject' => $subject, 'body' => implode("\n", $lines)];
}

/** E-mails de todos os membros (ou só dos administradores) da equipe. */
function teamEmails(int $teamId, bool $adminsOnly = false): array {
    $st = db()->prepare('SELECT u.email FROM team_members m JOIN users u ON u.id = m.user_id WHERE m.team_id = ? AND u.blocked = 0' . ($adminsOnly ? " AND m.role = 'admin'" : ''));
    $st->execute([$teamId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Lembretes do CRM de uma pessoa: linhas em aberto com próximo contato até hoje,
 * em que ela é a responsável (ou sem responsável, nas planilhas dela). Atrasadas primeiro.
 */
function crmReminders(int $uid, string $today, int $limit = 100): array {
    $teams = userTeams($uid);
    $st = db()->prepare('SELECT r.id, r.sheet_id, r.data_json, r.status, r.due_date, s.name AS sheet_name, s.user_id, s.team_id,
                                s.title_col, s.stages_json
                         FROM crm_rows r JOIN crm_sheets s ON s.id = r.sheet_id
                         WHERE r.due_date IS NOT NULL AND r.due_date <= ?
                           AND (r.assignee_id = ? OR (r.assignee_id IS NULL AND s.user_id = ?))
                         ORDER BY r.due_date, r.id LIMIT 1000');
    $st->execute([$today, $uid, $uid]);
    $out = [];
    $doneBySheet = [];
    foreach ($st->fetchAll() as $r) {
        // Planilha de equipe da qual a pessoa saiu: não lembra
        if ((int)$r['user_id'] !== $uid && !isset($teams[(int)$r['team_id']])) continue;
        $sid = (int)$r['sheet_id'];
        if (!isset($doneBySheet[$sid])) {
            $stages = json_decode((string)$r['stages_json'], true) ?: [];
            $doneBySheet[$sid] = array_column(array_filter($stages, fn($x) => !empty($x['done'])), 'name') ?: ['Concluído', 'Perdido'];
        }
        if (in_array($r['status'], $doneBySheet[$sid], true)) continue;
        $data = json_decode((string)$r['data_json'], true) ?: [];
        $due = substr((string)$r['due_date'], 0, 10);
        $out[] = [
            'id' => (int)$r['id'], 'sheet' => $sid, 'sheetName' => $r['sheet_name'],
            'title' => (string)($data[(int)$r['title_col']] ?? '') ?: '(sem nome)',
            'status' => $r['status'], 'due' => $due, 'late' => $due < $today,
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

function newJoinCode(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O e 1/I para não confundir
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $st = db()->prepare('SELECT 1 FROM teams WHERE join_code = ?');
        $st->execute([$code]);
    } while ($st->fetchColumn());
    return $code;
}
