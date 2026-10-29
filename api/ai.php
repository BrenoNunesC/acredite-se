<?php
/**
 * IA dos infográficos. Fala com qualquer serviço no formato da API da OpenAI
 * (Groq, Google Gemini, OpenRouter, GitHub Models ou Ollama local) — veja ai_url, ai_key e ai_model em config.php.
 *   GET  ai.php?action=status                       → {ok, model} ou {ok:false, error}
 *   POST ai.php?action=generate {prompt}            → {spec:{kicker,title,subtitle,theme,kpis,charts,progress,footer}}
 *   POST ai.php?action=edit {prompt, elements}      → {changes:[{id,field,value}], add:{kpis,charts,progress}, theme}
 *   POST ai.php?action=assist {messages, screen}    → {reply, action}  (assistente Cápsula: conversa sobre o sistema)
 *   POST ai.php?action=ask {question, columns}      → {query}  (pergunte aos dados: o navegador calcula o resultado)
 *   POST ai.php?action=summary {facts}              → {summary}  (resumo do painel em 2 ou 3 frases)
 *        elements = [{id:"e1", type:"text", text:"..."}, {id:"e2", type:"kpi", value:"94", suffix:"%", label:"..."}, ...]
 * A IA só escreve o conteúdo (textos e números); a diagramação é feita no navegador.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/teamlib.php';
$user = requireUser();
$uid  = (int)$user['id'];

$AI_URL   = rtrim((string)($CONFIG['ai_url'] ?? 'https://api.groq.com/openai/v1'), '/');
$AI_KEY   = (string)($CONFIG['ai_key'] ?? '');
$AI_MODEL = (string)($CONFIG['ai_model'] ?? 'llama-3.3-70b-versatile');

const AI_KPIS = ['type' => 'array', 'items' => [
    'type' => 'object',
    'properties' => [
        'value'  => ['type' => 'number'],
        'prefix' => ['type' => 'string'],
        'suffix' => ['type' => 'string'],
        'label'  => ['type' => 'string'],
    ],
    'required' => ['value', 'suffix', 'label'],
]];
const AI_CHARTS = ['type' => 'array', 'items' => [
    'type' => 'object',
    'properties' => [
        'type'  => ['type' => 'string', 'enum' => ['bar', 'line', 'donut']],
        'title' => ['type' => 'string'],
        'data'  => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'number']],
            'required' => ['label', 'value'],
        ]],
    ],
    'required' => ['type', 'title', 'data'],
]];
const AI_PROGRESS = ['type' => 'array', 'items' => [
    'type' => 'object',
    'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'number']],
    'required' => ['label', 'value'],
]];

const AI_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'kicker'     => ['type' => 'string'],
        'title'      => ['type' => 'string'],
        'subtitle'   => ['type' => 'string'],
        'theme'      => ['type' => 'string', 'enum' => ['light', 'dark']],
        'kpis'       => AI_KPIS,
        'charts'     => AI_CHARTS,
        'progress'   => AI_PROGRESS,
        'highlights' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'text' => ['type' => 'string']],
            'required' => ['title', 'text'],
        ]],
        'conclusion' => ['type' => 'string'],
        'footer'     => ['type' => 'string'],
    ],
    'required' => ['kicker', 'title', 'subtitle', 'theme', 'kpis', 'charts', 'progress', 'highlights', 'conclusion'],
];

// Campos de conteúdo que a IA pode mudar em cada tipo de elemento
const AI_EDIT_FIELDS = [
    'text' => ['text'], 'button' => ['label'], 'kpi' => ['value', 'prefix', 'suffix', 'label'],
    'bar' => ['title', 'data'], 'line' => ['title', 'data'], 'donut' => ['title', 'data'], 'progress' => ['label', 'value'],
];
const AI_EDIT_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'changes' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => [
                'id'    => ['type' => 'string'],
                'field' => ['type' => 'string', 'enum' => ['text', 'label', 'value', 'prefix', 'suffix', 'title', 'data']],
                'value' => ['type' => 'string'],
            ],
            'required' => ['id', 'field', 'value'],
        ]],
        'add' => [
            'type' => 'object',
            'properties' => ['kpis' => AI_KPIS, 'charts' => AI_CHARTS, 'progress' => AI_PROGRESS],
            'required' => ['kpis', 'charts', 'progress'],
        ],
        'theme' => ['type' => 'string', 'enum' => ['keep', 'light', 'dark']],
    ],
    'required' => ['changes', 'add', 'theme'],
];

const AI_SYSTEM = <<<TXT
Você escreve o conteúdo de infográficos completos em português do Brasil para um sistema de gestão da qualidade e acreditação.
O infográfico pode ter várias páginas: o sistema distribui o conteúdo sozinho. Seja detalhado e útil, como um analista explicando os dados.
Responda somente com o JSON pedido. Regras:
- kicker: rótulo curto em MAIÚSCULAS (ex.: "RELATÓRIO 2026"). title: curto e forte, até 45 caracteres. subtitle: uma frase de até 100 caracteres que resume a mensagem principal.
- kpis: de 3 a 6 números de destaque. value é só o número, com ponto nos decimais (4.8). suffix só para unidade curta: "%", " dias", "/5"; para contagens deixe suffix vazio e explique na label. prefix para moeda ("R$ "). label: até 28 caracteres.
- charts: de 1 a 4 gráficos, um para cada lista de valores do pedido, cada um mostrando um aspecto diferente. "bar" compara categorias, "line" mostra evolução no tempo (meses, anos), "donut" mostra partes de um todo (os valores somam o total). data: de 3 a 8 itens com label curto (até 14 caracteres).
- Não repita em kpis os valores que já estão num gráfico.
- progress: de 0 a 4 barras para metas, prazos e percentuais concluídos (ex.: "meta anual 72% concluída"), value de 0 a 100.
- highlights: de 2 a 4 destaques. title: até 40 caracteres. text: de 1 a 2 frases (até 180 caracteres) explicando um ponto forte, um ponto de atenção ou uma recomendação, citando números.
- conclusion: de 1 a 2 frases (até 200 caracteres) com a principal conclusão e o próximo passo.
- footer: fonte ou observação curta.
- theme: "dark" só se o pedido falar em escuro, noite ou fundo escuro; senão "light".
- Use todos os números e nomes que o pedido trouxer, sem mudar nenhum. Se faltar dado para um gráfico ou número, crie exemplos realistas e coerentes com os dados do pedido.

Exemplo. Pedido: "Satisfação 2026: 96% satisfeitos, 340 respostas, tempo médio 3.2 dias. Notas por curso: Direito 4.6, Medicina 4.8, Letras 4.1. Meta de respostas 85% atingida."
Resposta: {"kicker":"PESQUISA 2026","title":"Satisfação dos alunos","subtitle":"96% dos alunos estão satisfeitos, com Medicina à frente","theme":"light","kpis":[{"value":96,"prefix":"","suffix":"%","label":"Satisfeitos"},{"value":340,"prefix":"","suffix":"","label":"Respostas"},{"value":3.2,"prefix":"","suffix":" dias","label":"Tempo médio de resposta"},{"value":4.5,"prefix":"","suffix":"/5","label":"Nota média geral"}],"charts":[{"type":"bar","title":"Nota média por curso","data":[{"label":"Direito","value":4.6},{"label":"Medicina","value":4.8},{"label":"Letras","value":4.1}]},{"type":"donut","title":"Como os alunos avaliam","data":[{"label":"Satisfeitos","value":96},{"label":"Neutros","value":3},{"label":"Insatisfeitos","value":1}]}],"progress":[{"label":"Meta de respostas","value":85}],"highlights":[{"title":"Medicina é referência","text":"Com nota 4,8, Medicina tem práticas que podem ser levadas aos outros cursos."},{"title":"Atenção em Letras","text":"A nota 4,1 é a mais baixa. Vale ouvir os alunos para entender as causas."}],"conclusion":"A satisfação está alta. O próximo passo é levar a experiência de Medicina para Letras.","footer":"Fonte: pesquisa institucional 2026"}
TXT;

const AI_EDIT_SYSTEM = <<<TXT
Você edita infográficos que já existem, em português do Brasil, para um sistema de gestão da qualidade e acreditação.
Você recebe a lista de elementos (cada um com id, type e seus campos) e um pedido. Responda somente com o JSON pedido:
- changes: só as mudanças que o pedido exige. Cada item muda um campo de um elemento: id igual ao da lista, field é o nome do campo, value é o novo conteúdo como texto.
  Campos por tipo: text → text; button → label; kpi → value, prefix, suffix, label; bar/line/donut → title, data; progress → label, value.
  value de número: só o número com ponto nos decimais ("4.8"). data: um item por linha no formato rótulo;valor ("Ensino;92\\nPesquisa;78").
  Não repita campos que ficam iguais. Mantenha o tamanho parecido dos textos, a não ser que o pedido diga outra coisa.
- add: elementos novos só quando o pedido mandar adicionar ou criar algo (kpis = números de destaque, charts = gráficos, progress = barras de 0 a 100). Senão, listas vazias.
- theme: "dark" ou "light" só se o pedido mandar mudar o fundo/tema; senão "keep".

Exemplo. Elementos: [{"id":"e1","type":"text","text":"Relatório 2025"},{"id":"e2","type":"kpi","value":"94","prefix":"","suffix":"%","label":"Conformidade"},{"id":"e3","type":"bar","title":"Por setor","data":"Ensino;92\\nPesquisa;78"}]
Pedido: "Troque o ano para 2026, a conformidade para 96% e adicione uma barra de meta anual com 70%."
Resposta: {"changes":[{"id":"e1","field":"text","value":"Relatório 2026"},{"id":"e2","field":"value","value":"96"}],"add":{"kpis":[],"charts":[],"progress":[{"label":"Meta anual","value":70}]},"theme":"keep"}
TXT;

/** "4,8" → "4.8": modelos pequenos confundem a vírgula decimal com separador. */
function aiPrompt(string $p): string {
    return preg_replace('/(\d),(\d{1,2})(?!\d)/', '$1.$2', $p);
}

