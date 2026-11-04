---
created_at: "2026-11-03 15:23:38"
id: feat-0001-melhoria-seguranca
n45_version: 1.1.0
title: Melhoria de segurança e tratamento de erros
type: feat
---
## As Is (hoje)

A base de segurança do Map-OS é sólida: SQL por Query Builder/bindings, escape em todas as views, CSRF, CSP sem `unsafe-inline`, uploads validados, recuperação de senha robusta, 2FA e JWT do operador com refresh rotativo. A auditoria (somente leitura) encontrou lacunas concentradas na API v1, na área do cliente, nos gateways de pagamento, na fila de e-mail e no tratamento de erros:

**Segurança**
- `PUT /api/v1/os/{id}` (`application/controllers/api/v1/OsController.php:222-298`) não chama `osParaEditar()`/`isEditable()` e grava `faturado = 0` fixo: edita OS faturada/cancelada e "desfatura" sem estornar o lançamento.
- `notification_url` fixa em domínio de terceiros: `GerencianetSdk.php:207,284` (`http://mapos.com.br/`) e `MercadoPago.php:215` (`https://seusite.com.br/...`). Não existe endpoint de webhook; status só muda por consulta manual (`Cobrancas::atualizar`).
- Senha padrão do cliente = CPF/CNPJ (`clientes_helper.php:45`, `api/v1/ClientesController.php:92`).
- JWT do cliente sem teto (`ClientLoginController.php:61-69`), até 30 dias, sem refresh, logout ou revogação.
- Abertura de OS pelo cliente sem limite e com e-mail para toda a equipe (`Mine.php:493-496`, `ClientOsController.php:130-206`).
- API não confere grupo desativado (`REST_Controller.php:2321-2330`, refresh em `UsuariosController.php:379`).
- Menores: `perPage` sem teto e offset multiplicado duas vezes (`OsController.php:55-57` e outros); `Api_model::lastRow()` em vez de `insert_id()`; ativação de 2FA sem limite de tentativas (`Mapos.php:184`); logout por GET (`Login.php:25`, `Mine.php:45`); sessão web do cliente não revalidada no banco (`Mine.php:719-726`); seed com `admin@admin.com/123456` em qualquer ambiente; `.htaccess` gerado com sintaxe do Apache 2.2 (`armazenamento_helper.php:336`); token de reset marcado como usado só após a troca (corrida).

**Erros**
- `REST_Controller.php:857-864`: `show_exception()` devolve HTML com arquivo/linha e status 200 em produção; não pega `\Throwable`.
- `MercadoPago.php:236`: `new \Exception(array)` gera TypeError que escapa do `catch (\Exception)` de `Cobrancas.php:77`.
- Exclusões com várias escritas sem transação e com sucesso incondicional: `Os.php:722-757`, `Vendas.php:523-541`, `Clientes.php:276-290`, `Produtos.php:182-188`, `Servicos.php:148-153`, `Cobrancas.php:221-223` e equivalentes na API v1.
- Catches que engolem erro sem log e nunca disparam com `db_debug` false: `Clientes_model.php:194,230`, `Mapos_model.php:539`.
- AJAX com sessão expirada recebe 302 → HTML 200, tratado como sucesso pelo `assets/js/lib/http.js:92-100`.
- Fila de e-mail (`MY_Email.php:133-187`): corrida entre SELECT e UPDATE, sem lock entre execuções do cron, sem try/catch por e-mail, retry infinito, e `print_debugger()` grava o corpo (com links de redefinição de senha) no log.
- Gateways sem `log_message`, sem timeout no Mercado Pago/Asaas, mensagem crua do gateway ao usuário (`Cobrancas.php:78-86,192,233`, `MercadoPago.php`, `Relatorios.php:215`).
- Status inconsistentes: ~40 usos de 401 para falta de permissão na API; login do painel e área do cliente respondem 200 em toda falha sem `Content-Type` JSON; `Cobrancas` responde 500 para erro de validação.
- `try/catch` que não pega warnings do PHP 8 (`OsController.php:141-156,259-274`), ~30 `@` silenciosos (incluindo gravação do `.htaccess` de proteção), `null` em funções de string, `save_queries` true em produção, `fetch` sem timeout e JSON inválido mostrado como "sem conexão", sem handler de `unhandledrejection`.
- Bugs vistos de passagem: descrição do Mercado Pago sempre "OS" (`MercadoPago.php:213`), QR da venda pelo `os_model` (`ClientComprasController.php:75`), `ApiController.php:59/116` devolve `$allOs` no lugar de `$events`, paginação da API do cliente por `uri->segment(3)`, `Login::falha()` com nome de token CSRF fixo.

