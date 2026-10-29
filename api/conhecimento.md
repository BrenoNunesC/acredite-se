# Base de conhecimento da Cápsula
<!--
Cada seção começa com "## Título" e uma linha "palavras:" com termos de busca.
A cada pergunta, api/ai.php escolhe as seções mais parecidas com a pergunta e manda só elas para a IA
(o plano grátis tem limite de tokens por minuto). Escreva o que a pessoa vê na tela, com os nomes exatos dos botões.
-->

## Navegação: como chegar em cada tela
palavras: onde fica, onde encontro, como chego, abrir, acessar, ir para, menu, voltar, navegar, tela, página, módulo, início
Depois do login o sistema abre na tela Início. Ela tem a faixa "Olá", o botão Ver tutorial, a seção Criar (cartões Nova análise, Novo infográfico e Nova planilha no CRM) e a seção Explorar (cartões Biblioteca, Equipes, Configurações, Minha Cápsula e, só para administradores, Administração), além de Lembretes do CRM e Recentes (com Ver tudo).
Os módulos (Equipes, CRM de planilhas, Criar infográfico, Administração, Minha Cápsula) abrem por cima, com o botão Voltar no canto superior esquerdo; Voltar ou Esc levam de volta ao Início.
As telas de análise (Modelos/Nova análise, Biblioteca, Configurações e o painel da planilha) têm um menu lateral à esquerda com Início, Modelos, Biblioteca, Criar infográfico, CRM de planilhas, Equipes, Configurações e Administração (só administradores), e embaixo o nome da pessoa e Sair da conta.
Clicar no logotipo acredite.se no topo também volta ao Início. Não existe menu superior.

## Conta: entrar, criar conta, sair e senha
palavras: login, entrar, criar conta, cadastro, cadastrar, senha, esqueci, recuperar, redefinir, trocar senha, mudar senha, sair, logout, conta, e-mail, código
Entrar: informe e-mail e senha e clique em Entrar. Depois de 8 tentativas erradas, o acesso fica bloqueado por 15 minutos.
Criar conta: na tela de login, clique em Criar conta, preencha Nome completo, E-mail, Senha e Confirmar senha. A senha precisa ter pelo menos 6 caracteres. A primeira conta do sistema vira administradora. Se o administrador desligou Novos cadastros, só entra quem tem convite de equipe.
Esqueci a senha: na tela de login, clique em Esqueci a senha, digite o E-mail cadastrado e clique em Localizar conta. O sistema envia um código de 6 dígitos para o e-mail (vale 15 minutos). Na tela "Confira seu e-mail", digite o Código recebido, a Nova senha e Confirmar nova senha, e clique em Redefinir senha. Depois, Ir para login.
Trocar a senha: não há opção de trocar a senha dentro do sistema; use Esqueci a senha na tela de login. Um administrador do sistema também pode gerar uma Senha temporária em Administração → Usuários.
Sair: no menu lateral, clique em Sair da conta. A sessão também termina sozinha depois de 8 horas.

## Nova análise: enviar uma planilha
palavras: nova análise, analisar, planilha, enviar, carregar, arquivo, excel, xlsx, xls, csv, upload, arrastar, modelo de análise, exemplo, google sheets, sheets, link
Abra Nova análise (cartão no Início ou Modelos no menu lateral). Primeiro escolha um Modelo de análise na grade; depois arraste o arquivo para "Arraste seu arquivo aqui" ou clique para escolher (.xlsx, .xls ou .csv). O painel abre em seguida.
Importar do Google Sheets: cole o link da planilha; ela precisa estar com acesso "Qualquer pessoa com o link" para leitura.
Carregar exemplo: abre uma planilha de exemplo para ver como funciona.
O modelo pronto "Breno" analisa os campos Módulo, Origem, Esfera e Status. Datas no formato dd/mm/aaaa são reconhecidas.

