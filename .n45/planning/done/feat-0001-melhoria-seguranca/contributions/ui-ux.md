---
created_at: "2026-11-03 15:25:49"
from: ui-ux
n45_version: 1.1.0
spec_id: feat-0001-melhoria-seguranca
---
### Componentes (reutilizáveis)

**Do inventário (`DESIGN.components`):** `alert`, `toast`, `button`, `input` (variante `password`), `card`, `modal-confirm`, `pill-status`, `link` · **Novos:** nenhum componente de inventário novo. Um único padrão composto novo, feito só de componentes existentes: "senha exibida uma única vez" (alert + campo somente leitura + botão Copiar), que entra como composição documentada, não como componente.

**Decisão de composição:**

| Componente | Usado em | Variantes / estados |
| --- | --- | --- |
| alert | Cadastro de cliente (senha gerada), primeiro acesso (motivo do bloqueio), erro de login | warning (senha gerada, troca obrigatória) · danger (login negado/bloqueado) · success |
| input (password) | Primeiro acesso (senha atual, nova, confirmação), campo somente leitura da senha gerada | default / focus / error / disabled; somente leitura na senha gerada |
| button | Copiar, Salvar nova senha, Sair | primary (Salvar) · outline (Copiar) · ghost (Sair no menu) · loading no Salvar |
| toast | Sessão expirada, falha de gateway, erro de exclusão, falha de rede/timeout | warning (sessão expirou) · danger (erro) · success |
| card | Formulário "Defina sua nova senha" | default |
| modal-confirm | Exclusões (OS, venda, cliente, produto, serviço, cobrança): mensagem de erro de rollback vai em toast, o modal só fecha | closed / open |

Sem componente compartilhado novo além dos acima; o "Sair" por POST reusa `button` dentro de `<form>` com CSRF, não um componente novo.

### Fundação Visual

**Origem:** `DESIGN` adotado. Nada a acrescentar: tipografia, reset, app shell e tokens da feature são os já decididos (Rubik, tokens de `assets/src/tokens.css`, shell com `.v5-conteudo`). Esta feature não diverge de nenhum valor.

- **Tipografia / reset / app shell:** herdados do DESIGN, sem mudança.
- **Onde mora:** custom properties do projeto (idioma `css-vars`); nenhuma cor ou fonte fixa em view.

### Design System & Tokens

Adotar o design system do projeto. Só tokens semânticos existentes: `warning` para "senha exibida uma vez" e "troca obrigatória", `danger` para falha, `success` para confirmação, `muted` para texto de apoio. Um padrão por problema: feedback transitório sempre em `toast`; aviso que exige leitura/ação persistente sempre em `alert` na tela; erro de campo sempre inline no componente.

**Padrão de form:** o já existente (`formulario/padrao`): `novalidate`, erro inline por campo com texto do servidor, botão em loading no submit, `Enter` submete, POST com CSRF. O formulário de nova senha segue o mesmo padrão; confirmação divergente e senha fraca viram erro de campo (422 do servidor), não alerta genérico.

### Telas

#### Cadastro de cliente (painel), após salvar — senha gerada

**Compõe:** `alert` (warning), `input` password somente leitura, `button` outline, `toast`.
**Conteúdo:** o cliente já foi salvo; aviso persistente na tela de destino do redirecionamento: "Senha inicial do cliente: exibida apenas agora", a senha em campo somente leitura, e o texto "Também enviada por e-mail ao cliente". Se o envio do e-mail falhar, o texto muda para "Não foi possível enviar por e-mail: repasse a senha ao cliente" (a senha exibida passa a ser o único canal; nunca afirmar envio que não ocorreu).
**Ações disponíveis:** Copiar (feedback "Copiado" por toast, rótulo muda por instantes); fechar o aviso.
**Navegação:** continua na tela do cliente/lista; ao sair da tela ou recarregar, o aviso não reaparece e a senha não é recuperável.
**Estados:**

- Loading: não se aplica (dado já presente na resposta do salvar).
- Vazio: não se aplica.
- Erro: copiar indisponível no navegador → selecionar o texto do campo e dizer "Copie manualmente" no toast.

Mesmo contrato para `POST /api/v1/clientes` (`senha_inicial` só nessa resposta): o consumidor da API é responsável por mostrá-la.

#### Área do cliente — primeiro acesso: "Defina sua nova senha"

**Compõe:** `card`, `alert` (warning), `input` password ×3, `button` primary.
**Conteúdo:** título "Defina sua nova senha", explicação curta ("Por segurança, troque a senha que você recebeu antes de continuar"), campos senha atual (a recebida), nova senha e confirmação, com a regra de senha visível antes do erro (mínimo de caracteres exigido pelo servidor).
**Ações disponíveis:** Salvar; Sair (único outro destino permitido).
**Navegação:** enquanto a troca estiver pendente, qualquer rota da área do cliente redireciona para esta tela (sem beco: a única saída é salvar ou sair); ao salvar, vai para a tela inicial da área do cliente com toast de sucesso e a sessão segue válida.
**Estados:**