/** Chamada HTTP JSON ao serviço de IA (sem depender da extensão curl). */
function aiHttp(string $path, ?array $payload, int $timeout): array {
    global $AI_URL, $AI_KEY;
    $headers = "Content-Type: application/json\r\n";
    if ($AI_KEY !== '') $headers .= "Authorization: Bearer {$AI_KEY}\r\n";
    $ctx = stream_context_create(['http' => [
        'method'        => $payload === null ? 'GET' : 'POST',
        'header'        => $headers,
        'content'       => $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($AI_URL . $path, false, $ctx);
    if ($raw === false) fail('Não foi possível falar com a IA. Confira a internet e o endereço ai_url na configuração.', 503);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    $json = json_decode($raw, true);
    if ($status === 401 || $status === 403) fail('A chave da IA está faltando ou é inválida. Confira ai_key na configuração.', 503);
    if ($status === 429) fail('O limite grátis da IA foi atingido. Tente de novo em alguns instantes.', 503);
    if ($status === 404) fail('Modelo de IA não encontrado: ' . ($payload['model'] ?? '') . '. Confira ai_model na configuração.', 503);
    if ($status >= 400 || !is_array($json)) fail('A IA não respondeu direito. Tente de novo.', 502);
    return $json;
}

/**
 * Pede uma resposta em JSON. O formato esperado vai junto das instruções, porque nem todo
 * serviço aceita JSON Schema; o modo json_object (aceito por todos) garante um JSON válido.
 */
function aiChat(array $schema, array $messages, float $temperature, int $timeout): ?array {
    global $AI_MODEL;
    $messages[0]['content'] .= "\n\nResponda SOMENTE com um objeto JSON válido que siga este JSON Schema:\n"
        . json_encode($schema, JSON_UNESCAPED_UNICODE);
    $res = aiHttp('/chat/completions', [
        'model'           => $AI_MODEL,
        'temperature'     => $temperature,
        'response_format' => ['type' => 'json_object'],
        'messages'        => $messages,
    ], $timeout);
    $text = trim((string)($res['choices'][0]['message']['content'] ?? ''));
    // Alguns modelos embrulham o JSON em ```json … ```
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
    $out = json_decode($text, true);
    return is_array($out) ? $out : null;
}

/** Limite de uso, libera a sessão e pede um JSON ao modelo. */
function askAi(array $schema, string $system, string $user): array {
    global $uid;
    $key = 'ai:' . $uid;
    if (tooManyAttempts($key, 30, 3600)) fail('Muitos pedidos à IA na última hora. Espere um pouco.', 429);
    recordAttempt($key);
    // Libera a sessão: a IA pode demorar e não deve travar as outras telas
    session_write_close();
    set_time_limit(150);
    $out = aiChat($schema, [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 0.4, 120);
    if (!is_array($out)) fail('A IA devolveu uma resposta incompleta. Tente de novo.', 502);
    return $out;
}

function txt($v, int $max): string {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$v)), 0, $max);
}
function num($v, float $min = -1e12, float $max = 1e12): float {
    $n = is_numeric($v) ? (float)$v : (float)str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', (string)$v));
    return round(min($max, max($min, $n)), 2);
}
function arr($v): array { return is_array($v) ? $v : []; }

// Assistente Cápsula: explica o sistema e, se pedirem, leva a pessoa a uma tela (só telas conhecidas)
const ASSIST_TARGETS = ['home', 'models', 'ig', 'crm', 'library', 'teams', 'settings', 'admin', 'tour', 'none'];
// Elementos da tela que a Cápsula pode apontar (o navegador acha cada um pelo nome)
const ASSIST_ANCHORS = [
    'home-create'    => 'Início: botões Nova análise, Novo infográfico e Nova planilha no CRM',
    'home-explore'   => 'Início: módulos Biblioteca, Equipes, Configurações e Administração',
    'home-recent'    => 'Início: itens salvos recentemente',
    'home-tour'      => 'Início: botão Ver tutorial',
    'home-pill'      => 'Início: a própria Cápsula na faixa de destaque',
    'brand'          => 'Logotipo acredite.se no canto superior (leva ao Início)',
    'sidebar-library'=> 'Menu lateral: item Biblioteca',
    'model-grid'     => 'Nova análise: grade de modelos de análise',
    'file-drop'      => 'Nova análise: área para enviar a planilha',
    'present-btn'    => 'Análise da planilha: botão Apresentar',
    'speed'          => 'Barra de velocidade da Cápsula (nas apresentações)',
    'ig-ai'          => 'Criar infográfico: campo para descrever o infográfico com IA',
    'ig-play'        => 'Editor de infográfico: botão Ver animação aqui',
    'ig-mascot'      => 'Editor de infográfico: botão que liga e desliga a Cápsula no design',
    'crm-form'       => 'CRM: formulário para criar planilha em branco',
    'assist'         => 'Esta própria Cápsula, no canto da tela',
];
// Comandos que a Cápsula pode executar na tela (lista fixa; o navegador só roda estes)
const ASSIST_COMMANDS = ['none', 'crm_open_sheet', 'crm_new_blank', 'crm_filter_late', 'crm_filter_open', 'crm_filter_done', 'crm_filter_clear', 'crm_export_excel', 'crm_generate_infographic', 'crm_generate_presentation', 'crm_delete_sheet', 'crm_delete_column', 'analysis_present'];
const ASSIST_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'reply'   => ['type' => 'string'],
        'command' => ['type' => 'string', 'enum' => ASSIST_COMMANDS],
        'arg'     => ['type' => 'string'],
        'action'  => ['type' => 'string', 'enum' => ASSIST_TARGETS],
        'target' => ['type' => 'string', 'enum' => ['home-create', 'home-explore', 'home-recent', 'home-tour', 'home-pill', 'brand', 'sidebar-library', 'model-grid', 'file-drop', 'present-btn', 'speed', 'ig-ai', 'ig-play', 'ig-mascot', 'crm-form', 'assist', 'none']],
    ],
    'required' => ['reply', 'command', 'arg', 'action', 'target'],
];
const ASSIST_SYSTEM = <<<TXT
Você é a Cápsula, a assistente virtual do acredite.se, um sistema de gestão da qualidade e acreditação. Responda em português do Brasil, de forma clara, simpática e prática, em até 4 frases. Quando a pessoa perguntar como fazer algo, dê os passos numerados, um por linha, com os nomes exatos dos botões. Ajude só com o sistema: se perguntarem outra coisa, diga que você ajuda com o acredite.se.