## Modelos de análise: criar e editar
palavras: modelo, criar modelo, novo modelo, editar modelo, campos, campos prioritários, sugeridos, salvar modelo, modelo personalizado, modelo da equipe
Em Nova análise, clique em + Criar modelo. Passo 1: carregue uma planilha de referência; o sistema detecta os campos sozinho. Passo 2: selecione os campos prioritários (as abas Todos, Sugeridos e Selecionados ajudam; campos categóricos são sugeridos). Dê um Nome do modelo, confira a Prévia do painel e clique em Salvar modelo. O modelo aparece no seletor de análise para qualquer planilha compatível.
No painel, o botão Salvar no modelo guarda os títulos, cores e a ordem dos quadros no modelo.
Modelos podem ser da equipe (Equipes); editores e administradores da equipe criam e editam.

## Painel da planilha: quadros, filtros e período
palavras: painel, dashboard, quadro, quadros, filtro, filtrar, período, data, comparar com, ano passado, período anterior, barras, cards, tabela, adicionar quadro, ocultar quadro, editar quadro, meta, cor, ordenar, restaurar, abas, campos
O painel mostra um quadro por campo, com a contagem de cada valor. Clicar numa barra filtra tudo por aquele valor; Limpar filtros tira os filtros.
Cada quadro pode ser visto como Barras, Cards ou Tabela. "+ Adicionar quadro" inclui outra coluna; Restaurar volta ao padrão.
Editar quadro (botão de lápis no canto do quadro): Título, Cor, Ordenar por (Mais frequentes ou Ordem alfabética), Mostrar (5, 10, 20 principais ou Todas), Meta (opcional), Posição (Antes de / Depois) e Ocultar quadro. Clique em Aplicar.
Meta: escolha um valor e "no mínimo" ou "no máximo" uma porcentagem (ex.: "Aprovado no mínimo 60%"). O quadro fica verde, amarelo (até 5 pontos percentuais da meta) ou vermelho.
Período: quando a planilha tem datas, escolha De e Até. Com o período definido, "Comparar com" mostra a variação contra o período anterior ou o mesmo período do ano passado. Limpar período tira o filtro de datas.

## Painel: perguntar aos dados e resumo com IA
palavras: pergunte aos dados, perguntar, pergunta, quem mais, qual vendeu, resumo, resumo com ia, análise automática, observações, texto do painel
Pergunte aos dados: escreva uma pergunta como "qual vendedor vendeu mais em março?". A IA só escolhe a consulta; o sistema faz as contas com os dados reais e mostra o resultado.
Análise automática: o painel escreve um resumo com os principais números. O botão Resumo com IA reescreve esse texto usando só os números do painel. O resumo é guardado na biblioteca e entra no relatório mensal da equipe.

## Painel: atualizar, juntar e comparar versões
palavras: atualizar, atualização automática, a cada, minutos, juntar, outra planilha, mesclar, comparar versões, versão anterior, salvar, menu
Atualizar: para planilhas do Google Sheets, o seletor "Atualizar" busca a planilha de novo sozinho (manual, a cada 5 min, 15 min, 1 hora ou 6 horas) enquanto o painel está aberto.
Juntar outra planilha…: no menu Mais opções (⋯) do painel, junta as linhas de outra planilha à análise.
Salvar: guarda a análise na Biblioteca. Quando a mesma planilha é salva mais de uma vez, Comparar versões mostra a diferença para a versão anterior.

## Exportar: PDF, PowerPoint, PNG, Excel e CSV
palavras: exportar, baixar, download, pdf, powerpoint, ppt, pptx, png, imagem, excel, xls, csv, formato padrão, imprimir, slides
No painel, clique em Exportar e escolha: PDF (para imprimir e enviar), PowerPoint (slides editáveis, abre no PowerPoint ou Google Slides), PNG (imagem em alta resolução), Excel (as linhas que sobraram depois dos filtros, mais um resumo por quadro) ou CSV (separado por ponto e vírgula).
O formato usado na exportação rápida se escolhe em Configurações → Exportação → Formato padrão. Os arquivos saem sempre no tema claro.
No editor de infográfico: PDF sai com todas as páginas e Baixar PNG com a página aberta.