- Loading: botão Salvar em loading, campos desabilitados; sem skeleton.
- Vazio: não se aplica.
- Erro: senha atual incorreta, nova fraca ou confirmação divergente → erro inline no campo correspondente; falha técnica → alert danger "Não foi possível salvar. Tente novamente." sem perder o que foi digitado (exceto senhas, que são limpas).

#### Qualquer tela (painel e área do cliente) — sessão expirada

**Compõe:** `toast` (warning).
**Conteúdo:** aviso "Sua sessão expirou. Entre novamente." exibido por tempo suficiente para leitura (sem sumir antes do redirecionamento perder a mensagem) e ida ao login.
**Ações disponíveis:** nenhuma; o redirecionamento é automático, o aviso permanece também na tela de login (via flash/parâmetro) para ser lido.
**Navegação:** login; após entrar, volta para a tela de origem quando seguro (mesmo host, GET), senão para o início.
**Estados:** erro de rede/timeout é outro caso: toast danger "Sem conexão ou resposta demorou demais. Tente novamente." com ação de repetir quando a operação for idempotente; JSON inválido do servidor → "Resposta inesperada do servidor", nunca "sem conexão".

#### Login do painel e da área do cliente — respostas por status

**Compõe:** `alert`, `input`, `button` primary.
**Conteúdo e estados:** 401 credenciais → mensagem genérica única (não distingue e-mail de senha); 429 bloqueado → alert com o tempo restante para nova tentativa; 422 → erro inline por campo; falha de rede → toast. Foco vai ao primeiro campo com erro; o botão volta do loading em todo desfecho.

#### Menu do usuário — "Sair" (painel e área do cliente)

**Compõe:** `button` (ghost) dentro de `<form method=post>` com CSRF.
**Conteúdo:** mesmo rótulo e posição de hoje; a aparência não muda, só o mecanismo.
**Navegação:** sair → tela de login com "Você saiu"; acessar a antiga URL de logout por GET não derruba a sessão (mostra o login/404 sem efeito).
**Estados:** token CSRF vencido no logout → renova e sai (não deixar o usuário preso em erro 403 ao tentar sair).

#### Ações de exclusão e cobrança — mensagens de erro

**Compõe:** `modal-confirm`, `toast`, `alert`.
**Conteúdo:** exclusão com rollback → o modal fecha, o registro permanece na lista e `toast` danger "Não foi possível excluir. Nada foi alterado." (nunca sucesso falso). Cobrança: erro de validação do gateway (422) → mensagem amigável em linguagem do usuário no campo/alert; falha do gateway (502) → "O provedor de pagamento não respondeu. Tente novamente em instantes."; nunca texto cru do gateway, nunca stack. Pagamento baixado por webhook aparece como `pill-status` "Paga" com a palavra, sem sinalização extra de origem.

### Performance

- Percebida: feedback imediato no clique (botão em loading, "Copiado" instantâneo); timeout padrão no cliente HTTP com mensagem clara em vez de espera infinita.
- Real: o módulo de nova senha e o de Copiar carregam só nas telas que os usam (um módulo por tela, como o padrão atual); sem dependência nova.
- Layout: o aviso de senha gerada e o alert de erro de login reservam o lugar no topo do conteúdo (sem empurrar campos após o primeiro paint); toast em camada fixa, não desloca conteúdo.

### Responsivo & Touch

- Breakpoints: os do DESIGN (sm 640 / md 768 / lg 1024); formulário de nova senha e senha gerada em coluna única, largura de formulário (`max-w-4xl` no painel; card estreito centralizado na área do cliente).
- Layout fluido: campo de senha + botão Copiar empilham no estreito; o aviso quebra linha, sem rolagem horizontal.
- Touch: alvo ≥ 44px em Copiar, Salvar e Sair; "Sair" no menu móvel continua alcançável.

### Acessibilidade

- Contraste: AA ≥ 4.5:1 nos temas suportados, com os tokens `warning`/`danger` já validados; a senha gerada em fonte monoespaçada com contraste de texto normal.
- Teclado: tudo alcançável; `Enter` submete a nova senha; foco visível pelo anel padrão; ao abrir a tela de primeiro acesso o foco vai ao campo senha atual; após erro de submit, foco no primeiro campo inválido; Esc fecha o aviso dispensável.
- Rótulos: Copiar tem rótulo visível ("Copiar senha"); campo somente leitura com rótulo visível "Senha inicial"; botão de mostrar/ocultar senha com rótulo acessível não renderizado ("Mostrar senha"/"Ocultar senha") e estado anunciado.
- Roles/ARIA: toast e alert de erro com `role=alert` (urgente) e aviso de senha gerada/sessão expirada com `role=status`; "Copiado" anunciado por região `aria-live=polite`; erros de campo ligados ao campo por `aria-describedby`; a troca obrigatória não depende só de cor (ícone + texto).