## To Be (depois)

Fechar todas as lacunas acima sem regredir o que já está seguro:
- Nenhuma rota permite alterar registro fechado ou escalar privilégio; contas do cliente nascem com senha forte e o token do cliente é curto e revogável.
- Pagamentos confirmados chegam por webhook autenticado de cada gateway e baixam cobrança e financeiro automaticamente, de forma idempotente.
- Todo erro técnico é logado (sem dado sensível) e o usuário recebe mensagem clara com o status HTTP correto; nenhuma operação de várias escritas fica pela metade nem mostra sucesso falso.
- Beneficiados: operadores do painel, clientes da área do cliente/API e quem administra a instalação.

## Plataforma

Web (painel, área do cliente) + API REST v1 + CLI (cron de e-mail).

## Stack

Herda o STACK sem troca de tecnologia: PHP 8.5, CodeIgniter 3.1, MySQL 8.4/MariaDB (Query Builder, migrations em timestamp), views PHP + Tailwind 4, JS ES modules sem bundler, PHPUnit 12 + node:test, SDKs já instalados dos gateways (Efí, Mercado Pago, Asaas). Nenhuma dependência nova prevista.

## Funcionalidades / Escopo

### 1. Webhooks de pagamento (fase própria)
- Endpoints públicos por gateway (Efí, Mercado Pago, Asaas), fora do CSRF e da sessão, com autenticação própria de cada gateway.
- Nunca confiar em valor/status do corpo: reconsultar o pagamento na API do gateway.
- Idempotência por tabela `webhook_eventos` (chave única gateway + id do evento); evento repetido responde 200 sem reprocessar.
- Pagamento confirmado → cobrança `paga` + lançamento financeiro vinculado (OS/venda) baixado, na mesma transação, com `log_info`.
- `notification_url` montada de `base_url` + segredos do `.env` (`EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN`); sem domínio fixo.
- Documentar no RUNBOOK como cadastrar a URL em cada gateway.

### 2. Segurança da API do operador
- `PUT /api/v1/os/{id}` usa `osParaEditar()`/`isEditable()` (403 em OS fechada) e não altera `faturado`.
- 403 para falta de permissão; 401 só para token ausente/inválido.
- `logged_user` e refresh conferem grupo desativado (401).
- `perPage` com teto de 100 e offset corrigido; `insert_id()` no lugar de `lastRow()`.
- Filtros `from`/`to` e datas validados (422 em formato inválido).

### 3. Conta e API do cliente
- Senha aleatória forte no cadastro (painel e `POST /api/v1/clientes`), exibida uma única vez ao operador e enviada por e-mail ao cliente; coluna de troca obrigatória.
- Área do cliente força "Defina sua nova senha" no primeiro acesso; API do cliente responde 403 `troca_de_senha_obrigatoria` até `PUT /api/v1/client/password`.
- JWT do cliente com teto de 1 h + refresh rotativo com detecção de reuso e revogação da família; `POST /api/v1/client/logout`.
- Limite de 10 aberturas de OS por cliente por dia (área do cliente e API), 429 com `Retry-After`; notificação da equipe mantida.
- Sessão web do cliente revalidada no banco a cada requisição (cliente excluído ou senha trocada encerram a sessão).