## Apresentar a análise
palavras: apresentar, apresentação, slides, tela cheia, f5, modo escuro, lua, velocidade, mascote na apresentação
No painel, clique em Apresentar para abrir os slides em tela cheia. O mascote leva os números e painéis do slide de visão geral até o lugar deles. A lua no rodapé liga o modo escuro da apresentação.
A barra de velocidade da Cápsula fica no canto inferior esquerdo, só nas apresentações; o ícone da cápsula recolhe e expande a barra.
Setas, espaço ou clique avançam; Esc sai.

## Criar infográfico com IA
palavras: infográfico, criar infográfico, novo infográfico, gerar com ia, ia, descrever, prompt, gerar, refazer do zero, editar com ia, ctrl+i, textos mais curtos, título melhor, corrigir português, fundo escuro
Abra Criar infográfico (cartão Novo infográfico no Início). Descreva o que quer no campo de IA, por exemplo: "Relatório 2026: 94% de conformidade. Conformidade por setor: Ensino 92, Pesquisa 78. Avaliações por mês: Mai 18, Jun 26, Jul 31", e clique em Gerar com IA.
Cada lista no formato "Título: Nome 12, Outro 34" vira um gráfico com os números exatos; meses, trimestres e anos viram gráfico de linha. As frases sob os gráficos e os destaques (maior, menor, tendência) são calculados pelo sistema.
Editar com IA (botão IA ou Ctrl+I): escreva o que mudar e clique em Aplicar (Ctrl+Enter). Atalhos prontos: Textos mais curtos, Título melhor, Corrigir português, Fundo escuro, + Gráfico. Com elementos selecionados, a IA mexe só neles. Refazer do zero monta tudo de novo.
Cada pessoa pode fazer até 30 pedidos de infográfico por hora.

## Criar infográfico a partir de dados (sem IA)
palavras: a partir de dados, usar a planilha aberta, ramper, importar do ramper, sem ia, apresentação 16:9, formato
Em Criar infográfico, a seção Gerar a partir de dados monta o infográfico direto da planilha, com os números exatos: Usar a planilha aberta (a do painel) ou Importar do Ramper (.xlsx, .xls ou .csv; o cabeçalho é achado sozinho). Escolha Apresentação (16:9) ou um formato de infográfico antes de gerar.
O sistema conta as categorias (rosca ou barras), soma e tira a média das colunas de número, agrupa as datas por mês (linha) e escreve os destaques.
Também dá para gerar a partir de uma planilha do CRM (Gerar infográfico).

## Editor de infográfico: elementos, páginas e animações
palavras: editor, elementos, camadas, modelos prontos, mover, arrastar, página, páginas, duplicar, animação, animar, ver animação aqui, desfazer, refazer, zoom, ajustar, mascote, cor de fundo, degradê
No editor, as abas Elementos, Camadas e Modelos ficam ao lado. Arraste os elementos para mover; o painel de propriedades muda textos, cores, Cor de fundo, Fundo em degradê e Segunda cor.
Páginas: um infográfico pode ter até 20 páginas; a barra no canto troca, cria, duplica, move e exclui páginas (PgUp/PgDn também trocam).
Animação de entrada (painel de propriedades de cada bloco): padrão, desliza da esquerda, cai de cima, cresce com salto, gira na horizontal ou sem animação. Barras podem balançar e a rosca pode girar. Ver animação aqui testa.
O botão da cápsula no topo do editor liga e desliga o mascote naquele design. Zoom: 100% e Ajustar. Desfazer, refazer, animação e exportar ficam agrupados na barra.
Apresentar (ou F5) mostra em tela cheia, uma página por vez; setas, espaço ou clique avançam, R repete a animação e Esc sai.

## CRM de planilhas: criar e importar
palavras: crm, planilha do crm, nova planilha, criar em branco, colunas, importar exportação do ramper, ramper, etapas, contatos, responsável, próximo contato
Abra CRM de planilhas (cartão Nova planilha no CRM no Início). Para criar: dê o Nome, as Colunas separadas por vírgula (ex.: Nome, Contato, Observações), escolha Compartilhar (Só eu ou uma equipe) e clique em Criar em branco.
Importar exportação do Ramper: a coluna de etapa (Etapa, Status, Fase ou Funil) vira as etapas do CRM; etapas como Ganho, Fechado ou Perdido contam como concluídas.
Também dá para importar as linhas da planilha aberta no painel.