### Fricções e Economia Visual

**Fricção:** (1) troca obrigatória de senha no primeiro acesso é fricção intencional e única: não repetir exigência a cada login, e explicar o porquê em uma frase; (2) senha exibida uma só vez pode ser perdida: mitigada pelo e-mail ao cliente e pelo Copiar; (3) sessão expirada no meio de um formulário longo perde dados: o aviso deve dizer que será preciso refazer e, quando possível, preservar o rascunho já existente do formulário padrão.
**Economia visual:** cada elemento tem papel distinto:

- Dado duplicado encontrado: senha gerada no `alert` e de novo em toast de confirmação → fica só no `alert`; o toast diz apenas "Copiado".
- Exibir vs. editar do mesmo campo: a senha gerada é só exibição (somente leitura); nenhum campo editável da mesma informação.
- Mensagem de erro de login: um único local (alert ou inline), nunca alert + toast ao mesmo tempo para o mesmo erro.

### Fluxos de Usuário

**Cadastro de cliente com senha gerada:** operador salva cliente → vê alert com a senha única e o texto do e-mail → copia e repassa → segue para a lista.
Desvio de erro: falha ao enviar e-mail → o alert avisa que o envio falhou e a senha exibida é o único canal; falha ao salvar → volta ao formulário com valores e erros por campo, sem senha gerada.

**Primeiro acesso do cliente:** cliente entra com a senha recebida → é levado a "Defina sua nova senha" → informa atual, nova e confirmação → Salvar → entra na área do cliente.
Desvio de erro: senha atual errada → erro no campo; nova fraca → erro no campo com a regra; tentativa de abrir outra rota antes → volta a esta tela; na API, 403 `troca_de_senha_obrigatoria` até `PUT /api/v1/client/password`.

**Sessão expirada em AJAX:** usuário age → servidor responde 401 JSON → toast "Sua sessão expirou" → login → retorno à tela de origem.
Desvio de erro: resposta sem `redirect` → ir ao login padrão; navegador seguiu redirecionamento para HTML de login (`response.redirected`) → tratado como sessão expirada, nunca como sucesso.

**Sair:** usuário abre menu → Sair (POST) → login com "Você saiu".
Desvio de erro: CSRF vencido → renova e conclui o logout.

**Bloqueio/limite no cliente:** cliente abre a 11ª OS do dia → 429 → mensagem "Limite diário de aberturas atingido. Tente novamente amanhã ou contate a equipe." com o tempo (`Retry-After`) legível; formulário preservado.

### Para tasks

- `844dd534f4`: **fundação visual** adotada do DESIGN sem mudança (tipografia, reset, shell, tokens); **componentes a reusar** `alert`, `toast`, `button`, `input` password, `card`, `modal-confirm`, `pill-status`; nenhum componente novo; **telas a compor**: aviso de senha gerada (cadastro de cliente), "Defina sua nova senha" (área do cliente, bloqueia as demais rotas), tratamento de sessão expirada (toast + login), respostas de login por status, "Sair" como form POST com CSRF (painel e área do cliente), mensagens de erro de exclusão/gateway; **responsivo**: coluna única no estreito, alvo ≥ 44px; **perf**: timeout padrão no cliente HTTP, módulos por tela, espaço reservado ao alert/toast (CLS); **a11y**: contraste AA, foco no primeiro campo inválido, `role=alert`/`status`, `aria-live` no "Copiado", rótulo acessível no mostrar/ocultar senha, Copiar com rótulo visível; **padrão de form** existente (`formulario/padrao`, erro inline, loading, Enter).
- `50a8844c7d`: contratos que a UI precisa receber pronto: `senha_inicial` somente na resposta de criação e nunca persistida/relogada; flag de e-mail enviado ou não para o aviso não afirmar o que não ocorreu; 401 JSON de AJAX com `result`, `message` e `redirect`; login com status 401/422/429 e, no 429, o tempo restante em campo legível; 422 de senha com erro por campo (`erros`); 403 `troca_de_senha_obrigatoria` com `code`; 429 de OS com `Retry-After`; cobrança com `result` e mensagem já amigável (422 validação, 502 falha do gateway); exclusão com falha → `result:false` e mensagem, sem sucesso falso.

**Checagem final:** todos os itens do checklist têm destino acima (rotas/estados em Telas; componentização em Componentes; fundação/tokens em suas seções; perf, responsivo, forms, a11y, fricções, journeys, contratos de API e economia visual em seções próprias). Não há referência de usuário (refs vazio) e não há tela nova de lista/dashboard, portanto sem skeleton ou estado vazio novo; os endpoints consumidos (webhooks, API do cliente) não têm UI própria, e a baixa por webhook só aparece como `pill-status` existente.
