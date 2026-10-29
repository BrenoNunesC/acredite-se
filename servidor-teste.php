<?php
/**
 * Roteador do servidor de teste do PHP (php -S), que ignora os .htaccess.
 * Bloqueia o mesmo que os .htaccess bloqueiam na hospedagem: banco, pasta storage,
 * arquivos internos da API, documentação e pastas ocultas (.git, .env…).
 * Uso: veja iniciar-teste.ps1. Não é usado na hospedagem (Apache).
 */
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$blocked = '#(^|/)\.'                                                   // .git, .env, .htaccess…
         . '|^/storage(/|$)'                                            // banco e imagens: só via api/library.php
         . '|\.(sql|sqlite|md|log|ps1|yml|example)$|^/Dockerfile$'       // como o FilesMatch do .htaccess
         . '|^/api/(config|config\.local|config\.local\.example|bootstrap|presentation|teamlib|schema)\.php$' // como o api/.htaccess
         . '|^/servidor-teste\.php$#i';
if (preg_match($blocked, $path) || strpos($path, '..') !== false) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado.';
    return true;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
return false; // o servidor do PHP entrega o arquivo ou roda o .php normalmente