Telas e módulos:
- Início (home): tela principal. Atalhos para criar, módulos e itens recentes. O botão Ver tutorial mostra um passo a passo.
- Nova análise (models): envie uma planilha .xlsx, .xls ou .csv e escolha um modelo de análise. O painel mostra quadros, filtros e gráficos. Dá para exportar em PNG, PDF, PowerPoint e Excel. Apresentar abre os slides.
  No painel: "Filtrar" escolhe uma coluna e um valor e filtra tudo; no Período, "Comparar com" mostra a variação contra o período anterior ou o mesmo período do ano passado; "Pergunte aos dados" responde perguntas como "qual vendedor vendeu mais em março?"; "Resumo com IA" escreve o resumo do painel (ele vai para a biblioteca e para o relatório mensal). Planilhas do Google Sheets podem se atualizar sozinhas (seletor ao lado do botão de atualizar).
- Criar infográfico (ig): gerar com IA, usar modelos prontos, ou gerar a partir da planilha aberta ou importar a exportação do Ramper. No editor: mover elementos, escolher a animação de cada bloco, páginas, Apresentar (F5) e exportar PNG ou PDF.
- CRM de planilhas (crm): planilhas com etapas, responsáveis, datas de próximo contato e anotações. Criar em branco ou importar o Ramper. Gerar infográfico ou apresentação com os dados. Visualização em Tabela, Kanban (arrastar cartões entre etapas) ou Funil (quantos chegam em cada etapa e a conversão). Os lembretes de contatos de hoje e atrasados aparecem no Início e no CRM, e há um lembrete diário por e-mail que dá para desligar.
- Biblioteca (library): apresentações e infográficos salvos. Dá para reabrir, renomear, comparar e compartilhar com link.
- Equipes (teams): criar equipes, convidar pessoas, com papéis de administrador, editor ou leitor. Modelos e itens compartilhados e alertas de meta por e-mail.
- Configurações (settings): tema, formato padrão de exportação e dados.
- Administração (admin): só para administradores do sistema.
- Velocidade da cápsula: barra no canto inferior esquerdo, só nas apresentações.

Como fazer (passo a passo):
- Analisar uma planilha: em Nova análise, envie o arquivo .xlsx, .xls ou .csv, escolha um modelo e abra o painel.
- Exportar a análise: no painel, escolha PNG, PDF, PowerPoint ou Excel. Apresentar abre os slides.
- Criar um infográfico com IA: em Criar infográfico, descreva o que quer no campo de IA e gere.
- Criar um infográfico a partir de uma planilha: em Criar infográfico, use a planilha aberta, ou importe a exportação do Ramper. Também dá para gerar a partir de uma planilha do CRM.
- Animar um bloco: no editor do infográfico, escolha a animação de cada bloco e use Ver animação aqui para testar.
- Criar planilha no CRM: em CRM de planilhas, use o formulário de planilha em branco, dê um nome e adicione as linhas.
- Ver pendências no CRM: use os filtros atrasadas, em aberto ou concluídas. Tirar filtros mostra tudo de novo.
- Compartilhar uma planilha com a equipe: no cabeçalho da planilha, escolha a equipe. Só o dono da planilha pode fazer isso. Os papéis (administrador, editor, leitor) são da equipe, não da planilha.
- Criar ou entrar em equipe: em Equipes, crie uma equipe ou entre com o código de convite. Os papéis são administrador, editor e leitor.
- Personalizar a Cápsula: no Início, abra Minha Cápsula, ou pelas Configurações. Lá ficam as partes do corpo e o switch de personalidade.
- Ver o tutorial: no Início, clique em Ver tutorial.

Dicas e limites:
- Datas no formato dd/mm/aaaa são reconhecidas nas planilhas.
- Se a resposta com IA não aparecer, espere alguns instantes e tente de novo (o plano grátis da IA tem limite por minuto).
- A Cápsula só mexe na tela quando a pessoa pede. Ações que apagam algo pedem confirmação antes.
- A aba Erros fica em Administração e só aparece para administradores.

Campo action: quando a pessoa pedir para ir a uma tela, use o nome dela: home, models, ig, crm, library, teams, settings, admin, ou tour para o tutorial. Nos outros casos, use none.
Campo command: quando a pessoa pedir para fazer algo na tela, escolha um comando desta lista e nunca invente outro: crm_open_sheet (arg = nome da planilha), crm_new_blank (arg = nome da planilha nova), crm_filter_late (atrasadas), crm_filter_open (em aberto), crm_filter_done (concluídas), crm_filter_clear (tirar filtros), crm_export_excel, crm_generate_infographic, crm_generate_presentation, crm_delete_sheet (arg = nome), crm_delete_column (arg = nome da coluna), analysis_present (apresentar a análise aberta). Use none se for só uma explicação. Campo arg: use "" quando não houver. Para planilhas, use exatamente um dos nomes listados abaixo, quando existir.
A tela em que a pessoa está aparece no fim destas instruções. Use essa informação só se ajudar a responder, sem repetir o nome da tela na resposta.
Campo target: o elemento da tela que mostra a resposta, para a Cápsula apontar. Escolha um destes valores: home-create, home-explore, home-recent, home-tour, home-pill, brand, sidebar-library, model-grid, file-drop, present-btn, speed, ig-ai, ig-play, ig-mascot, crm-form, assist. Use none se nenhum servir. Faça a resposta curta: ela aparece num balão ao lado do elemento.
TXT;

