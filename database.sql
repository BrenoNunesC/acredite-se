-- Acredite.se — estrutura do banco (MySQL 5.7+ / MariaDB 10.3+)
-- Crie o banco antes:  CREATE DATABASE acredite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Importar é opcional: se o banco estiver vazio, o sistema roda este arquivo sozinho no primeiro acesso.
-- Para importar à mão: phpMyAdmin (aba Importar) ou  mysql -u USUARIO -p acredite < database.sql
-- Aqui estão só as tabelas básicas. Equipes, convites, comentários, atividade, administração e
-- colunas novas são criadas automaticamente por api/schema.php no primeiro acesso ao sistema.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS models (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  client_id   VARCHAR(64)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  fields_json TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uq_models_user_client (user_id, client_id),
  CONSTRAINT fk_models_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS library (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  model_name  VARCHAR(120) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  records     INT UNSIGNED NOT NULL DEFAULT 0,
  image_file  VARCHAR(80)  NOT NULL DEFAULT '',
  presentation MEDIUMTEXT  NULL,
  title       VARCHAR(120) NULL,
  note        TEXT         NULL,
  share_token VARCHAR(64)  NULL,
  team_id     INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  KEY ix_library_user (user_id, id),
  KEY ix_library_team (team_id),
  UNIQUE KEY ux_library_share (share_token),
  CONSTRAINT fk_library_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  code_hash  VARCHAR(255) NOT NULL,
  expires_at DATETIME     NOT NULL,
  tries      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL,
  KEY ix_resets_user (user_id),
  CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_key VARCHAR(255) NOT NULL,
  created_at  DATETIME     NOT NULL,
  KEY ix_attempts_key (attempt_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