## CRM de planilhas: usar a planilha
palavras: nova linha, nova coluna, editar linha, anotação, anotações, colunas, renomear coluna, excluir coluna, kanban, funil, tabela, filtros, atrasadas, em aberto, concluídas, situação, etapa, baixar excel, compartilhar planilha, gerar apresentação, gerar infográfico
Nova linha adiciona um registro; abra uma linha para editar os campos e clique em Salvar dados; cada linha tem anotações (Salvar anotação), etapa, responsável e próximo contato.
Nova coluna acrescenta um campo. O botão Colunas renomeia e exclui colunas (excluir pede confirmação e apaga os dados da coluna).
Visualização (seletor no topo): Tabela, Kanban (uma coluna por etapa; no computador arraste o cartão; no celular use o seletor no cartão) e Funil (quantos chegam em cada etapa e a conversão).
Ferramentas ao lado: Buscar…, Todas as etapas, Responsável (Todos, Sem responsável ou uma pessoa), Todas as situações (Em aberto ou Concluídas) e a caixa Só atrasadas.
Baixar Excel gera um .xlsx com as colunas, etapa, responsável e próximo contato. Gerar infográfico e Gerar apresentação usam os dados do CRM.
Compartilhar: o seletor no topo da planilha envia para uma equipe em que você é editor ou administrador; só o dono da planilha faz isso.

## CRM: lembretes
palavras: lembrete, lembretes, hoje, atrasado, atrasados, próximo contato, e-mail diário, lembrete diário
Os contatos de hoje e os atrasados em que você é responsável aparecem em Lembretes do CRM, no Início e no CRM.
Lembrete diário por e-mail: no CRM, na caixa Lembretes, marque ou desmarque "Lembrete diário por e-mail".

## Biblioteca
palavras: biblioteca, salvos, itens salvos, salvei, reabrir, renomear, editar item, nota, comparar, compartilhar, link, criar link, copiar link, desativar link, comentário, comentar, excluir, versão, recentes
A Biblioteca guarda as análises, apresentações e infográficos salvos. Abra pelo cartão Biblioteca no Início ou pelo menu lateral.
Cada item tem Apresentar (ou Baixar PNG nos itens antigos) e botões de ícone para Editar (Título, Nota e Equipe), Compartilhar, Comentários (itens com apresentação) e Excluir.
Comparar: clique no ícone Comparar de dois itens (só itens com apresentação). A barra embaixo mostra "2 apresentações selecionadas"; clique em Comparar nessa barra para ver a diferença entre eles.
Compartilhar: Criar link gera um endereço que abre a apresentação sem login; Copiar link copia; Desativar link desliga.
Comentários: escreva em Novo comentário e clique em Comentar (itens da equipe).
A biblioteca guarda até 200 itens por pessoa. Configurações → Dados → Limpar tudo apaga a biblioteca.

## Equipes
palavras: equipe, equipes, criar equipe, entrar com código, convite, convidar, código, link de convite, papel, papéis, administrador da equipe, editor, leitor, membros, sair da equipe, excluir equipe
Abra Equipes (cartão no Início ou menu lateral). Criar equipe: dê um nome e clique em Criar. Entrar com código: digite o código de convite e clique em Entrar.
Papéis: Administrador (convida por e-mail, código ou link, muda papéis, remove pessoas, edita e exclui a equipe), Editor (cria e edita modelos e itens da biblioteca da equipe) e Leitor (vê, comenta e apresenta).
Convidar: o administrador da equipe usa Convidar (e-mail), Copiar link ou Gerar novo código. Convites por e-mail aparecem para a pessoa ao entrar, com Aceitar e Recusar.
Sair da equipe fica na página da equipe (se for a última pessoa, a equipe é apagada); o administrador da equipe também pode Excluir equipe. Cada pessoa pode participar de várias equipes, com um papel em cada.