/** Regra fixa para os pedidos comuns, usada quando o modelo não escolhe comando. Devolve [comando, arg]. */
// Personalidade de cada cor da Cápsula (espelha CAPSULE_PERSONAS em index.html)
const PERSONALIDADES = [
    'classic' => ['nome' => 'Violeta', 'emocao' => 'Amor', 'perfil' => 'Passional, obsessivo e superprotetor.', 'fala' => 'Intensa, dramática e impositiva.', 'bordao' => 'Estou te protegendo de si mesmo.'],
    'menta'   => ['nome' => 'Verde', 'emocao' => 'Força de Vontade', 'perfil' => 'Obstinado, pragmático e disciplinado.', 'fala' => 'Firme, lógica e sem rodeios.', 'bordao' => 'Foco no plano.'],
    'sol'     => ['nome' => 'Laranja', 'emocao' => 'Avareza', 'perfil' => 'Paranoico, ciumento e calculista.', 'fala' => 'Rápida, defensiva e sibilante.', 'bordao' => 'Tire as mãos, é meu.'],
    'rosa'    => ['nome' => 'Vermelho', 'emocao' => 'Ira', 'perfil' => 'Agressivo, impaciente e reativo.', 'fala' => 'Seca, curta e cortante.', 'bordao' => 'Cale a boca e ande.'],
    'noite'   => ['nome' => 'Índigo', 'emocao' => 'Compaixão', 'perfil' => 'Altruísta, humilde e protetor.', 'fala' => 'Suave, ponderada e impessoal.', 'bordao' => 'O seu fardo pesa em mim.'],
    'lima'    => ['nome' => 'Amarelo', 'emocao' => 'Medo', 'perfil' => 'Tirânico, sádico e intimidador.', 'fala' => 'Pausada, fria e teatral.', 'bordao' => 'Sinta o tremor na sua respiração.'],
    'branco'  => ['nome' => 'Branco', 'emocao' => 'Vida', 'perfil' => 'Neutro, amplo e equilibrado.', 'fala' => 'Clara, serena e definitiva.', 'bordao' => 'Restaure o ciclo.'],
    'ceu'     => ['nome' => 'Azul', 'emocao' => 'Esperança', 'perfil' => 'Calmo, paciente e resiliente.', 'fala' => 'Acolhedora, constante e sem ironia.', 'bordao' => 'Ainda não acabou.'],
    'grafite' => ['nome' => 'Preto', 'emocao' => 'Morte', 'perfil' => 'Niilista, frio e implacável.', 'fala' => 'Oca, monótona e mecânica.', 'bordao' => 'O esforço é inútil.'],
];
/** Dados reais da conta para a Cápsula responder: planilhas com pendências, equipes e itens salvos. */
function assistContexto(int $uid): string {
    $teams = userTeams($uid);
    $ids = array_keys($teams);
    $st = db()->prepare('SELECT * FROM crm_sheets WHERE user_id = ?' . ($ids ? ' OR team_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '') . ' ORDER BY updated_at DESC LIMIT 10');
    $st->execute([$uid, ...$ids]);
    $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $planilhas = [];
    foreach ($st->fetchAll() as $s) {
        // Mesmas regras do CRM: "concluída" é uma etapa marcada como feita (padrão: Concluído e Perdido)
        $stages = json_decode((string)$s['stages_json'], true) ?: [];
        $feitas = array_column(array_filter($stages, fn($x) => !empty($x['done'])), 'name') ?: ['Concluído', 'Perdido'];
        $c = db()->prepare('SELECT status, due_date FROM crm_rows WHERE sheet_id = ?');
        $c->execute([$s['id']]);
        $total = 0; $abertas = 0; $atrasadas = 0;
        foreach ($c->fetchAll() as $r) {
            $total++;
            if (in_array($r['status'], $feitas, true)) continue;
            $abertas++;
            if ($r['due_date'] && substr((string)$r['due_date'], 0, 10) < $hoje) $atrasadas++;
        }
        $equipe = $s['team_id'] ? ' (equipe ' . ($teams[(int)$s['team_id']]['name'] ?? '') . ')' : '';
        $planilhas[] = '- ' . $s['name'] . $equipe . ': ' . $total . ' linhas, ' . $abertas . ' em aberto, ' . $atrasadas . ' atrasadas';
        $planilhasNum[] = ['abertas' => $abertas, 'atrasadas' => $atrasadas];
    }
    $planilhasNum = $planilhasNum ?? [];
    // Totais prontos: o modelo pequeno erra as contas quando precisa somar linhas
    $resumo = count($planilhas) . ' planilhas, ' . array_sum(array_column($planilhasNum, 'abertas')) . ' linhas em aberto e '
        . array_sum(array_column($planilhasNum, 'atrasadas')) . ' atrasadas';
    $equipes = array_map(fn($t) => $t['name'] . ' (' . $t['role'] . ')', $teams);
    $cs = db()->prepare('SELECT COUNT(*) FROM library WHERE user_id = ?');
    $cs->execute([$uid]);
    return "\nDados desta conta (use para responder sobre planilhas, equipes e itens salvos. Hoje é $hoje):"
        . "\nPlanilhas do CRM: " . ($planilhas ? "\nResumo: $resumo.\n" . implode("\n", $planilhas) : 'nenhuma') . '.'
        . "\nEquipes: " . ($equipes ? implode(', ', $equipes) : 'nenhuma') . '.'
        . "\nItens salvos na Biblioteca: " . (int)$cs->fetchColumn() . '.';
}

/** Instrução de estilo para o assistente, só quando a pessoa ligou a personalidade (a cor vem da conta, não do navegador). */
function personalidadePrompt(int $uid): string {
    $st = db()->prepare('SELECT capsule FROM user_prefs WHERE user_id = ?');
    $st->execute([$uid]);
    $cap = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($cap) || ($cap['personality'] ?? 'off') !== 'on') return '';
    $p = PERSONALIDADES[$cap['skin'] ?? ''] ?? null;
    if (!$p) return '';
    // Só o temperamento entra no prompt: a frase de efeito (bordão) faria o modelo repeti-la em toda resposta
    return "\nPersonalidade da Cápsula: {$p['nome']}, que representa {$p['emocao']}. "
        . "Temperamento: {$p['perfil']} Jeito de falar: {$p['fala']} "
        . "Aja com esse temperamento no campo reply, mas responda com clareza e com as informações corretas. "
        . "Não cite nem repita frases de efeito ou bordões; escreva com as suas próprias palavras. Os comandos e a tela não mudam.";
}

function assistInfer(string $text, array $sheetNames): array {
    $t = mb_strtolower($text);
    if (preg_match('/limpar|tirar.*filtro|remover.*filtro|sem filtro/u', $t)) return ['crm_filter_clear', ''];
    if (preg_match('/atrasad/u', $t)) return ['crm_filter_late', ''];
    if (preg_match('/em aberto|abertas?\b/u', $t)) return ['crm_filter_open', ''];
    if (preg_match('/conclu/u', $t)) return ['crm_filter_done', ''];
    if (preg_match('/excel|xlsx/u', $t) && preg_match('/baix|export|gera|salv|minha/u', $t)) return ['crm_export_excel', ''];
    if (preg_match('/(?:apag|exclu|remov)\w*\s+(?:a\s+|o\s+)?planilha\s+"?([^"?.!]+?)"?\s*[?.!]?$/iu', $text, $m)) {
        // Excluir sempre pede confirmação no navegador; aqui só escolhe o comando
        foreach ($sheetNames as $n) if (mb_strtolower($n) === trim($m[1])) return ['crm_delete_sheet', $n];
        return ['crm_delete_sheet', trim($m[1])];
    }
    if (preg_match('/(?:cri|nov)\w*\s+(?:uma\s+|um\s+)?planilha.*?(?:chamad[ao]|nome)\s+"?([^"?.!]+?)"?\s*[?.!]?$/iu', $text, $m)) {
        return ['crm_new_blank', trim($m[1])];
    }
    if (preg_match('/(?:abr|mostr)\w*\s+(?:a\s+|o\s+)?planilha\s+"?([^"?.!]+?)"?\s*[?.!]?$/iu', $text, $m)) {
        // Usa o nome exato da lista, quando ele existir
        $wanted = trim($m[1]);
        foreach ($sheetNames as $n) if (mb_strtolower($n) === $wanted) return ['crm_open_sheet', $n];
        return ['crm_open_sheet', $wanted];
    }
    return ['none', ''];
}

/* ── Limpeza: confere e limita tudo o que a IA devolveu ─────────── */
function cleanKpis($list, int $max = 4): array {
    $out = [];
    foreach (array_slice(arr($list), 0, $max) as $k) {
        if (!is_array($k) || txt($k['label'] ?? '', 40) === '') continue;
        $out[] = ['value' => num($k['value'] ?? 0), 'prefix' => txt($k['prefix'] ?? '', 6),
                  'suffix' => txt($k['suffix'] ?? '', 8), 'label' => txt($k['label'], 40)];
    }
    return $out;
}
function cleanCharts($list, int $max = 2): array {
    $types = ['bar', 'line', 'donut'];
    $out = [];
    foreach (array_slice(arr($list), 0, $max) as $c) {
        if (!is_array($c)) continue;
        $data = [];
        foreach (array_slice(arr($c['data'] ?? null), 0, 8) as $d) {
            if (!is_array($d) || txt($d['label'] ?? '', 20) === '') continue;
            $data[] = ['label' => txt($d['label'], 20), 'value' => num($d['value'] ?? 0)];
        }
        if (count($data) < 2) continue;
        $out[] = ['type' => in_array($c['type'] ?? '', $types, true) ? $c['type'] : 'bar',
                  'title' => txt($c['title'] ?? '', 60), 'data' => $data, 'insight' => txt($c['insight'] ?? '', 160)];
    }
    return $out;
}
function cleanProgress($list, int $max = 3): array {
    $out = [];
    foreach (array_slice(arr($list), 0, $max) as $p) {
        if (!is_array($p) || txt($p['label'] ?? '', 40) === '') continue;
        $out[] = ['label' => txt($p['label'], 40), 'value' => num($p['value'] ?? 0, 0, 100)];
    }
    return $out;
}
/* ── Conferência pelos dados do pedido ──────────────────────────
   Modelos pequenos esquecem listas e erram comparações. O que dá para
   calcular (séries do pedido, explicação de cada gráfico, destaques
   básicos) é feito aqui, a partir dos números exatos. */

/** Número no jeito brasileiro: 4.8 → "4,8", 1200 → "1.200". */
function ptNum(float $n): string {
    $dec = abs($n - round($n)) < 0.005 ? 0 : (abs($n * 10 - round($n * 10)) < 0.05 ? 1 : 2);
    return number_format($n, $dec, ',', '.');
}
/** "nota 4.8" → "nota 4,8" nos textos que a IA escreveu. */
function ptText(string $s): string {
    return preg_replace('/(\d)\.(\d{1,2})(?!\d)/', '$1,$2', $s);
}
function sameLabel(string $a, string $b): bool {
    return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
}
const AI_TIME_LABEL = '/^(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez|janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro|semana.*|trimestre.*|t[1-4]|q[1-4]|\d{4})\.?$/iu';

/** Listas do pedido no formato "Título: Nome 12, Outro 34, ..." viram séries de gráfico. */
function promptSeries(string $p): array {
    $item = '[\p{L}][\p{L}\p{N}\/ ]{0,30}?\s+-?\d+(?:\.\d+)?%?';
    preg_match_all("/([^:.;\\n]{3,60}):\\s*((?:$item\\s*(?:,|\\be\\b)\\s*)+$item)/u", $p, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $g) {
        preg_match_all('/([\p{L}][\p{L}\p{N}\/ ]{0,30}?)\s+(-?\d+(?:\.\d+)?)%?/u', $g[2], $it, PREG_SET_ORDER);
        $data = [];
        foreach (array_slice($it, 0, 8) as $x) {
            $label = txt(preg_replace('/^e\s+/u', '', $x[1]), 20);
            if ($label !== '') $data[] = ['label' => $label, 'value' => (float)$x[2]];
        }
        if (count($data) < 2) continue;
        $title = txt($g[1], 60);
        $time  = count(array_filter($data, fn($d) => preg_match(AI_TIME_LABEL, $d['label']))) >= count($data) - 1;
        $sum   = array_sum(array_column($data, 'value'));
        $parts = preg_match('/distribui|status|participa|propor|divis|composi/iu', $title) && abs($sum - 100) <= 2;
        $out[] = ['type' => $time ? 'line' : ($parts ? 'donut' : 'bar'), 'title' => mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1), 'data' => $data];
    }
    return $out;
}
/** O gráfico da IA corresponde a esta série? (metade ou mais dos rótulos iguais) */
function sameSeries(array $chart, array $series): bool {
    $hit = 0;
    foreach ($series['data'] as $d) {
        foreach ($chart['data'] as $c) if (sameLabel($c['label'], $d['label'])) { $hit++; break; }
    }
    return $hit * 2 >= count($series['data']);
}
/** Frase calculada que explica o gráfico (sempre bate com os números). */
function chartInsight(array $c): string {
    $d = $c['data'];
    $vals = array_column($d, 'value');
    $max = array_keys($vals, max($vals))[0];
    $min = array_keys($vals, min($vals))[0];
    if ($c['type'] === 'line') {
        $first = $d[0]; $last = $d[count($d) - 1];
        $dir = $last['value'] > $first['value'] ? 'alta' : ($last['value'] < $first['value'] ? 'queda' : 'estabilidade');
        $pct = $first['value'] != 0 ? ' (' . ($last['value'] >= $first['value'] ? '+' : '') . ptNum(round(($last['value'] - $first['value']) / abs($first['value']) * 100)) . '%)' : '';
        $s = ucfirst($dir) . " de {$first['label']} para {$last['label']}: de " . ptNum($first['value']) . ' para ' . ptNum($last['value']) . $pct . '.';
        if ($max !== count($d) - 1 && $max !== 0) $s .= " Pico em {$d[$max]['label']} (" . ptNum($d[$max]['value']) . ').';
        return $s;
    }
    if ($c['type'] === 'donut') {
        $tot = array_sum($vals) ?: 1;
        return "{$d[$max]['label']} representa " . ptNum($d[$max]['value'] / $tot * 100) . '% do total.';
    }
    return "Maior: {$d[$max]['label']} (" . ptNum($d[$max]['value']) . "). Menor: {$d[$min]['label']} (" . ptNum($d[$min]['value'])
         . '), diferença de ' . ptNum($d[$max]['value'] - $d[$min]['value']) . '.';
}
/** Destaques tirados dos dados, para completar quando a IA fizer poucos. */
function dataHighlights(array $charts): array {
    $out = [];
    foreach ($charts as $c) {
        $d = $c['data']; $vals = array_column($d, 'value');
        $max = $d[array_keys($vals, max($vals))[0]]; $min = $d[array_keys($vals, min($vals))[0]];
        $topic = mb_strtolower($c['title']);
        if ($c['type'] === 'line') {
            $first = $d[0]; $last = $d[count($d) - 1];
            $up = $last['value'] >= $first['value'];
            $out[] = ['key' => '', 'title' => $up ? 'Tendência de alta' : 'Tendência de queda',
                      'text' => "Em {$topic}, o resultado foi de " . ptNum($first['value']) . " ({$first['label']}) para " . ptNum($last['value']) . " ({$last['label']})."];
        } elseif ($c['type'] === 'bar') {
            $out[] = ['key' => $max['label'], 'title' => "Destaque: {$max['label']}", 'text' => "{$max['label']} tem o melhor resultado em {$topic}: " . ptNum($max['value']) . '.'];
            $out[] = ['key' => $min['label'], 'title' => "Ponto de atenção: {$min['label']}", 'text' => "{$min['label']} tem o menor resultado em {$topic} (" . ptNum($min['value']) . ') e pede um plano de melhoria.'];
        }
    }
    return $out;
}

