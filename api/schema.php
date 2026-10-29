<?php
/**
 * Atualização automática do banco (MariaDB / MySQL).
 * Roda uma vez quando a versão guardada em app_settings('schema_version') é menor que SCHEMA_VERSION.
 * Cada passo é idempotente: pode rodar de novo sem estragar nada.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'schema.php') {
    http_response_code(404);
    exit;
}

const SCHEMA_VERSION = 7;

function ensureSchema(PDO $pdo): void {
    try {
        $st = $pdo->query("SELECT v FROM app_settings WHERE k = 'schema_version'");
        if ((int)$st->fetchColumn() >= SCHEMA_VERSION) return;
    } catch (PDOException $e) {
        // app_settings ainda não existe: segue migrando
    }
    migrateSchema($pdo);
}

function migrateSchema(PDO $pdo): void {
    global $CONFIG;
    $has = function (string $sql) use ($pdo): bool {
        try { $pdo->query($sql); return true; } catch (PDOException $e) { return false; }
    };
    $addCol = function (string $table, string $col, string $type) use ($pdo, $has): void {
        if (!$has("SELECT $col FROM $table LIMIT 0")) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
    };
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // Configurações gerais do sistema (chave → valor)
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT NOT NULL) $opts");

    // Usuários: administrador geral, bloqueio e último acesso
    $addCol('users', 'is_admin', 'TINYINT NOT NULL DEFAULT 0');
    $addCol('users', 'blocked', 'TINYINT NOT NULL DEFAULT 0');
    $addCol('users', 'last_login', 'DATETIME NULL');

    // Biblioteca: apresentação, título, nota, link público, equipe
    $addCol('library', 'presentation', 'MEDIUMTEXT NULL');
    $addCol('library', 'title', 'VARCHAR(120) NULL');
    $addCol('library', 'note', 'TEXT NULL');
    if (!$has('SELECT share_token FROM library LIMIT 0')) {
        $addCol('library', 'share_token', 'VARCHAR(64) NULL');
        $pdo->exec('CREATE UNIQUE INDEX ux_library_share ON library(share_token)');
    }
    if (!$has('SELECT team_id FROM library LIMIT 0')) {
        $addCol('library', 'team_id', 'INT UNSIGNED NULL');
        $pdo->exec('CREATE INDEX ix_library_team ON library(team_id)');
    }

    // Equipes
    $pdo->exec("CREATE TABLE IF NOT EXISTS teams (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL,
             join_code VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY ux_teams_code (join_code)) $opts");
    $addCol('teams', 'alerts_on', 'TINYINT NOT NULL DEFAULT 1');
    $addCol('teams', 'report_monthly', 'TINYINT NOT NULL DEFAULT 0');
    $addCol('teams', 'report_last', 'DATETIME NULL');
    $addCol('teams', 'description', 'VARCHAR(500) NULL');
    $addCol('teams', 'created_by', 'INT UNSIGNED NULL');

    // Membros: uma pessoa pode estar em várias equipes (versões antigas permitiam só uma)
    if (!$has('SELECT 1 FROM team_members LIMIT 0')) {
        $pdo->exec("CREATE TABLE team_members (team_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, role VARCHAR(10) NOT NULL,
                 created_at DATETIME NOT NULL, PRIMARY KEY (team_id, user_id), KEY ix_members_user (user_id),
                 CONSTRAINT fk_tm_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                 CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) $opts");
    } else {
        $idx = $pdo->query("SHOW INDEX FROM team_members WHERE Key_name = 'ux_members_user'")->fetch();
        if ($idx) {
            $pdo->exec('ALTER TABLE team_members ADD KEY ix_members_user (user_id)');
            $pdo->exec('ALTER TABLE team_members DROP INDEX ux_members_user');
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_models (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, team_id INT UNSIGNED NOT NULL,
             client_id VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '', fields_json TEXT NOT NULL,
             updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL, UNIQUE KEY ux_tmodels (team_id, client_id),
             CONSTRAINT fk_tmodels_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE) $opts");

    // Convites por e-mail
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_invites (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, team_id INT UNSIGNED NOT NULL,
             email VARCHAR(190) NOT NULL, role VARCHAR(10) NOT NULL, invited_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
             UNIQUE KEY ux_invites (team_id, email), KEY ix_invites_email (email),
             CONSTRAINT fk_invites_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE) $opts");

    // Comentários e atividade
    $pdo->exec("CREATE TABLE IF NOT EXISTS library_comments (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             item_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL,
             KEY ix_comments_item (item_id, id),
             CONSTRAINT fk_comments_item FOREIGN KEY (item_id) REFERENCES library(id) ON DELETE CASCADE,
             CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_activity (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, team_id INT UNSIGNED NOT NULL,
             user_id INT UNSIGNED NULL, action VARCHAR(40) NOT NULL, detail VARCHAR(255) NOT NULL DEFAULT '', ref_id INT UNSIGNED NULL,
             created_at DATETIME NOT NULL, KEY ix_activity_team (team_id, id),
             CONSTRAINT fk_activity_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE) $opts");

    // Registro das ações dos administradores gerais
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_log (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, admin_id INT UNSIGNED NULL,
             action VARCHAR(40) NOT NULL, detail VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY ix_admin_log (id)) $opts");

    // CRM de planilhas: cada linha importada vira um registro com status, responsável,
    // próximo contato, etiquetas e histórico de anotações
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_sheets (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
             team_id INT UNSIGNED NULL, name VARCHAR(120) NOT NULL, columns_json TEXT NOT NULL, title_col INT UNSIGNED NOT NULL DEFAULT 0,
             stages_json TEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
             KEY ix_crm_sheets_user (user_id), KEY ix_crm_sheets_team (team_id),
             CONSTRAINT fk_crm_sheets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_rows (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sheet_id INT UNSIGNED NOT NULL,
             pos INT UNSIGNED NOT NULL DEFAULT 0, data_json MEDIUMTEXT NOT NULL, status VARCHAR(40) NOT NULL DEFAULT '', due_date DATE NULL,
             tags VARCHAR(255) NOT NULL DEFAULT '', assignee_id INT UNSIGNED NULL, updated_at DATETIME NOT NULL,
             KEY ix_crm_rows_sheet (sheet_id, pos),
             CONSTRAINT fk_crm_rows_sheet FOREIGN KEY (sheet_id) REFERENCES crm_sheets(id) ON DELETE CASCADE,
             CONSTRAINT fk_crm_rows_assignee FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_notes (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, row_id INT UNSIGNED NOT NULL,
             user_id INT UNSIGNED NULL, kind VARCHAR(20) NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL,
             KEY ix_crm_notes_row (row_id, id),
             CONSTRAINT fk_crm_notes_row FOREIGN KEY (row_id) REFERENCES crm_rows(id) ON DELETE CASCADE,
             CONSTRAINT fk_crm_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL) $opts");
    // Preferências de cada pessoa (aparência da Cápsula, guardada na conta)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_prefs (user_id INT UNSIGNED NOT NULL PRIMARY KEY, capsule TEXT NULL, updated_at DATETIME NULL,
             CONSTRAINT fk_user_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) $opts");
    // Lembretes do CRM por e-mail: ligado/desligado e o dia do último envio
    $addCol('user_prefs', 'crm_mail', 'TINYINT NOT NULL DEFAULT 1');
    $addCol('user_prefs', 'crm_mail_last', 'DATE NULL');

    // Primeiro administrador: e-mails de config['admin_emails'] ou, se não houver nenhum, o usuário mais antigo
    foreach ((array)($CONFIG['admin_emails'] ?? []) as $email) {
        $pdo->prepare('UPDATE users SET is_admin = 1 WHERE email = ?')->execute([mb_strtolower(trim((string)$email))]);
    }
    if (!(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn()) {
        $first = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($first) $pdo->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$first]);
    }

    $pdo->prepare('REPLACE INTO app_settings (k, v) VALUES (?, ?)')
        ->execute(['schema_version', (string)SCHEMA_VERSION]);
}

/** Lê/grava uma configuração do sistema. */
function setting(string $k, ?string $default = null): ?string {
    $st = db()->prepare('SELECT v FROM app_settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function setSetting(string $k, string $v): void {
    db()->prepare('REPLACE INTO app_settings (k, v) VALUES (?, ?)')
        ->execute([$k, $v]);
}
