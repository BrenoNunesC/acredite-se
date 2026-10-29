<?php
/**
 * Funções compartilhadas por todos os endpoints da API.
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'bootstrap.php') {
    http_response_code(404);
    exit;
}

$CONFIG = require __DIR__ . '/config.php';
require __DIR__ . '/schema.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/* ── Registro de erros: storage/errors.log, uma linha JSON por erro ── */
const ERR_LOG_FILE = __DIR__ . '/../storage/errors.log';
const ERR_LOG_KEEP = 500;

/** Grava um erro no registro. Dados sensíveis não entram: só os campos da lista. */
function logError(string $source, string $message, array $ctx = []): void {
    $keep = array_intersect_key($ctx, array_flip(['file', 'line', 'route', 'url', 'stack']));
    $row = ['date' => gmdate('Y-m-d H:i:s'), 'source' => $source,
        'message' => mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '', 0, 400)];
    foreach ($keep as $k => $v) $row[$k] = mb_substr((string)$v, 0, 800);
    @file_put_contents(ERR_LOG_FILE, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    // Mantém só as últimas linhas quando o arquivo passa de 1 MB
    if (@filesize(ERR_LOG_FILE) > 1048576) {
        $lines = file(ERR_LOG_FILE, FILE_IGNORE_NEW_LINES) ?: [];
        @file_put_contents(ERR_LOG_FILE, implode("\n", array_slice($lines, -ERR_LOG_KEEP)) . "\n", LOCK_EX);
    }
}

/** Últimos erros, do mais novo para o mais antigo. */
function readErrorLog(int $max): array {
    if (!is_file(ERR_LOG_FILE)) return [];
    $lines = array_slice(file(ERR_LOG_FILE, FILE_IGNORE_NEW_LINES) ?: [], -$max);
    $out = [];
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) $out[] = $row;
    }
    return $out;
}

set_exception_handler(function (Throwable $e) {
    error_log('[acredite] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    logError('servidor', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(), 'route' => (string)($_GET['action'] ?? '')]);
    respond(['error' => 'Erro interno no servidor.'], 500);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        logError('servidor', $err['message'], ['file' => $err['file'], 'line' => $err['line'], 'route' => (string)($_GET['action'] ?? '')]);
    }
});

/* ── Proteção CSRF ───────────────────────────────────────────────
   Toda requisição que altera dados precisa ser JSON. Formulários HTML
   de outros sites não conseguem enviar JSON sem permissão (CORS). */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
    && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    http_response_code(415);
    echo json_encode(['error' => 'Content-Type inválido.']);
    exit;
}

/* ── Sessão ─────────────────────────────────────────────────────── */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('ACREDITESESS');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', (string)$CONFIG['session_lifetime']);
ini_set('session.use_strict_mode', '1');
session_start();

if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $CONFIG['session_lifetime']) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_seen'] = time();

/* ── Banco de dados ─────────────────────────────────────────────── */
function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo) return $pdo;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $CONFIG['db_host'], $CONFIG['db_port'], $CONFIG['db_name']);
    $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], $opts);
    // Banco vazio (primeira execução): cria as tabelas básicas de database.sql
    if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn() === false) {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../database.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
    }
    ensureSchema($pdo);
    return $pdo;
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

/* ── Requisição / resposta ──────────────────────────────────────── */
function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $status = 400): void {
    // Erros do servidor (500 e 502) vão para o registro; 503 é "IA desligada", esperado
    if ($status >= 500 && $status !== 503) logError('servidor', $msg, ['route' => (string)($_GET['action'] ?? '')]);
    respond(['error' => $msg], $status);
}

/** Lê o corpo JSON. Exige Content-Type JSON em POST (bloqueia formulários de outros sites). */
function body(): array {
    static $data = null;
    if ($data !== null) return $data;
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') === false) fail('Content-Type inválido.', 415);
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($data)) fail('JSON inválido.');
    return $data;
}

function action(): string {
    return (string)($_GET['action'] ?? '');
}

function requireMethod(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== $method) fail('Método não permitido.', 405);
}

function str(array $src, string $key, int $max = 255): string {
    $v = trim((string)($src[$key] ?? ''));
    return mb_substr($v, 0, $max);
}

/* ── Autenticação ───────────────────────────────────────────────── */
function currentUser(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) return $user = null;
    $st = db()->prepare('SELECT id, name, email, created_at, is_admin, blocked FROM users WHERE id = ?');
    $st->execute([$id]);
    $user = $st->fetch() ?: null;
    // Conta bloqueada por um administrador: encerra a sessão na hora
    if ($user && (int)$user['blocked']) {
        $_SESSION = [];
        session_destroy();
        $user = null;
    }
    return $user;
}

function requireUser(): array {
    $u = currentUser();
    if (!$u) fail('Sessão expirada. Faça login novamente.', 401);
    return $u;
}

/** Exige administrador geral do sistema. */
function requireSysAdmin(): array {
    $u = requireUser();
    if (!(int)($u['is_admin'] ?? 0)) fail('Área restrita aos administradores do sistema.', 403);
    return $u;
}

function publicUser(array $u): array {
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'isAdmin' => (bool)(int)($u['is_admin'] ?? 0)];
}

/** Registro das ações dos administradores gerais. */
function adminLog(int $adminId, string $action, string $detail = ''): void {
    db()->prepare('INSERT INTO admin_log (admin_id, action, detail, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$adminId, $action, mb_substr($detail, 0, 255), now()]);
}

function clientIp(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Limita tentativas: no máximo $max falhas em $window segundos por chave. */
function tooManyAttempts(string $key, int $max = 8, int $window = 900): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ? AND created_at > ?');
    $st->execute([$key, gmdate('Y-m-d H:i:s', time() - $window)]);
    return (int)$st->fetchColumn() >= $max;
}

function recordAttempt(string $key): void {
    db()->prepare('INSERT INTO login_attempts (attempt_key, created_at) VALUES (?, ?)')
        ->execute([$key, now()]);
    // Limpeza ocasional
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM login_attempts WHERE created_at < ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);
    }
}

function clearAttempts(string $key): void {
    db()->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')->execute([$key]);
}