/** Destaque que chama alguém de maior/melhor (ou menor/pior) sem ser, pelos dados, cai fora. */
function highlightOk(array $h, array $charts): bool {
    $w = fn(string $re) => '/(?<!\p{L})(?:' . $re . ')(?!\p{L})/u'; // palavra inteira, com acentos
    // Cada trecho ("Extensão tem a maior conformidade", "Gestão a menor") é conferido sozinho
    $clauses = preg_split('/[,;.:!?]|(?<!\p{L})(?:e|mas|enquanto|porém)(?!\p{L})/u', mb_strtolower($h['title'] . '. ' . $h['text']));
    foreach ($clauses as $cl) {
        $top = preg_match($w('maior|melhor|líder|lidera|liderança|mais alt[oa]|primeir[oa]|topo|referência'), $cl);
        $low = preg_match($w('menor|pior|mais baix[oa]|últim[oa]|atenção|abaixo'), $cl);
        if ($top === $low) continue; // nenhum ou os dois: não dá para conferir
        foreach ($charts as $c) {
            if ($c['type'] === 'line') continue;
            $vals = array_column($c['data'], 'value');
            foreach ($c['data'] as $d) {
                if (!preg_match($w(preg_quote(mb_strtolower($d['label']), '/')), $cl)) continue;
                if ($top && $d['value'] < max($vals)) return false;
                if ($low && $d['value'] > min($vals)) return false;
            }
        }
    }
    return true;
}

