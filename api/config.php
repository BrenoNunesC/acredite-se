<?php
/**
 * Configuração do Acredite.se
 *
 * NÃO coloque senhas nem chaves neste arquivo (ele vai para o GitHub).
 * Para configurar cada computador/servidor, use UMA das opções:
 *   1) Copie api/config.local.example.php para api/config.local.php e edite
 *      (config.local.php fica fora do Git).
 *   2) Defina variáveis de ambiente ACREDITE_* (é o que o docker-compose.yml faz).
 */
$env = function (string $key, $default) {
    $v = getenv('ACREDITE_' . $key);
    return ($v === false || $v === '') ? $default : $v;
};

$config = [
    // Banco de dados MariaDB (ou MySQL)
    'db_host'     => $env('DB_HOST', 'localhost'),
    'db_port'     => (int)$env('DB_PORT', 3306),
    'db_name'     => $env('DB_NAME', 'acredite'),
    'db_user'     => $env('DB_USER', 'root'),
    'db_pass'     => $env('DB_PASS', ''),

    // Pasta onde as imagens dos infográficos são guardadas
    'storage_dir' => __DIR__ . '/../storage',

    // E-mail que aparece como remetente na recuperação de senha
    'mail_from'   => $env('MAIL_FROM', 'nao-responda@seudominio.com.br'),
    'app_name'    => 'Acredite.se',
    // Endereço do site usado nos links dos e-mails (opcional)
    'app_url'     => $env('APP_URL', ''),
    // Chave para rodar api/cron.php por endereço (opcional, veja README.md)
    'cron_key'    => $env('CRON_KEY', ''),

    // true = mostra o código de recuperação na resposta da API (só para testes!)
    'debug'       => filter_var($env('DEBUG', false), FILTER_VALIDATE_BOOLEAN),

    // Duração da sessão em segundos (8 horas)
    'session_lifetime' => 8 * 60 * 60,

    // Limites
    'max_library_items' => 200,       // por usuário
    'max_image_bytes'   => 8 * 1024 * 1024, // 8 MB por infográfico

    // IA (qualquer serviço no formato da API da OpenAI). Padrão: Groq, grátis — chave em https://console.groq.com
    'ai_url'      => $env('AI_URL', 'https://api.groq.com/openai/v1'),
    'ai_key'      => $env('AI_KEY', ''),
    'ai_model'    => $env('AI_MODEL', 'llama-3.3-70b-versatile'),
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, (array)require $local);
}
return $config;
