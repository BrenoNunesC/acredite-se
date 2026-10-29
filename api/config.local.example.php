<?php
/**
 * Copie este arquivo para api/config.local.php e coloque os dados DESTE computador/servidor.
 * O config.local.php está no .gitignore: senhas e chaves não vão para o GitHub.
 * Só precisa deixar aqui o que for diferente do padrão de config.php.
 */
return [
    // Banco de dados MariaDB / MySQL
    'db_host'   => '127.0.0.1',
    'db_port'   => 3306,
    'db_name'   => 'acredite',
    'db_user'   => 'acredite',
    'db_pass'   => 'sua-senha',

    // IA — Groq (grátis): crie a chave em https://console.groq.com → API Keys
    'ai_key'    => 'gsk_...',
    // 'ai_model' => 'llama-3.1-8b-instant',   // mais rápido e com limite diário maior

    // Outros serviços no mesmo formato (troque as três linhas):
    //   Google Gemini:  'ai_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'ai_model' => 'gemini-flash-latest'
    //   OpenRouter:     'ai_url' => 'https://openrouter.ai/api/v1', 'ai_model' => 'nome-do-modelo:free'
    //   Ollama local:   'ai_url' => 'http://127.0.0.1:11434/v1', 'ai_key' => '', 'ai_model' => 'qwen2.5:3b'

    'mail_from' => 'nao-responda@seudominio.com.br',

    // true só no seu computador: mostra o código de recuperação de senha na tela
    'debug'     => false,
];