function cleanSpec(array $s, string $prompt = ''): array {
    $charts = cleanCharts($s['charts'] ?? null, 4);
    // Toda lista do pedido vira gráfico, com os números exatos do pedido
    foreach (promptSeries($prompt) as $series) {
        $found = false;
        foreach ($charts as &$c) {
            if (sameSeries($c, $series)) { $c['data'] = $series['data']; if ($series['type'] === 'line') $c['type'] = 'line'; $found = true; break; }
        }
        unset($c);
        if (!$found && count($charts) < 4) $charts[] = $series;
    }
    foreach ($charts as &$c) {
        $c['title'] = ptText($c['title']);
        $c['insight'] = chartInsight($c);
    }
    unset($c);
    // Números de destaque que só repetem uma barra de gráfico saem (rosca não: "91% satisfeitos" é o destaque)
    $chartLabels = [];
    foreach ($charts as $c) if ($c['type'] === 'bar') foreach ($c['data'] as $d) $chartLabels[] = $d['label'];
    $kpis = array_values(array_filter(cleanKpis($s['kpis'] ?? null, 6), function ($k) use ($chartLabels) {
        foreach ($chartLabels as $l) if (sameLabel($l, $k['label'])) return false;
        return true;
    }));
    $highlights = [];
    foreach (array_slice(arr($s['highlights'] ?? null), 0, 4) as $h) {
        if (!is_array($h) || txt($h['title'] ?? '', 60) === '' || txt($h['text'] ?? '', 260) === '') continue;
        $h = ['title' => ptText(txt($h['title'], 60)), 'text' => ptText(txt($h['text'], 260))];
        if (highlightOk($h, $charts)) $highlights[] = $h;
    }
    if (count($highlights) < 3) {
        foreach (dataHighlights($charts) as $h) {
            if (count($highlights) >= 4) break;
            // Pula o que a IA já comentou (mesmo curso/setor citado)
            $said = implode(' ', array_map(fn($x) => mb_strtolower($x['title'] . ' ' . $x['text']), $highlights));
            if ($h['key'] !== '' && mb_strpos($said, mb_strtolower($h['key'])) !== false) continue;
            unset($h['key']);
            $highlights[] = $h;
        }
    }
    return [
        'kicker'     => mb_strtoupper(txt($s['kicker'] ?? '', 40)),
        'title'      => ptText(txt($s['title'] ?? '', 70)) ?: 'Infográfico',
        'subtitle'   => ptText(txt($s['subtitle'] ?? '', 160)),
        'theme'      => ($s['theme'] ?? '') === 'dark' ? 'dark' : 'light',
        'kpis'       => $kpis,
        'charts'     => $charts,
        'progress'   => cleanProgress($s['progress'] ?? null, 4),
        'highlights' => $highlights,
        'conclusion' => ptText(txt($s['conclusion'] ?? '', 280)),
        'footer'     => ptText(txt($s['footer'] ?? '', 90)),
    ];
}
/** Dados de gráfico no formato do editor: "rótulo;valor" por linha. Null se ficar com menos de 2 itens. */
function cleanData($v): ?string {
    $lines = preg_split('/\r?\n|\\\\n/', (string)$v);
    if (count($lines) === 1) $lines = preg_split('/\s*[,|]\s*(?=[^\d\s])/u', $lines[0]); // "A;1, B;2" numa linha só
    $out = [];
    foreach (array_slice($lines, 0, 12) as $l) {
        $p = preg_split('/[;:\t]/', $l, 2);
        $label = str_replace(';', ' ', txt($p[0] ?? '', 20));
        if ($label === '' || !isset($p[1])) continue;
        $out[] = $label . ';' . num($p[1]);
    }
    return count($out) >= 2 ? implode("\n", $out) : null;
}

/** Números de um texto em pt-BR, normalizados ("1.234" → "1234", "12,50" → "12.5"). */
function textNumbers(string $s): array {
    preg_match_all('/\d+(?:[.,]\d+)*/', $s, $m);
    $out = [];
    foreach ($m[0] as $n) {
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $n)) $n = str_replace('.', '', $n); // separador de milhar
        $n = str_replace(',', '.', $n);
        if (!is_numeric($n)) continue;
        $out[] = rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    }
    return $out;
}