### 4. Autenticação do painel
- Logout por POST com CSRF (painel e área do cliente).
- Ativação do 2FA via `Limite_login::confirmarSenha`.
- Login (painel e área do cliente) com JSON e status: 401 credenciais, 429 bloqueado, 422 validação; nome do token CSRF vindo da config.
- Token de reset marcado como usado de forma atômica antes da troca.
- Seed do admin padrão só em `development`.

### 5. Tratamento de erros no servidor
- `REST_Controller` captura `\Throwable`, loga e responde 500 JSON `{status:false, message:"Erro interno"}`.
- Exclusões de OS, vendas, clientes, produtos, serviços e cobranças (painel e API) em transação, conferindo retorno; falha → rollback, log e mensagem de erro.
- Catches que engolem erro passam a logar e a conferir `trans_status`/retorno real.
- Faturamento, `registrarStatus`, `desativarTotp` e enfileiramento de e-mail com retorno conferido e log técnico.
- `try/catch` inúteis substituídos por validação explícita; `null` convertido antes de funções de string.
- `@` silenciosos substituídos por checagem de retorno + log (obrigatório na gravação do `.htaccess`/`index.html` de proteção; `.htaccess` com bloco `mod_authz_core`).
- `save_queries` desligado em produção.

### 6. Gateways (erros)
- Correção do TypeError do Mercado Pago (`mpErrorMessage`) e da descrição "OS"/"Venda".
- `log_message` em toda falha de gateway, sem dado sensível; timeout configurado em Mercado Pago e Asaas.
- Mensagem amigável ao usuário; erro de validação do gateway → 422, falha técnica → 502/500; JSON com `result`.

### 7. Fila de e-mail (cron)
- Reserva atômica por lote (UPDATE com marcador e depois SELECT dos reservados), lock contra execuções simultâneas.
- try/catch por e-mail; contador de tentativas com máximo de 5, depois `failed` definitivo.
- Log só com cabeçalhos (`print_debugger(['headers'])`), nunca corpo.
- `email/process` e `email/retry` logam início/fim e quantidades e devolvem código de saída.

### 8. Frontend
- `MY_Controller` responde 401 JSON `{result:false, message:"Sessão expirada", redirect}` em AJAX; `http.js` trata 401 (e `response.redirected`) com aviso e ida ao login.
- `http.js` com timeout padrão (AbortController), JSON inválido com mensagem correta, handler global de `unhandledrejection`.
- Feedback visível nos catches que hoje engolem falha (`layout/shell.js`).

### 9. Bugs funcionais vistos de passagem
- `ClientComprasController.php:75` (QR da venda), `ApiController.php:59/116` (`$events`), paginação da API do cliente por `uri->segment(3)`.

## Contratos de API

```
Webhooks
  POST /webhooks/efi/{EFI_WEBHOOK_SECRET} — body: notification → consulta notificação na API Efí → 200 | 401 segredo inválido | 404 cobrança desconhecida
  POST /webhooks/mercadopago — headers: x-signature (HMAC-SHA256 com MP_WEBHOOK_SECRET), x-request-id; body: type, data.id → consulta pagamento → 200 | 401 assinatura inválida
  POST /webhooks/asaas — header: asaas-access-token = ASAAS_WEBHOOK_TOKEN; body: event, payment.id → consulta pagamento → 200 | 401 token inválido
  Evento repetido → 200 sem reprocessar

API do cliente
  POST /api/v1/client/auth — body: email, senha → 200 { access_token (1h), refresh_token } | 401 | 429
  POST /api/v1/client/refresh — body: refresh_token → 200 novo par | 401 (reuso revoga a família)
  POST /api/v1/client/logout — revoga a família do refresh → 204
  PUT  /api/v1/client/password — body: senha_atual, senha_nova → 200 | 422
  POST /api/v1/client/os — → 201 | 429 + Retry-After (acima de 10/dia)
  Demais rotas com troca pendente → 403 { code: "troca_de_senha_obrigatoria" }

API do operador
  PUT  /api/v1/os/{id} — → 200 | 403 OS faturada/cancelada | 422; não altera faturado
  POST /api/v1/clientes — → 201 { ..., senha_inicial } (exibida uma vez)
  Falta de permissão → 403; token ausente/inválido ou grupo desativado → 401
  perPage → teto 100
  Exceção não tratada → 500 { status:false, message:"Erro interno" }

Painel (AJAX)
  Sessão expirada → 401 { result:false, message:"Sessão expirada", redirect:"/login" }
  POST /login/verificarLogin e login da área do cliente → 200 | 401 | 422 | 429 (Content-Type JSON)
  POST /login/sair, POST /mine/sair — com CSRF (GET removido)
  Cobranças → 422 erro de validação do gateway | 502 falha do gateway, com result
```

