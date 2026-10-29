# Acredite.se

Sistema web para analisar planilhas e criar infográficos, apresentações e painéis, com IA, CRM de planilhas, equipes e biblioteca compartilhada.

**Tecnologias:** HTML/CSS/JS (tela) · PHP 8+ (API) · MariaDB/MySQL (banco) · IA no formato da API da OpenAI (padrão: Groq, grátis)

## Como rodar

| Opção | Para quem |
|---|---|
| [A. Docker](#a-docker-mais-fácil) | Qualquer computador com Docker. Não precisa instalar PHP nem banco |
| [B. PHP + MariaDB/MySQL na máquina](#b-php--mariadbmysql-na-máquina) | Quem já tem (ou prefere instalar) PHP e banco, como XAMPP ou Laragon |
| [C. Hospedagem](#c-publicar-em-hospedagem) | Publicar na internet (Hostinger, HostGator, Locaweb, KingHost…) |

Em todas as opções, para a IA funcionar, você precisa de uma chave grátis do Groq. Veja [Configurar a IA](#configurar-a-ia-groq-grátis).

### A. Docker (mais fácil)

Precisa do [Docker Desktop](https://www.docker.com/products/docker-desktop/).

```bash
git clone https://github.com/BrenoNunesC/acredite-se.git
cd acredite-se
cp .env.example .env      # no Windows: copy .env.example .env
# edite o .env e coloque a chave do Groq em AI_KEY=
docker compose up -d
```

Abra **http://localhost:8080** e clique em **Criar conta**. A primeira conta vira administradora do sistema.

- O banco (MariaDB) sobe junto e as tabelas são criadas sozinhas.
- Para conectar no banco com o Workbench ou o DBeaver: `localhost:3307`, usuário `acredite`, senha `acredite`. Portas e senhas podem ser trocadas no `.env`.
- Para parar: `docker compose down`. Para apagar tudo, inclusive os dados: `docker compose down -v`.
- Depois de mudar o código ou o `.env`: `docker compose up -d --build`.

### B. PHP + MariaDB/MySQL na máquina

Precisa de **PHP 8.0+** (com as extensões `pdo_mysql`, `mbstring` e `openssl`) e de **MariaDB 10.3+** ou **MySQL 5.7+**.

1. Clone o projeto (no XAMPP, dentro de `C:\xampp\htdocs\`):
   ```bash
   git clone https://github.com/BrenoNunesC/acredite-se.git
   ```
   No Windows, se faltar algo: `winget install PHP.PHP.8.4` e `winget install MariaDB.Server`.
2. Crie o banco e um usuário para o site (phpMyAdmin → aba *SQL*, ou no terminal `mariadb -u root -p`):
   ```sql
   CREATE DATABASE acredite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'acredite'@'localhost' IDENTIFIED BY 'uma-senha-forte';
   GRANT ALL PRIVILEGES ON acredite.* TO 'acredite'@'localhost';
   ```
   As tabelas são criadas sozinhas no primeiro acesso. Se preferir, importe `database.sql` à mão.
3. Copie `api/config.local.example.php` para **`api/config.local.php`** e preencha os dados do banco, a chave da IA e `'debug' => true`, que mostra na tela o código de recuperação de senha.
4. Rode, na pasta do projeto:
   - **Só neste PC:** `php -S localhost:8000 servidor-teste.php` e abra http://localhost:8000
   - **Na rede local** (celular e outros PCs): `powershell -ExecutionPolicy Bypass -File .\iniciar-teste.ps1`
   - **XAMPP:** inicie o Apache e o MySQL e abra http://localhost/acredite-se

> O `api/config.local.php` está no `.gitignore`. Cada computador tem o seu, e senhas e chaves não vão para o GitHub.

### C. Publicar em hospedagem

1. Crie o banco MariaDB/MySQL no painel da hospedagem e anote o nome do banco, o usuário e a senha.
2. Importe `database.sql` no phpMyAdmin (aba *Importar*). Esse passo é opcional, porque o sistema cria as tabelas no primeiro acesso.
3. Crie o `api/config.local.php` a partir do `config.local.example.php`, com os dados do banco, a chave da IA e o `mail_from` do seu domínio. Deixe `'debug' => false`.
4. Envie os arquivos para a pasta pública (geralmente `public_html`), mantendo as pastas `api/` e `storage/`.
5. Dê permissão de escrita à pasta `storage/` (755 ou 775 no gerenciador de arquivos).
6. Abra o site, clique em **Criar conta** e comece a usar. **A primeira conta criada vira administradora do sistema.**

Depois que o SSL (cadeado) estiver ativo, tire o `#` das 3 linhas de "Força HTTPS" no `.htaccess`.

O banco se atualiza sozinho: no primeiro acesso, `api/schema.php` cria as tabelas de equipes, convites, comentários, atividade, CRM e administração (e as colunas novas). Não é preciso rodar SQL ao publicar versões novas.

## Configurar a IA (Groq, grátis)

A IA gera e edita infográficos, responde perguntas sobre os dados, escreve o resumo do painel e faz a Cápsula conversar. Ela roda na nuvem: não precisa baixar nada, e funciona inclusive em hospedagem compartilhada.

1. Entre em **https://console.groq.com** com uma conta do Google ou do GitHub. Não pede cartão.
2. Vá em **API Keys** → **Create API Key** e copie a chave (começa com `gsk_`). Ela só aparece uma vez.
3. Coloque a chave:
   - **Docker:** no `.env`, em `AI_KEY=gsk_...`, e rode `docker compose up -d`.
   - **PHP/hospedagem:** no `api/config.local.php`, em `'ai_key' => 'gsk_...'`.

**Não coloque a chave em nenhum arquivo que vá para o GitHub.** Ela funciona como uma senha. Se ela vazar, apague-a no console do Groq e crie outra.

Modelos sugeridos (`ai_model`):
- `openai/gpt-oss-120b` (padrão): o mais inteligente, bom em português e em JSON.
- `openai/gpt-oss-20b`: mais rápido e com limite maior, mas acerta menos.

Os modelos disponíveis mudam com o tempo. A lista atual fica em console.groq.com → Docs → Models. Se aparecer "Modelo de IA não encontrado", troque o `ai_model`.

O plano grátis tem limite por minuto e por dia (veja em console.groq.com → Settings → Limits). Se o limite for atingido, o sistema avisa e o resto continua funcionando. Para trocar de serviço, mude `ai_url`, `ai_key` e `ai_model`. Funcionam Google Gemini, OpenRouter, GitHub Models e Ollama local, e há exemplos em `api/config.local.example.php`.

Os textos e números que a IA analisa (o pedido, as colunas e alguns valores da planilha) são enviados ao serviço escolhido. Se os dados forem sensíveis, leia a política de privacidade do serviço ou use o Ollama local.

## Configuração

Os valores padrão ficam em `api/config.php`, que vai para o GitHub sem senhas. Para mudar algum valor sem editar esse arquivo:

- **`api/config.local.php`**: ponha ali só as chaves que mudam (veja o exemplo).
- **Variáveis de ambiente**: `ACREDITE_DB_HOST`, `ACREDITE_DB_PORT`, `ACREDITE_DB_NAME`, `ACREDITE_DB_USER`, `ACREDITE_DB_PASS`, `ACREDITE_AI_URL`, `ACREDITE_AI_KEY`, `ACREDITE_AI_MODEL`, `ACREDITE_MAIL_FROM`, `ACREDITE_APP_URL`, `ACREDITE_CRON_KEY` e `ACREDITE_DEBUG`.

## Administração do sistema

Quem é administrador do sistema vê **Administração** no menu lateral (e no menu ⋯ do painel):

- **Visão geral**: números de usuários, equipes, itens e acessos; avisos do servidor (modo de teste, remetente dos e-mails, agendamento do relatório); e a opção **Novos cadastros** — desligada, só entra quem tiver convite de uma equipe.
- **Usuários**: busca, filtros, bloquear/desbloquear (a pessoa é desconectada na hora), tornar ou tirar administrador, gerar **senha temporária** (mostrada uma única vez) e excluir conta.
- **Equipes**: todas as equipes do sistema, seus membros, e excluir equipe.
- **Registro**: tudo o que os administradores fizeram.

O sistema nunca fica sem administrador: não é possível bloquear, rebaixar ou excluir o último. Para definir administradores pelo e-mail (por exemplo, se a primeira conta não for a sua), acrescente em `api/config.local.php`:

```php
'admin_emails' => ['voce@seudominio.com.br'],
```

## Equipes

Na tela **Equipes** (menu lateral) cada pessoa pode criar várias equipes e participar de outras. Em cada equipe ela tem um papel:

- **Administrador**: convida (por e-mail, código ou link), muda papéis, remove pessoas, edita e exclui a equipe.
- **Editor**: cria e edita modelos e itens da biblioteca da equipe.
- **Leitor**: vê, comenta e apresenta.

Convites por e-mail aparecem para a pessoa ao entrar no sistema (ou depois de se cadastrar com aquele e-mail). O link de convite tem o formato `https://seusite.com.br/?equipe=CODIGO`.

## E-mails da equipe (alertas de meta e relatório mensal)

Os e-mails usam a mesma função `mail()` da recuperação de senha. Na tela **Equipes**, um administrador da equipe liga ou desliga:

- **Alertas de meta**: quando alguém salva um item da equipe (ou com um modelo da equipe) com meta fora do verde, os administradores recebem um aviso. No máximo um aviso por item e 20 por dia.
- **Relatório mensal**: resumo dos itens da equipe e das metas, para todos os membros. O botão **Enviar agora** manda na hora.
- **Lembrete do CRM** (por pessoa): um e-mail por dia com os próximos contatos de hoje e os atrasados em que a pessoa é responsável. Cada um liga ou desliga em **CRM de planilhas → Lembretes → Lembrete diário por e-mail**. Os mesmos lembretes aparecem no Início e no CRM.
- **Resumo do painel**: o texto do resumo (simples ou escrito pela IA) é guardado com a apresentação e entra no relatório mensal.

Para o relatório sair sozinho todo mês, agende na hospedagem (painel → "Cron jobs" / "Tarefas agendadas") para rodar **uma vez por dia** — cada equipe recebe no máximo um por mês:

```
php /home/SEU_USUARIO/public_html/api/cron.php
```

Se a hospedagem só aceitar um endereço, acrescente em `api/config.local.php` uma chave secreta e use o endereço com ela:

```php
'cron_key' => 'troque-por-uma-frase-longa-e-secreta',
'app_url'  => 'https://seusite.com.br/',   // endereço usado nos links dos e-mails (opcional)
```

```
https://seusite.com.br/api/cron.php?key=troque-por-uma-frase-longa-e-secreta
```

Com `'debug' => true` (teste local) nenhum e-mail é enviado: as mensagens ficam em `storage/outbox/` para conferência.

## Gerar infográfico com IA

Em **Criar infográfico**, a pessoa descreve o que quer (ex.: "Relatório 2026: 94% de conformidade. Conformidade por setor: Ensino 92, Pesquisa 78. Avaliações por mês: Mai 18, Jun 26, Jul 31") e clica em **Gerar com IA**. A IA monta números de destaque, gráficos, destaques e conclusão, em quantas páginas forem precisas.

- Cada lista no formato `Título: Nome 12, Outro 34` vira um gráfico com os números exatos (meses, trimestres e anos viram gráfico de linha).
- A frase embaixo de cada gráfico e os destaques básicos (maior, menor, tendência) são calculados pelo sistema, não pela IA, então sempre batem com os dados.
- No editor, o botão **IA** (ou `Ctrl+I`) edita o que já existe: troca textos e números, adiciona gráficos e muda o tema. Com elementos selecionados, mexe só neles. **Refazer do zero** monta tudo de novo.

## Gerar infográfico a partir de dados (sem IA)

Na tela **Criar infográfico**, a seção **Gerar a partir de dados** monta o infográfico direto da planilha, sem IA e com os números exatos:

- **Usar a planilha aberta**: usa a planilha que está aberta no painel.
- **Importar do Ramper**: escolha a exportação do Ramper (.xlsx, .xls ou .csv). O sistema acha o cabeçalho sozinho.

O sistema conta as categorias (gráfico de rosca ou barras), soma e tira a média das colunas numéricas, agrupa as datas por mês (gráfico de linha) e escreve os destaques. Escolha **Apresentação** (16:9) ou um formato de infográfico antes de gerar.

## CRM de planilhas

- **Planilha em branco**: dê um nome e as colunas (separadas por vírgula) e adicione as linhas depois com **Nova linha**. **Nova coluna** acrescenta campos a uma planilha existente.
- **Importar do Ramper**: a coluna de etapa (Etapa, Status, Fase ou Funil) vira as etapas do CRM. Etapas como "Ganho", "Fechado" ou "Perdido" contam como concluídas.
- Abra uma linha para editar os campos e salvar.
- **Colunas:** o botão Colunas permite renomear e excluir colunas. Excluir pede confirmação e apaga os dados daquela coluna em todas as linhas.
- **Compartilhar:** o seletor no topo da planilha envia a planilha para uma equipe em que você é editor ou administrador.
- **Baixar Excel:** gera um arquivo .xlsx com as colunas, a etapa, o responsável e o próximo contato.
- **Situação:** o filtro "Todas as situações" separa as linhas em aberto e as concluídas.
- **Kanban:** o seletor **Tabela | Kanban** mostra uma coluna por etapa, com os mesmos filtros da tabela. No computador, arraste o cartão para outra coluna; no celular, use o seletor dentro do cartão. A escolha da visualização fica salva neste navegador.
- **Gerar infográfico** e **Gerar apresentação** criam o material com os dados do CRM: em aberto, atrasadas, percentual concluído, etapas e responsáveis.

## Animações e mascote

Cada bloco tem o campo **Animação de entrada** no painel de propriedades: padrão, desliza da esquerda, cai de cima, cresce com salto, gira na horizontal ou sem animação. Barras podem **balançar** ao entrar e a rosca pode **girar** ao entrar.

O **mascote** é a cápsula de remédio em pixel art (branca em cima, roxa embaixo). Na apresentação e em **Ver animação aqui**, ele:

- leva os blocos (cards, números, barras, gráficos) até o lugar deles; o que está dentro de um card vai junto com ele;
- pula sobre o maior gráfico do bloco, que balança.

Ele fica de pé, com a parte branca em cima e a roxa embaixo.

Na **apresentação** das planilhas (botão Apresentar), o mascote leva os números e os painéis do slide de visão geral até o lugar deles, a cada abertura. A lua no rodapé liga o modo escuro. A escolha vale para as próximas apresentações nesta visita. Os PNG, PDF e PowerPoint continuam no tema claro.

Ele aparece nos infográficos por padrão.

A velocidade do mascote fica na **barra de velocidade**, no canto inferior esquerdo, que aparece nas **apresentações** (slides e infográfico em tela cheia). O ícone da cápsula recolhe e expande a barra. A escolha é salva neste navegador.

Os botões de desfazer/refazer, animação e exportação do editor de infográficos ficam agrupados na barra.

## Cápsula: assistente virtual (IA)

A **Cápsula** fica no canto inferior direito, em todas as telas, inclusive nas apresentações. Clique nela para conversar: ela explica qualquer parte do sistema e, quando você cita uma página (por exemplo, "me leva para o CRM" ou "onde ficam as equipes?"), caminha até o botão dessa página na tela em que você está e o destaca. Ela usa a mesma IA dos infográficos (veja [Configurar a IA](#configurar-a-ia-groq-grátis)). Se a IA estiver indisponível, o painel avisa "IA indisponível" e o restante do sistema funciona normalmente.

A resposta também aparece **na tela**: a Cápsula caminha até o botão, bloco ou campo que responde a dúvida, põe um anel de destaque em volta dele e mostra a resposta num balão. No Início, ela aponta o cartão da página; nas telas de análise, o item do menu lateral; dentro de um módulo, aponta o **Voltar** e explica o caminho ("Clique em Voltar para ir ao Início e depois em …"). Se a conversa estiver na frente do botão, ela se fecha. Clicar no botão destacado abre a página e encerra o destaque; **Entendi** ou Esc também fecham o balão. A resposta também fica no painel de conversa.

A Cápsula também **faz coisas na tela**, quando você pede: abrir uma planilha do CRM, criar uma planilha em branco, filtrar atrasadas, em aberto ou concluídas, baixar o Excel, gerar infográfico ou apresentação da planilha e apresentar a análise aberta. Ela tem uma página própria, **Minha Cápsula**, que abre pela tela Início, pelo botão **Aparência** da Cápsula ou por **Configurações → Cápsula**. A página mostra uma prévia grande e as opções em abas (Corpo, Rosto, Cabelo, Chapéu, Bigode e Destaque), com miniaturas mostrando o resultado de cada opção. A escolha é salva **na sua conta**, então vale em qualquer computador em que você entrar. Opções:
- **Cor do corpo:** 9 cores.
- **Óculos:** sem óculos, redondos, quadrados ou escuros.
- **Boca:** padrão, sorriso, surpresa ou neutra.
- **Cabelo:** 8 estilos, em 7 cores.
- **Chapéu:** 8 modelos (cartola, boné, coroa, festa, capacete, palha, gorro).
- **Bigode:** 8 estilos, em 7 cores.
- **Destaque dos acessórios:** contorno branco, dourado ou neon em volta de cabelo, chapéu e bigode, para eles não se perderem no contorno do personagem. Pode ser desligado.
- **Sortear** cria uma combinação aleatória; **Restaurar padrão** volta ao visual original.

**Sobreposições:** quando duas escolhas se encontram, o desenho resolve sozinho e a página mostra um aviso. Com chapéu que cobre a cabeça (cartola, boné, capacete, gorro, palha), o topo do cabelo fica escondido e só as laterais aparecem. Os bigodes ficam sempre abaixo das lentes (a partir da linha 17 da grade), e a barba deixa a região da boca livre. O destaque nunca pinta por cima do rosto.

As opções válidas ficam em `api/capsule.php` (guardadas na conta) e no `index.html` (CAPSULE_OPTIONS). Se você acrescentar uma opção, atualize as duas listas. A escolha vale em todas as telas e fica salva neste navegador. O desenho usa uma grade mais fina (16×32), com brilho nos olhos, e os chapéus, cabelos e bigodes são desenhados linha a linha em `index.html` (ver `CAPSULE_ART`), o que facilita criar novas opções.

Ela só executa comandos de uma lista fixa. Excluir uma planilha ou uma coluna sempre pede a sua confirmação antes.

Cada pessoa pode fazer até 60 perguntas por hora. A Cápsula responde só sobre o sistema.

**O que a Cápsula sabe:** tudo o que ela explica vem de `api/conhecimento.md`, uma base com uma seção por assunto (conta, análise, painel, exportação, infográfico, CRM, biblioteca, equipes, configurações, administração…). A cada pergunta, o servidor escolhe as 3 seções mais ligadas à pergunta e manda só elas para a IA, porque o plano grátis tem limite de tokens por minuto. **Quando mudar ou criar uma tela, atualize a seção correspondente** com os nomes exatos dos botões e, na linha `palavras:`, os termos que as pessoas usariam para perguntar. Se algo não estiver na base, a Cápsula diz que não sabe em vez de inventar.

## Registro de erros

Os erros do servidor (exceções, falhas fatais e respostas 500) e os erros de script do navegador são gravados em `storage/errors.log`, uma linha por erro. O arquivo fica protegido, como a pasta `storage/`. Quando passa de 1 MB, mantém só os últimos 500 erros. Os dados de login e senhas não entram no registro.

O administrador vê os últimos 200 erros em **Administração → Erros** e pode limpar o registro. Cada página envia no máximo 20 erros do navegador, sem repetir a mesma mensagem, e cada endereço tem limite de envio.

## Tutorial

No primeiro acesso, a cápsula apresenta o sistema: ela leva a atenção para cada parte da tela Início e explica o que faz. Use **Próximo** e **Anterior** (ou as setas do teclado), **Pular** ou Esc para sair. Para rever, clique em **Ver tutorial** na tela Início.

## Tela Início

A **tela Início** é a tela principal: o sistema abre nela depois do login, e fechar qualquer módulo (Voltar ou Esc) traz você de volta para ela. Na faixa de destaque, a cápsula é interativa: **arraste** para mover (ela fica onde você soltar), **um toque** faz ela pular, piscar e falar, e **dois toques** a devolvem ao lugar. Ela tem três ações de criação (nova análise, novo infográfico e nova planilha no CRM), os módulos (Biblioteca, Equipes, Configurações e, para administradores, Administração) e os últimos itens salvos. Para voltar a ela de qualquer tela, use **Início** no menu lateral, o ícone de casa no editor de infográficos ou clique no logotipo **acredite.se** no canto superior (também funciona com Tab e Enter). Um link de convite de equipe (`?equipe=CODIGO`) continua abrindo a página Equipes. O botão da cápsula no topo do editor liga e desliga o mascote só naquele design. Quem pede menos movimento no sistema não vê o mascote.

## Páginas e apresentação

Um infográfico pode ter até 20 páginas. A barra no canto do editor troca de página, cria, duplica, move e exclui (`PgUp`/`PgDn` também trocam). O **PDF** sai com todas as páginas; o **PNG**, com a página aberta.

**Apresentar** (ou `F5`) abre em tela cheia, uma página por vez, com as animações. Setas, espaço ou clique avançam; `R` repete a animação; `Esc` sai. Nos designs salvos, o botão ▶ apresenta direto.

A IA escreve só o conteúdo (textos e números); o sistema faz a diagramação. Cada pessoa pode fazer até 30 pedidos por hora.

## Estrutura

```
index.html            → o sistema (tela e JavaScript)
style.css             → o visual do sistema (cores, layout, animações)
api/
  config.php          → configurações padrão (sem senhas)
  config.local.example.php → modelo da configuração de cada computador (copie para config.local.php)
  bootstrap.php       → funções comuns (sessão, banco, segurança)
  auth.php            → cadastro, login, sair, recuperar senha
  models.php          → modelos de análise personalizados
  library.php         → biblioteca de apresentações (salvar, renomear, compartilhar, equipe)
  share.php           → abre uma apresentação compartilhada por link (sem login)
  presentation.php    → funções comuns da biblioteca
  team.php            → equipes (criar, convites, papéis, opções, excluir, sair)
  teamlib.php         → funções comuns de equipe (e-mails, metas, relatório)
  admin.php           → administração do sistema (usuários, equipes, configurações, registro)
  ai.php              → IA: infográficos, perguntas aos dados, resumo e Cápsula (Groq ou outro serviço)
  conhecimento.md     → o que a Cápsula sabe sobre o sistema (uma seção por assunto)
  schema.php          → atualiza o banco automaticamente
  cron.php            → envia o relatório mensal e o lembrete diário do CRM (agendar na hospedagem, 1 vez por dia)
storage/              → PNGs antigos da biblioteca (protegida)
database.sql          → tabelas básicas do MariaDB/MySQL (o resto é criado por schema.php)
Dockerfile, docker-compose.yml → ambiente pronto com PHP + MariaDB (docker compose up -d)
```

## Recuperar senha

O sistema envia um **código de 6 dígitos por e-mail** usando a função `mail()` do PHP. A maioria das hospedagens já tem isso funcionando. Se o e-mail não chegar, crie uma conta de e-mail no painel com o mesmo endereço de `mail_from`.

## Segurança incluída

- Senhas guardadas com hash (`password_hash`), nunca em texto
- Sessão com cookie `HttpOnly`
- Bloqueio após 8 tentativas de login erradas (15 minutos)
- Cada usuário só vê os próprios modelos e infográficos
- Pasta `storage/` e arquivos de configuração bloqueados para acesso direto
- Proteção contra envio de formulários de outros sites (CSRF)

Se o servidor for **Nginx** (não Apache), os arquivos `.htaccess` não funcionam: peça ao suporte para bloquear o acesso às pastas `storage/` e aos arquivos `api/config*.php`, `api/bootstrap.php`, `api/schema.php`, `api/teamlib.php`, `api/presentation.php` e `*.sql`.