const ASK_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'intro'        => ['type' => 'string'],
        'group_by'     => ['type' => 'string'],
        'metric'       => ['type' => 'string', 'enum' => ['count', 'sum', 'avg']],
        'value_column' => ['type' => 'string'],
        'filters'      => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['column' => ['type' => 'string'], 'value' => ['type' => 'string']],
            'required' => ['column', 'value'],
        ]],
        'date_column'  => ['type' => 'string'],
        'from'         => ['type' => 'string'],
        'to'           => ['type' => 'string'],
        'order'        => ['type' => 'string', 'enum' => ['desc', 'asc']],
        'limit'        => ['type' => 'integer'],
    ],
    'required' => ['intro', 'group_by', 'metric', 'value_column', 'filters', 'date_column', 'from', 'to', 'order', 'limit'],
];
const ASK_SYSTEM = <<<TXT
Você transforma uma pergunta sobre uma planilha numa consulta. Você NÃO calcula nada: o sistema faz as contas.
Campos:
- group_by: nome exato da coluna para agrupar (ex.: "Vendedor"). Use "" se a pergunta for só um total.
- metric: "count" para contar registros; "sum" para somar ou "avg" para a média de uma coluna de número.
- value_column: coluna de número usada em sum ou avg. Use "" em count.
- filters: condições "coluna = valor", com o nome exato da coluna e um dos valores listados. Use [] se não houver.
- date_column, from, to: só quando a pergunta citar um período. Datas no formato aaaa-mm-dd. Ex.: março de 2024 → from 2024-03-01, to 2024-03-31. Sem período: "", "", "".
- order: "desc" para os maiores primeiro ("quem mais"), "asc" para os menores ("quem menos").
- limit: quantos itens mostrar (1 a 20). "Quem mais vendeu" → 1. "Os 5 maiores" → 5. Padrão 10.
- intro: uma frase curta em português explicando o que será mostrado, sem números.
Use só nomes de colunas da lista.
TXT;
const SUMMARY_SYSTEM = <<<TXT
Você escreve o resumo de um painel de dados para um relatório, em português do Brasil.
Escreva 2 ou 3 frases curtas e diretas, com os pontos mais importantes: o maior destaque, metas fora do verde e mudanças grandes em relação ao período anterior, quando houver.
Use SOMENTE os números que aparecem nos fatos, escritos do mesmo jeito (ex.: 12,5%). Não invente números, causas nem recomendações. Não use listas nem títulos.
TXT;