## Jornadas e Telas

```
Tela: Cadastro de cliente (painel), após salvar
- Aviso com a senha gerada, exibida uma única vez
  - Botão Copiar
  - Texto "Também enviada por e-mail ao cliente"

Tela: Área do cliente, primeiro acesso
- Formulário "Defina sua nova senha" (bloqueia as demais telas)
  - Campo senha atual (a recebida)
  - Campo nova senha + confirmação
  - Botão Salvar

Qualquer tela do painel / área do cliente com sessão expirada
- Aviso "Sua sessão expirou" e redirecionamento ao login

Menu do usuário (painel e área do cliente)
- "Sair" vira botão de formulário POST
```

## Mudanças de Schema / Banco

- Nova tabela `webhook_eventos` (id, gateway, evento_id, cobranca_id, payload_hash, processado_em, created_at; UNIQUE gateway + evento_id).
- `clientes`: coluna de troca de senha obrigatória (ex.: `senha_trocar` tinyint) e contador de versão de sessão web, se ainda não houver.
- Refresh token do cliente: reutilizar `api_refresh_tokens` com coluna de tipo (`usuario`/`cliente`) ou tabela própria (decisão do Architect).
- `email_queue`: colunas `tentativas` e marcador de reserva (`reservado_por`, `reservado_em`).
- Limite de abertura de OS: contagem por `os` do cliente no dia (sem tabela nova) ou reaproveitar o rate limit existente.
- Todas por migration em timestamp, reversíveis.

## Dependências e Impacto

- APIs externas Efí, Mercado Pago e Asaas: webhooks precisam ser cadastrados em cada conta do gateway; URL pública HTTPS obrigatória.
- Novas variáveis no `.env.example`: `EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN`, `API_CLIENT_TOKEN_EXPIRE` (teto 3600), timeouts dos gateways.
- Breaking changes de contrato: 401→403 na API do operador, login JSON com status, logout por POST, refresh obrigatório na API do cliente. Precisam constar no CHANGELOG e em `docs/api-autenticacao.md`.
- Cron de e-mail: nova semântica de tentativas; e-mails `failed` antigos passam a respeitar o máximo.
- Testes: toda mudança de segurança com teste do caso negado (PATTERNS), incluindo assinatura inválida de webhook, evento repetido, OS fechada no PUT, grupo desativado, refresh reutilizado, limite de OS e sessão expirada em AJAX.
- Guards do CI (`check-escape`, `check-inline-script`, PHPStan com baseline) não podem subir.

## Fora do Escopo

- Migração do CodeIgniter 3 para outro framework.
- Zerar a baseline do PHPStan (189 erros ignorados): só não pode subir.
- Medição de cobertura de testes.
- Mudança de bloqueio por e-mail no `Limite_login` (troca conhecida, mantida).
- Assinatura de autoria do atualizador (`Github_updater`) e janela do instalador antes da instalação (documentar apenas).
- Senhas do `docker/.env` de desenvolvimento.