## Equipes: alertas de meta e relatório mensal
palavras: alerta, alertas de meta, meta, relatório mensal, relatório, enviar agora, e-mail da equipe
Na página da equipe, o administrador da equipe liga ou desliga:
Alertas de meta: quando alguém salva um item da equipe com meta fora do verde, os administradores recebem um e-mail (no máximo um por item e 20 por dia).
Relatório mensal: resumo dos itens e das metas da equipe para todos os membros; Enviar agora manda na hora.

## Configurações
palavras: configurações, configuração, tema, tema escuro, modo escuro, aparência, perfil, formato padrão, dados, limpar tudo, remover todos, restaurar originais, modelos customizados, versão
Abra Configurações (cartão no Início ou menu lateral). Seções: Perfil (e-mail), Equipes (Gerenciar equipes), Aparência (Tema escuro), Cápsula (Personalizar abre Minha Cápsula), Exportação (Formato padrão: PNG, PDF ou PowerPoint) e Dados (Limpar tudo apaga a biblioteca; Remover todos apaga os modelos customizados; Restaurar originais volta os modelos prontos ao padrão).
O botão Alternar tema no painel também troca entre claro e escuro.

## Minha Cápsula: aparência
palavras: minha cápsula, cápsula, mascote, personalizar, aparência, cor, corpo, óculos, boca, cabelo, chapéu, bigode, destaque, sortear, restaurar padrão
Abra Minha Cápsula pelo cartão no Início, pelo botão Aparência no painel da Cápsula ou em Configurações → Cápsula → Personalizar.
As abas são Corpo (9 cores), Rosto (óculos: sem, redondos, quadrados ou escuros; boca: padrão, sorriso, surpresa ou neutra), Cabelo (8 estilos, 7 cores), Chapéu (cartola, boné, coroa, festa, capacete, palha, gorro), Bigode (8 estilos, 7 cores) e Destaque (contorno branco, dourado, neon ou nenhum). Sortear cria uma combinação; Restaurar padrão volta ao original. A escolha fica salva na conta.
Na tela Início, a cápsula da faixa pode ser arrastada; um toque faz ela pular e falar; dois toques a devolvem ao lugar.

## Cápsula assistente: o que ela faz
palavras: cápsula, assistente, ajuda, conversar, perguntar, o que você faz, comandos, mostrar na tela, entendi
A Cápsula fica no canto inferior direito, em todas as telas. Ela explica o sistema e, quando a pergunta cita uma página, caminha até o botão dessa página na tela atual e o destaca; Entendi ou Esc fecham o balão.
Ela também faz algumas ações quando você pede: abrir uma planilha do CRM, criar planilha em branco, filtrar atrasadas, em aberto ou concluídas, tirar filtros, baixar o Excel, gerar infográfico ou apresentação da planilha, excluir planilha ou coluna (sempre com confirmação) e apresentar a análise aberta.
Cada pessoa pode fazer até 60 perguntas por hora. Se aparecer "IA indisponível", espere alguns instantes: o plano grátis da IA tem limite por minuto.

## Tutorial
palavras: tutorial, passo a passo, ver tutorial, tour, guia, primeiro acesso
No primeiro acesso, a cápsula apresenta a tela Início. Use Próximo e Anterior (ou as setas do teclado), Pular ou Esc. Para rever, clique em Ver tutorial na tela Início.

## Administração do sistema
palavras: administração, administrador do sistema, admin, usuários, bloquear, desbloquear, senha temporária, novos cadastros, registro, erros, visão geral, excluir conta
Só aparece para administradores do sistema (cartão no Início ou menu lateral). Abas:
Visão geral: números de usuários, equipes, itens e acessos, avisos do servidor e a opção Novos cadastros (desligada, só entra quem tem convite de equipe).
Usuários: busca e filtros no topo da lista. Cada pessoa tem, na própria linha, os botões Bloquear (ou Desbloquear; a pessoa é desconectada na hora), Tornar admin (ou Tirar admin), Senha temporária (aparece uma vez só) e o ícone de lixeira para Excluir conta (pede confirmação).
Equipes: todas as equipes, membros e Excluir equipe. Registro: tudo o que os administradores fizeram. Erros: os últimos erros do sistema, com Limpar registro.
O sistema nunca fica sem administrador: não dá para bloquear, rebaixar ou excluir o último.