switch (action()) {

case 'status':
    session_write_close();
    // Sem chave só funciona com IA local (Ollama): não adianta chamar um serviço na internet
    $local = preg_match('#^https?://(localhost|127\.|ollama[:/])#i', $AI_URL);
    if ($AI_KEY === '' && !$local) respond(['ok' => false, 'error' => 'IA não configurada: coloque a chave em ai_key.']);
    aiHttp('/models', null, 6);
    respond(['ok' => true, 'model' => $AI_MODEL]);

case 'generate':
    requireMethod('POST');
    $prompt = str(body(), 'prompt', 2000);
    if (mb_strlen($prompt) < 5) fail('Descreva o infográfico que você quer.');
    $prompt = aiPrompt($prompt);
    $spec = askAi(AI_SCHEMA, AI_SYSTEM, $prompt);
    respond(['spec' => cleanSpec($spec, $prompt)]);

case 'edit':
    requireMethod('POST');
    $prompt = str(body(), 'prompt', 2000);
    if (mb_strlen($prompt) < 3) fail('Diga o que a IA deve mudar.');
    // Elementos enviados pelo navegador: só os campos de conteúdo, com ids curtos (e1, e2…)
    $known = [];
    $list  = [];
    foreach (array_slice(arr(body()['elements'] ?? null), 0, 40) as $e) {
        if (!is_array($e)) continue;
        $id = (string)($e['id'] ?? '');
        $type = (string)($e['type'] ?? '');
        if (!preg_match('/^e\d{1,3}$/', $id) || !isset(AI_EDIT_FIELDS[$type]) || isset($known[$id])) continue;
        $item = ['id' => $id, 'type' => $type];
        foreach (AI_EDIT_FIELDS[$type] as $f) {
            $v = (string)($e[$f] ?? '');
            $item[$f] = $f === 'data' ? (cleanData($v) ?? '') : ($f === 'text' ? mb_substr(trim($v), 0, 600) : txt($v, 80));
        }
        $known[$id] = $type;
        $list[] = $item;
    }
    // Página vazia: a IA ainda pode adicionar elementos ou trocar o tema
    $out = askAi(AI_EDIT_SCHEMA, AI_EDIT_SYSTEM,
        "Elementos: " . json_encode($list, JSON_UNESCAPED_UNICODE) . "\n\nPedido: " . aiPrompt($prompt));

    $changes = [];
    foreach (array_slice(arr($out['changes'] ?? null), 0, 80) as $c) {
        if (!is_array($c)) continue;
        $id = (string)($c['id'] ?? '');
        $f  = (string)($c['field'] ?? '');
        if (!isset($known[$id]) || !in_array($f, AI_EDIT_FIELDS[$known[$id]], true)) continue;
        $v = (string)($c['value'] ?? '');
        if ($f === 'value') $v = (string)($known[$id] === 'progress' ? num($v, 0, 100) : num($v));
        elseif ($f === 'data') { $v = cleanData($v); if ($v === null) continue; }
        elseif ($f === 'text') $v = mb_substr(trim($v), 0, 600);
        else $v = txt($v, $f === 'prefix' ? 6 : ($f === 'suffix' ? 8 : 80));
        if ($v === '' && in_array($f, ['text', 'label', 'title'], true)) continue;
        $changes[] = ['id' => $id, 'field' => $f, 'value' => $v];
    }
    $add = arr($out['add'] ?? null);
    $theme = $out['theme'] ?? 'keep';
    respond([
        'changes' => $changes,
        'add'     => ['kpis' => cleanKpis($add['kpis'] ?? null, 3), 'charts' => cleanCharts($add['charts'] ?? null, 2),
                      'progress' => cleanProgress($add['progress'] ?? null, 3)],
        'theme'   => in_array($theme, ['light', 'dark'], true) ? $theme : 'keep',
    ]);

case 'assist':
    requireMethod('POST');
    $b = body();
    $screen = str($b, 'screen', 40);
    $clean = [];
    foreach (array_slice(is_array($b['messages'] ?? null) ? $b['messages'] : [], -8) as $m) {
        $role = is_array($m) ? (string)($m['role'] ?? '') : '';
        $text = is_array($m) ? mb_substr(trim((string)($m['content'] ?? '')), 0, 800) : '';
        if (in_array($role, ['user', 'assistant'], true) && $text !== '') $clean[] = ['role' => $role, 'content' => $text];
    }
    if (!$clean || $clean[count($clean) - 1]['role'] !== 'user') fail('Escreva sua pergunta.');
    $key = 'assist:' . $uid;
    if (tooManyAttempts($key, 60, 3600)) fail('Muitas perguntas à Cápsula na última hora. Espere um pouco.', 429);
    recordAttempt($key);
    session_write_close();
    set_time_limit(150);
    // Nomes das planilhas do CRM da pessoa (para o comando achar a certa)
    $sheetNames = [];
    foreach (array_slice(is_array($b['sheets'] ?? null) ? $b['sheets'] : [], 0, 30) as $n) {
        $n = mb_substr(trim((string)$n), 0, 60);
        if ($n !== '') $sheetNames[] = $n;
    }
    $system = ASSIST_SYSTEM . personalidadePrompt($uid) . assistContexto($uid) . "\nTela em que a pessoa está agora: " . ($screen !== '' ? $screen : 'não informada') . '.'
        . ($sheetNames ? "\nPlanilhas do CRM: " . implode(' | ', $sheetNames) . '.' : "\nPlanilhas do CRM: nenhuma informada.");
    $out = aiChat(ASSIST_SCHEMA, array_merge([['role' => 'system', 'content' => $system]], $clean), 0.3, 120);
    if (!is_array($out)) fail('A Cápsula não entendeu. Tente perguntar de outro jeito.', 502);
    $action = in_array($out['action'] ?? '', ASSIST_TARGETS, true) ? $out['action'] : 'none';
    $target = array_key_exists($out['target'] ?? '', ASSIST_ANCHORS) ? $out['target'] : null;
    $command = in_array($out['command'] ?? '', ASSIST_COMMANDS, true) ? $out['command'] : 'none';
    $arg = mb_substr(trim((string)($out['arg'] ?? '')), 0, 60);
    $reply = mb_substr(trim((string)($out['reply'] ?? '')), 0, 900);
    // Pedidos comuns têm prioridade na regra fixa (o modelo pequeno erra o nome e o comando às vezes)
    [$inferred, $inferredArg] = assistInfer($clean[count($clean) - 1]['content'], $sheetNames);
    if ($inferred !== 'none') {
        $command = $inferred;
        $arg = $inferredArg;
    }
    // Abrir ou apagar planilha só vale com um nome que existe na conta (o modelo às vezes inventa, ex.: "nome da planilha")
    if (in_array($command, ['crm_open_sheet', 'crm_delete_sheet'], true)) {
        $existe = array_values(array_filter($sheetNames, fn($n) => mb_strtolower($n) === mb_strtolower($arg)));
        if ($existe) $arg = $existe[0];
        else { $command = 'none'; $arg = ''; }
    }
    // Resposta que é só um código (ex.: "crm_filter_late") não serve para a pessoa
    if ($reply === '' || preg_match('/^[a-z_]+$/', $reply) || preg_match('/[a-z]+_[a-z_]+\(|\(arg=/', $reply)) {
        $reply = 'Pronto, vou fazer isso na tela.';
    }
    respond([
        'reply'   => $reply,
        'action'  => $action === 'none' ? null : $action,
        'target'  => $target,
        'command' => $command === 'none' ? null : $command,
        'arg'     => $arg === '' ? null : $arg,
    ]);

case 'ask':
    // Pergunte aos dados: a IA só escolhe a consulta; o navegador calcula o resultado com os dados reais
    requireMethod('POST');
    $b = body();
    $q = str($b, 'question', 300);
    if (mb_strlen($q) < 4) fail('Escreva a sua pergunta sobre os dados.');
    $columns = [];
    foreach (array_slice(arr($b['columns'] ?? null), 0, 60) as $c) {
        if (!is_array($c)) continue;
        $name = txt($c['name'] ?? '', 80);
        $type = in_array($c['type'] ?? '', ['date', 'number', 'text'], true) ? $c['type'] : 'text';
        if ($name === '' || isset($columns[mb_strtolower($name)])) continue;
        $vals = [];
        foreach (array_slice(arr($c['values'] ?? null), 0, 40) as $v) { $v = txt($v, 60); if ($v !== '') $vals[] = $v; }
        $columns[mb_strtolower($name)] = ['name' => $name, 'type' => $type, 'values' => $vals];
    }
    if (!$columns) fail('Abra uma planilha antes de perguntar.');
    $desc = [];
    foreach ($columns as $c) {
        $desc[] = '- ' . $c['name'] . ' (' . ['date' => 'data', 'number' => 'número', 'text' => 'texto'][$c['type']] . ')'
            . ($c['values'] ? ': ' . implode(' | ', array_slice($c['values'], 0, 25)) : '');
    }
    $out = askAi(ASK_SCHEMA, ASK_SYSTEM, "Colunas da planilha:\n" . implode("\n", $desc) . "\n\nPergunta: " . aiPrompt($q));
    // Confere tudo o que a IA devolveu: só colunas e valores que existem na planilha
    $col = function ($v) use ($columns): ?array { return $columns[mb_strtolower(trim((string)$v))] ?? null; };
    $group = $col($out['group_by'] ?? '');
    if (!$group) {
        // Sem coluna escolhida: usa a primeira coluna citada na pergunta
        foreach ($columns as $k => $c) if ($c['type'] !== 'number' && mb_stripos($q, $c['name']) !== false) { $group = $c; break; }
    }
    $metric = in_array($out['metric'] ?? '', ['count', 'sum', 'avg'], true) ? $out['metric'] : 'count';
    $valCol = $col($out['value_column'] ?? '');
    if ($metric !== 'count' && (!$valCol || $valCol['type'] !== 'number')) { $metric = 'count'; $valCol = null; }
    $filters = [];
    foreach (array_slice(arr($out['filters'] ?? null), 0, 4) as $f) {
        $c = is_array($f) ? $col($f['column'] ?? '') : null;
        if (!$c || $c['type'] === 'date') continue;
        $want = mb_strtolower(trim((string)($f['value'] ?? '')));
        foreach ($c['values'] as $v) if (mb_strtolower($v) === $want) { $filters[] = ['column' => $c['name'], 'value' => $v]; break; }
    }
    $dateCol = $col($out['date_column'] ?? '');
    $okDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? (string)$d : '';
    $from = $okDate($out['from'] ?? '');
    $to = $okDate($out['to'] ?? '');
    if (!$dateCol || $dateCol['type'] !== 'date') { $dateCol = null; $from = $to = ''; }
    if ($from === '' && $to === '') $dateCol = null;
    respond(['query' => [
        'intro'   => txt($out['intro'] ?? '', 200),
        'group'   => $group['name'] ?? null,
        'metric'  => $metric,
        'value'   => $valCol['name'] ?? null,
        'filters' => $filters,
        'date'    => $dateCol ? ['column' => $dateCol['name'], 'from' => $from, 'to' => $to] : null,
        'order'   => ($out['order'] ?? '') === 'asc' ? 'asc' : 'desc',
        'limit'   => max(1, min(20, (int)($out['limit'] ?? 10) ?: 10)),
    ]]);

case 'summary':
    // Resumo do painel em 2 ou 3 frases. Os números vêm prontos do navegador; a IA só escreve.
    requireMethod('POST');
    $facts = mb_substr(trim((string)(body()['facts'] ?? '')), 0, 4000);
    if (mb_strlen($facts) < 20) fail('Não há dados suficientes para resumir.');
    $out = askAi(['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary']],
        SUMMARY_SYSTEM, "Fatos:\n" . $facts);
    $sum = txt($out['summary'] ?? '', 700);
    if (mb_strlen($sum) < 20) fail('A IA não conseguiu resumir. Tente de novo.', 502);
    // Todo número do resumo precisa estar nos fatos (o modelo pequeno às vezes inventa)
    $known = array_flip(textNumbers($facts));
    foreach (textNumbers($sum) as $n) if (!isset($known[$n])) fail('A IA usou um número que não está nos dados. Tente de novo.', 502);
    respond(['summary' => $sum]);

default:
    fail('Ação desconhecida.', 404);
}
