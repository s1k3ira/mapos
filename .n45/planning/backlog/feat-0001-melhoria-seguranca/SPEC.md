---
created_at: "2026-11-03 15:39:19"
id: feat-0001-melhoria-seguranca
n45_version: 1.1.0
status: proposed
title: Melhoria de segurança e tratamento de erros
type: feat
updated_at: "2026-11-03 15:39:19"
---
## 1. Visão Geral

**Goal (To Be):** fechar as lacunas de segurança e de tratamento de erros encontradas na auditoria do Map-OS (API v1, área do cliente, gateways, fila de e-mail, painel), sem regredir o que já é seguro, para operadores, clientes finais, integradores e quem administra a instalação.

**Forma do trabalho:** `build`

**Background (As Is):** a base é sólida (Query Builder, `e()`, CSRF, CSP sem `unsafe-inline`, 2FA, JWT do operador com refresh rotativo), mas a auditoria encontrou: `PUT /api/v1/os/{id}` edita OS fechada e zera `faturado`; `notification_url` fixa em domínios de terceiros e nenhum webhook; senha inicial do cliente = CPF/CNPJ; JWT do cliente de até 30 dias sem refresh/revogação; abertura de OS pelo cliente sem limite; API sem conferir grupo desativado; 401/403 trocados; `perPage` sem teto e offset multiplicado duas vezes; `show_exception()` devolvendo HTML com arquivo/linha e status 200; exclusões multi-tabela sem transação e com sucesso incondicional; fila de e-mail com corrida, retry infinito e corpo (com link de reset) no log; gateways sem log nem timeout e com mensagem crua ao usuário; AJAX com sessão expirada tratado como sucesso; logout por GET; seed `admin@admin.com/123456`; `.htaccess` em sintaxe Apache 2.2 gravado com `@`.

**Ajustes por política da org:** nenhuma política da organização foi injetada; nada a adequar.

### Requisitos Funcionais

**A. Webhooks de pagamento**

- **RF-01** — O sistema deve expor `POST /webhooks/efi/{segredo}`, `POST /webhooks/mercadopago` e `POST /webhooks/asaas` num controller público `Webhooks` (estende `CI_Controller`, não `MY_Controller` nem `REST_Controller`), disponíveis com `API_ENABLED` ligado ou desligado, fora do CSRF (exclusão mínima em `csrf_exclude_uris`) e sem sessão.
- **RF-02** — Cada webhook deve autenticar o gateway **antes** de qualquer consulta externa ou escrita, com comparação em tempo constante (`hash_equals`): Efí pelo segredo do path contra `EFI_WEBHOOK_SECRET`; Mercado Pago pelo HMAC-SHA256 do manifesto oficial (`x-signature` + `x-request-id` + `data.id`) com `MP_WEBHOOK_SECRET` e tolerância de timestamp de 300 s; Asaas pelo header `asaas-access-token` contra `ASAAS_WEBHOOK_TOKEN`. Segredo ausente/vazio no `.env` = endpoint daquele gateway recusa tudo (401) e loga "webhook desabilitado".
- **RF-03** — O webhook nunca deve confiar em status, valor ou identificação vindos do corpo: deve reconsultar o pagamento na API do gateway pelo SDK (host fixo do provedor, nunca URL/host do payload) e usar só o retorno da consulta.
- **RF-04** — O sistema deve garantir idempotência por `webhook_eventos` (UNIQUE `gateway` + `evento_id`): evento já processado responde 200 sem consulta nem efeito; evento cuja aplicação falhou antes (`processado_em` nulo) pode ser reprocessado no reenvio.
- **RF-05** — Pagamento confirmado pela consulta deve, numa **única transação**: registrar o evento, atualizar `cobrancas.status` (transição monotônica — status pago nunca regride para pendente), baixar o lançamento financeiro vinculado (OS: `os.lancamento`; venda: `vendas.lancamentos_id`) com `UPDATE ... WHERE baixado = 0` conferindo `affected_rows`, e gravar `processado_em`; depois do commit, `log_info()`.
- **RF-06** — O webhook deve conferir que o valor consultado no gateway é igual ao `cobrancas.total` (centavos) antes de baixar; divergência não baixa, registra o evento como processado com log de alerta e responde 200.
- **RF-07** — Os códigos de resposta do webhook devem seguir a regra de reentrega: 200 para aplicado, repetido, cobrança desconhecida (ignorado) ou payload irrelevante; 401 para autenticação inválida (sem detalhe); 413 para corpo acima de 64 KB; 429 acima do limite por IP; 503 para falha transitória (consulta ao gateway falhou/timeout, banco indisponível, rollback) — falha nunca marca o evento como processado.
- **RF-08** — A `notification_url` das cobranças Efí e Mercado Pago deve ser montada de `site_url()` + segredo do `.env` (Efí: `site_url('webhooks/efi/' . EFI_WEBHOOK_SECRET)`; MP: `site_url('webhooks/mercadopago')`); sem o segredo, a cobrança é criada **sem** `notification_url`. Nenhum domínio fixo (`mapos.com.br`, `seusite.com.br`) permanece no código.

**B. API do operador**

- **RF-09** — `PUT /api/v1/os/{id}` deve usar `osParaEditar()` (403 em OS `Faturado`/`Cancelado`/`faturado = 1`), nunca gravar `faturado` e recusar `status = "Faturado"` com 422 (faturar é só pelo fluxo de faturamento do painel).
- **RF-10** — Toda escrita da API v1 (OS e sub-rotas, clientes, produtos, serviços, usuários) deve montar os dados por allow-list explícita de campos; `faturado`, `valorTotal`, `lancamento`, `idOs`, `asaas_id`, `senha_trocar`, `sessao_versao`, `token_versao` nunca vêm do corpo; `usuarios` só aceita `permissoes_id` dentro de `podeDelegarGrupo()`.
- **RF-11** — A API do operador deve responder **403** para falta de permissão e **401** só para token ausente, inválido, expirado, de outro tipo ou de conta/grupo desativado.
- **RF-12** — `logged_user()` e `POST /api/v1/refresh` devem recusar (401) usuário inexistente, desativado, expirado ou cujo grupo de permissão está desativado (`Mapos_model::grupoAtivo()`); no refresh, a recusa revoga os refresh tokens do usuário.
- **RF-13** — Listagens da API (`os`, `clientes`, `produtos`, `servicos`, `usuarios`) devem aceitar `perPage` inteiro de 1 a 100 (padrão 20; acima de 100 → 100; não inteiro/≤0 → 422) e `page` inteiro ≥ 0 com `offset = page * perPage` (uma multiplicação só).
- **RF-14** — Os filtros de data da API (`from`/`to` em `GET /api/v1/os`, formato `dd/mm/aaaa`; `start`/`end` em `GET /api/v1/calendario`, formato `aaaa-mm-dd`) devem ser validados estritamente; formato inválido ou janela acima de 366 dias → 422. As datas de `POST`/`PUT /api/v1/os` (`dataInicial`, `dataFinal`) idem, substituindo os `try/catch` que não pegam warning.
- **RF-15** — Inserções de item em OS pela API (`produtos_post`, `servicos_post`, `anotacoes_post`) devem usar `$this->db->insert_id()` no lugar de `Api_model::lastRow()`.

**C. Conta e API do cliente**

- **RF-16** — Cliente criado pelo operador (painel `Clientes::adicionar`, cadastro rápido e `POST /api/v1/clientes`) sem senha informada deve receber senha aleatória forte gerada por CSPRNG (`clienteSenhaAleatoria()`), guardada só como `password_hash`, e `senha_trocar = 1`; senha informada pelo operador também grava `senha_trocar = 1`. O CPF/CNPJ nunca mais é senha.
- **RF-17** — A senha gerada deve ser exibida **uma única vez** ao operador (painel: tela `clientes/senha_inicial` renderizada na resposta do POST, sem redirect e sem flashdata; API: `result.senha_inicial` só na resposta 201, com `Cache-Control: no-store`) e enviada ao cliente por e-mail **síncrono** (fora de `email_queue`), informando `email_enviado` (bool). A senha em claro nunca vai para log, `log_info`, sessão, flashdata ou banco. Cadastro rápido não exibe nem envia (o cliente usa "Esqueci a senha").
- **RF-18** — A área do cliente deve forçar a tela "Defina sua nova senha" (`GET|POST /mine/definirSenha`) enquanto `clientes.senha_trocar = 1`: toda rota autenticada de `Mine` redireciona para ela, exceto ela própria e `mine/sair`.
- **RF-19** — A API do cliente deve responder 403 `{status:false, code:"troca_de_senha_obrigatoria", message}` em toda rota autenticada enquanto `senha_trocar = 1`, exceto `PUT /api/v1/client/password`, `POST /api/v1/client/refresh` e `POST /api/v1/client/logout`.
- **RF-20** — `PUT /api/v1/client/password` deve trocar a senha conferindo a atual por `Limite_login::confirmarSenha()` (escopo `confirmacao`), validar a nova (8–72 caracteres, diferente da atual), zerar `senha_trocar`, incrementar `token_versao` e `sessao_versao`, revogar os refresh tokens do cliente e devolver um par novo de tokens.
- **RF-21** — O access token do cliente deve durar no máximo 1 h (teto `Authorization_Token::TETO_COM_REFRESH` imposto em código) e vir com refresh token rotativo (`Refresh_token` com `tipo = 'cliente'`), com detecção de reuso que revoga a família e claim `sid` conferida em `logged_client()`.
- **RF-22** — O sistema deve expor `POST /api/v1/client/refresh` (gira o refresh do tipo cliente; reuso/expirado/revogado/de operador → 401) e `POST /api/v1/client/logout` (revoga a família → 204). Refresh de cliente nunca é aceito em `/api/v1/refresh` e vice-versa.
- **RF-23** — Troca de senha do cliente por qualquer caminho (`definirSenha`, `editarDados`, `senhaSalvar` do reset, `PUT /client/password`, edição pelo operador no painel/API) e exclusão do cliente devem, na mesma transação, incrementar `sessao_versao` e `token_versao` e revogar os refresh tokens `tipo = 'cliente'` daquele cliente (`Clientes_model::registrarTrocaDeSenha()`).
- **RF-24** — A sessão web do cliente deve ser revalidada no banco a cada requisição autenticada (`Mine::clienteLogado()`): cliente inexistente ou `sessao_versao` diferente da gravada na sessão encerra a sessão com aviso; erro de consulta encerra (fail-closed).
- **RF-25** — Abertura de OS pelo cliente (`Mine::adicionarOs` e `POST /api/v1/client/os`) deve respeitar o limite diário `CLIENTE_OS_LIMITE_DIARIO` (padrão 10; 0 desliga), contado de forma atômica no dia-calendário de `APP_TIMEZONE`; acima do limite → 429 com `Retry-After` (segundos até 00:00) na API e mensagem na tela, sem criar a OS. A notificação por e-mail da equipe é mantida. A API passa a gravar o histórico (`registrarStatus`) como a tela.
- **RF-26** — O sistema deve oferecer o comando `php index.php tools marcarSenhasLegadas` que marca `senha_trocar = 1` nos clientes cuja senha ainda é o próprio CPF/CNPJ sem pontuação (conferido por `password_verify`), imprimindo só a quantidade marcada.

**D. Autenticação do painel e da área do cliente**

- **RF-27** — Logout (`login/sair` e `mine/sair`) só por POST com CSRF; GET responde 405 com `Allow: POST` sem encerrar a sessão; todos os "Sair" viram formulário POST; o campo CSRF é renovado do cookie no submit (módulo `layout/sair`).
- **RF-28** — A ativação do 2FA (`Mapos::ativarDoisFatores`) deve conferir a senha por `Limite_login::confirmarSenha()` e contar código TOTP errado como falha do escopo `confirmacao`; bloqueado → mensagem `Limite_login::MENSAGEM` sem conferir nada.
- **RF-29** — `POST /login/verificarLogin` e `POST /mine/login` devem responder JSON (`Content-Type: application/json`) com status: 200 sucesso; 422 validação (`erros` por campo); 401 credenciais (mensagem genérica única); 403 conta desativada/expirada/grupo desativado (só após senha certa, como hoje); 429 bloqueado com `Retry-After` e `retry_after` (segundos). O token CSRF novo vai no campo de nome `$this->security->get_csrf_token_name()` (não mais `MAPOS_TOKEN` fixo).
- **RF-30** — O token de redefinição de senha deve ser consumido de forma atômica **antes** da troca (`UPDATE resets_de_senha SET token_utilizado = 1 WHERE id = ? AND token_utilizado = 0` com `affected_rows === 1`); zero linhas = token inválido.
- **RF-31** — O seed `Usuarios` (`admin@admin.com`/`123456`) só roda com `ENVIRONMENT === 'development'`; em outro ambiente o seed sai sem inserir e imprime o motivo.

**E. Tratamento de erros no servidor**

- **RF-32** — `REST_Controller::_remap()` deve capturar `\Throwable`, logar (classe, mensagem, arquivo:linha, rota) e responder 500 `{status:false, message:"Erro interno"}` em JSON, em qualquer ambiente; nunca `show_exception()`.
- **RF-33** — As exclusões do painel e da API de OS, vendas, clientes, produtos, serviços e cobranças devem rodar em transação (`trans_begin`/`trans_status`/`trans_commit`/`trans_rollback`), conferindo cada retorno; falha → rollback, `log_message('error')` e mensagem "Não foi possível excluir. Nada foi alterado." (flashdata no painel; 500 na API); sucesso só com commit. As regras de bloqueio atuais (cobrança ativa impede excluir OS/venda) são mantidas.
- **RF-34** — Os catches que engolem erro (`Clientes_model.php:194,230`, `Mapos_model.php:539`) devem logar e decidir pelo retorno real/`trans_status()`, não pela exceção (que não dispara com `db_debug` desligado).
- **RF-35** — Faturamento (OS e venda), `Os_model::registrarStatus`, `Mapos_model::desativarTotp` e o enfileiramento de e-mail (`MY_Email::send()`) devem ter o retorno conferido pelos chamadores, com `log_message('error')` em falha e mensagem de erro ao usuário em vez de sucesso.
- **RF-36** — Os `@` que silenciam falha devem ser trocados por checagem de retorno + `log_message('error')`; na gravação do `.htaccess` e do `index.html` de proteção (`armazenamento_helper.php`, `general_helper.php::protegerDiretorioUpload`) a falha é obrigatoriamente logada e o `.htaccess` passa a ter o bloco `mod_authz_core` (`Require all denied`) com fallback 2.2, regravado quando o existente não contém `mod_authz_core`.
- **RF-37** — `null` deve ser convertido antes de funções de string (`(string)`/`?? ''`) nos pontos tocados por esta feature, sem `Deprecated` do PHP 8.5 no log ao executar os fluxos de RF-09, RF-14, RF-16 e RF-29.
- **RF-38** — `save_queries` deve ficar desligado em produção (`'save_queries' => ENVIRONMENT !== 'production'`).

**F. Gateways (erros)**

- **RF-39** — `MercadoPago.php` não deve mais lançar `new \Exception(array)` (usar `mpErrorMessage()` em todo `catch` de `MPApiException`) e a descrição deve ser `"OS #<id>"` para OS e `"Venda #<id>"` para venda.
- **RF-40** — Toda falha de gateway deve ser logada por `log_message('error')` com gateway, operação, id da cobrança, código HTTP e duração, sem token, CPF/CNPJ, segredo ou corpo bruto.
- **RF-41** — As chamadas a Mercado Pago e Asaas devem ter timeout de conexão e de resposta configuráveis (`PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT`, `PAYMENT_GATEWAYS_ASAAS_TIMEOUT`, padrão 10 s); o Efí mantém `PAYMENT_GATEWAYS_EFI_TIMEOUT` com padrão reduzido de 30 para 10 s.
- **RF-42** — Os gateways devem lançar `GatewayRecusouException` (validação/recusa do provedor, mensagem amigável em pt-BR segura para o usuário) ou `GatewayIndisponivelException` (falha técnica/timeout, mensagem genérica); `Cobrancas::adicionar` responde 422 / 502 com `{result:false, message}` e 403/400 atuais ganham `result`; as ações por formulário (`atualizar`, `confirmarPagamento`, `cancelar`, `excluir`) mostram a mensagem amigável no flashdata, nunca o texto cru do gateway.

**G. Fila de e-mail (cron)**

- **RF-43** — `email/process` deve rodar sob lock exclusivo entre execuções (`GET_LOCK('mapos_email_fila', 0)`); lock ocupado → loga "ignorado" e sai com código 0.
- **RF-44** — A reserva deve ser atômica por lote: `UPDATE ... SET status='sending', reservado_por=?, reservado_em=NOW(), tentativas=tentativas+1 WHERE status='pending' AND tentativas < 5 ORDER BY id LIMIT 50`, depois `SELECT` só dos reservados por aquela execução.
- **RF-45** — Cada e-mail é enviado dentro de `try/catch (\Throwable)` próprio; sucesso → `sent`; falha → `failed` (com `tentativas >= 5` é definitivo). `email/retry` volta para `pending` só `failed` com `tentativas < 5` e `sending` com reserva expirada (> 15 min); reserva expirada com `tentativas >= 5` vira `failed`.
- **RF-46** — O log de falha de envio deve usar `print_debugger(['headers'])` (nunca o corpo) com id e tentativas.
- **RF-47** — `email/process` e `email/retry` devem logar início, fim e contagens (reservados/enviados/falhas/definitivos/reabertos) e terminar com `exit(0)` em sucesso ou lock ocupado e `exit(1)` em falha técnica do ciclo (erro de banco).
- **RF-48** — `email/retry` deve aplicar a retenção (`Retencao::limpar()`), em lotes de até 500 linhas por tabela: `webhook_eventos` com `created_at` > 90 dias; `api_refresh_tokens` expirados ou revogados há > 30 dias; `email_queue` `sent` > 7 dias e `failed` > 30 dias.

**H. Frontend**

- **RF-49** — Requisição AJAX sem sessão válida (painel `MY_Controller`, área do cliente `Mine::clienteLogado`) deve receber 401 JSON `{result:false, message:"Sessão expirada", redirect:"<site_url('login') ou site_url('mine')>"}` e flashdata "Sua sessão expirou. Entre novamente."; `redirect` é sempre caminho interno fixo, nunca derivado de entrada.
- **RF-50** — `assets/js/lib/http.js` deve tratar 401 com `redirect` (e `response.redirected` para HTML) como sessão expirada: toast `warning` "Sua sessão expirou. Entre novamente." e ida ao `redirect` após 1,5 s, sem resolver a promessa (o chamador não mostra sucesso nem segundo erro).
- **RF-51** — `http.js` deve ter timeout padrão de 30 s (AbortController, opção `timeout`), lançar `ErroHttp` status 0 com "Sem conexão ou a resposta demorou demais. Tente novamente." em rede/timeout e `ErroHttp` com "Resposta inesperada do servidor." para JSON inválido; `app.js` registra handler global de `unhandledrejection` que mostra toast `danger` e faz `console.error`.
- **RF-52** — Os catches de `assets/js/modules/layout/shell.js` que hoje engolem falha devem mostrar toast `danger` com a mensagem do `ErroHttp`.
- **RF-53** — O módulo de login (`modules/login/login.js`) deve mapear as respostas de RF-29: 422 → erro inline por campo; 401/403 → `message` no alert; 429 → alert com o tempo restante; 403 sem JSON (CSRF recusado) → `MENSAGEM_SESSAO`; rede → `MENSAGEM_REDE`; o token CSRF é lido pelo nome da meta `csrf-token-name`.
- **RF-54** — As telas de RF-17 e RF-18 e as mensagens de RF-25, RF-33 e RF-42 devem seguir §5 (componentes existentes, tokens, a11y).

**I. Bugs vistos de passagem**

- **RF-55** — `ClientComprasController` deve gerar o QR da venda por `Vendas_model::getQrCode()`.
- **RF-56** — `ApiController::calendario_get` deve devolver os eventos (`$events`) no lugar de `$allOs`.
- **RF-57** — A paginação da API do cliente (`ClientOsController`, `ClientComprasController`, `ClientCobrancasController`) deve usar `perPage`/`page` da query string com a regra de RF-13, no lugar de `$this->uri->segment(3)`.

**J. Configuração, infraestrutura e documentação**

- **RF-58** — `application/.env.example` deve listar `EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN` (vazios), `WEBHOOK_RATE_LIMIT=120`, `CLIENTE_OS_LIMITE_DIARIO=10`, `PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT=10`, `PAYMENT_GATEWAYS_ASAAS_TIMEOUT=10`, `PAYMENT_GATEWAYS_EFI_TIMEOUT=10`.
- **RF-59** — O nginx do `docker/` deve não registrar no access log o path `/webhooks/efi/` (segredo no path) e manter os cabeçalhos de segurança e `client_max_body_size` nas rotas de webhook; o cron do Docker (`docker/etc/php/cron-jobs`) continua chamando `email/process` (2 min) e `email/retry` (5 min).
- **RF-60** — O script `scripts/smoke-seguranca.sh` deve subir pelo comando do RUNBOOK (`cd docker && docker-compose up -d`), esperar `curl -sf http://localhost:8000/`, bater nas rotas da tabela de smoke (§7), derrubar (`docker-compose down`) e sair ≠ 0 se algum veredito falhar.
- **RF-61** — A documentação deve ser atualizada: `docs/webhooks-de-pagamento.md` (novo: cadastro da URL em cada gateway, HTTPS obrigatório, rotação de segredo, mascarar o path Efí no access log, reprocessar evento, destravar a fila), `docs/api-autenticacao.md` (401×403, refresh/logout/troca de senha do cliente, `senha_inicial`, 403 `troca_de_senha_obrigatoria`, 429 de OS, `perPage`), `docs/servidor-web.md` (`.htaccess` 2.4), `docs/migracao-4x-para-5.md` (variáveis novas, migrations, comando `marcarSenhasLegadas`, logout POST, login com status) e `CHANGELOG.md` (breaking changes listadas em §6).

## 2. Stack Tecnológica

Projeto existente — **nenhuma tecnologia nova**. PHP 8.5, CodeIgniter 3.1, MySQL 8.4/MariaDB, Query Builder, migrations em timestamp, views PHP + Tailwind 4 (tokens), JS ES modules sem bundler, PHPUnit 12 + node:test, SDKs já instalados (`efipay/sdk-php-apis-efi` 1.17.0, `mercadopago/dx-php` 3.8.0, `codephix/asaas-sdk` v2.0.12).

| Camada | Componente | Tecnologia | Observação |
| ------ | ---------- | ---------- | ---------- |
| Backend | Webhooks | `application/controllers/Webhooks.php` (`CI_Controller`) | Fora da API: funciona com `API_ENABLED=false` |
| Backend | Limite por IP reutilizável | `application/libraries/Limite_requisicoes.php` | Extraído de `REST_Controller::limiteDeRequisicoes()`, mesma tabela `api_rate_limit` |
| Backend | Retenção | `application/libraries/Retencao.php` | Chamada por `email/retry` |
| Backend | Exceções de gateway | `application/libraries/Gateways/Exceptions/` | `GatewayRecusouException`, `GatewayIndisponivelException` |

## 3. Modelagem de Dados

Todas as mudanças por migration timestamp reversível em `application/database/migrations/` (classe `Migration_<nome>`), só aditivas, com conferência pós-DDL (`table_exists`/`field_exists`) lançando `RuntimeException` (o `db_debug` fica desligado em produção). **`banco.sql` não é editado** (é o esquema 4.x; o instalador roda as migrations depois). Nomes em português, no padrão das tabelas vizinhas.

```sql
-- 20261103000000_create_webhook_eventos.php
CREATE TABLE webhook_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gateway ENUM('efi','mercadopago','asaas') NOT NULL,
  evento_id VARCHAR(128) NOT NULL,
  cobranca_id INT NULL,                 -- cobrancas.idCobranca; sem FK (evento guardado mesmo sem cobrança)
  payload_hash CHAR(64) NOT NULL,        -- sha256 do corpo bruto; o corpo NUNCA é persistido
  resultado VARCHAR(20) NULL,            -- 'aplicado' | 'ignorado' | 'divergente' | 'sem_efeito'
  processado_em DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY webhook_eventos_gateway_evento (gateway, evento_id),
  KEY webhook_eventos_cobranca (cobranca_id),
  KEY webhook_eventos_created (created_at)
) ENGINE=InnoDB;
-- down(): DROP TABLE webhook_eventos

-- 20261103000100_add_tipo_to_api_refresh_tokens.php
ALTER TABLE api_refresh_tokens
  ADD COLUMN tipo ENUM('usuario','cliente') NOT NULL DEFAULT 'usuario' AFTER id;  -- legado = operador
CREATE INDEX api_refresh_tokens_dono ON api_refresh_tokens (tipo, usuario_id);
DROP INDEX usuario_id ON api_refresh_tokens;  -- nome real do índice simples criado por add_key('usuario_id'); conferir com SHOW INDEX
-- down(): DELETE FROM api_refresh_tokens WHERE tipo = 'cliente'; recria índice simples (usuario_id); DROP INDEX api_refresh_tokens_dono; DROP COLUMN tipo

-- 20261103000200_add_senha_trocar_sessao_versao_to_clientes.php
ALTER TABLE clientes
  ADD COLUMN senha_trocar TINYINT(1) NOT NULL DEFAULT 0,      -- clientes existentes = 0 (sem troca em massa)
  ADD COLUMN sessao_versao INT UNSIGNED NOT NULL DEFAULT 0;   -- mesmo padrão de usuarios.sessao_versao
-- clientes.token_versao já existe (20261014050000) e é reaproveitada
-- down(): DROP COLUMN senha_trocar, sessao_versao

-- 20261103000300_add_reserva_to_email_queue.php
ALTER TABLE email_queue
  ADD COLUMN tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN reservado_por CHAR(32) NULL,
  ADD COLUMN reservado_em DATETIME NULL;
CREATE INDEX email_queue_status_reserva ON email_queue (status, reservado_por);
-- status continua ENUM('pending','sending','sent','failed'): nenhum valor novo
-- down(): DROP INDEX; DROP COLUMN tentativas, reservado_por, reservado_em

-- 20261103000400_add_index_charge_to_cobrancas.php
CREATE INDEX cobrancas_charge_gateway ON cobrancas (charge_id(64), payment_gateway(32));
-- criar só se não houver índice começando por charge_id (conferir information_schema.statistics)
-- down(): DROP INDEX cobrancas_charge_gateway
```

**Contagem diária de OS do cliente (sem tabela nova):** contada em `os_historico` (já indexada por `os_id`) — linhas com `usuarios_id IS NULL AND status_anterior IS NULL AND status_novo = 'Aberto'` (abertura pelo cliente, como `Mine::adicionarOs` já grava) unidas a `os` por `os_id` com `os.clientes_id = ?` e `data_hora >= <hoje 00:00:00 em APP_TIMEZONE>`. OS excluída sai da contagem (limitação aceita: o limite é antiabuso, não de cobrança).

**Valores de `payment_gateway` reais** (usados para localizar a cobrança no webhook): `'GerencianetSdk'`, `'MercadoPago'`, `'Asaas'`. Mapa: `efi → GerencianetSdk`, `mercadopago → MercadoPago`, `asaas → Asaas`.

**Status considerados "pago"** (conjunto monotônico — uma vez num deles, a cobrança não volta a pendente): Efí `paid`, `settled`; Mercado Pago `approved`; Asaas `RECEIVED`, `CONFIRMED`, `RECEIVED_IN_CASH`, `DUNNING_RECEIVED`.

**Retenção (RF-48):** `webhook_eventos` 90 dias (> janela de reenvio dos gateways; apagar reabre a idempotência daquele id); `api_refresh_tokens` expirados/revogados há 30 dias; `email_queue` `sent` 7 dias, `failed` 30 dias. Hard delete em lotes de 500.

**LGPD:** `webhook_eventos` não guarda dado do pagador; senha inicial em claro nunca é persistida (e-mail síncrono, fora da fila); refresh só como SHA-256; segredos só no `.env`.

## 4. Contratos e Arquitetura

### Endpoints / Interfaces

**Webhooks** (rotas em `application/config/routes.php`, fora do `if (API_ENABLED)`; `csrf_exclude_uris` += `'webhooks/(efi/[^/]+|mercadopago|asaas)'`):

```
POST /webhooks/efi/{segredo}
  body (form-urlencoded): notification=<token>
  evento_id = notification
  consulta: $efi->getNotification(['token' => $notification]) → último item do histórico:
            identifiers.charge_id, status.current; valor por detailCharge(charge_id).data.total (centavos)

POST /webhooks/mercadopago
  headers: x-signature: "ts=<ts>,v1=<hex>"; x-request-id: <uuid>
  query:   data.id=<id>&type=payment      (PHP converte "data.id" em $_GET['data_id'])
  body:    {"id": <notificacao>, "type": "payment", "action": "...", "data": {"id": "<pagamento>"}}
  assinatura: v1 == hash_hmac('sha256', "id:{dataId};request-id:{xRequestId};ts:{ts};", MP_WEBHOOK_SECRET)
              dataId = $_GET['data_id'] ?? body.data.id, em minúsculas quando alfanumérico;
              |agora - ts| <= 300 s (ts > 10^12 → milissegundos ÷ 1000)
  evento_id = body.id ?? x-request-id ;  type != "payment" → 200 ignorado (sem consulta)
  consulta: (new PaymentClient())->get(dataId) → status, transaction_amount

POST /webhooks/asaas
  header: asaas-access-token: <ASAAS_WEBHOOK_TOKEN>
  body:   {"id": "evt_...", "event": "PAYMENT_RECEIVED", "payment": {"id": "pay_..."}}
  evento_id = body.id ?? (event . ':' . payment.id) ; sem payment.id → 200 ignorado
  consulta: $asaas->Cobranca()->getById(payment.id) → status, value

Respostas (todas JSON, Content-Type application/json, Cache-Control: no-store):
  200 {"status":"aplicado"|"repetido"|"ignorado"|"divergente"|"sem_efeito"}
  401 {"status":"recusado"}            -- sem detalhe; também com segredo ausente no .env
  405 (Allow: POST)                    -- método ≠ POST
  413 {"status":"recusado"}            -- corpo > 65536 bytes (lido de php://input com limite)
  429 (Retry-After)                    -- Limite_requisicoes escopo 'webhook', WEBHOOK_RATE_LIMIT/min/IP
  503 {"status":"tente_novamente"}     -- consulta falhou/timeout, banco, rollback
```

Algoritmo (cada gateway implementa só autenticação, extração do `evento_id` e consulta; o resto é comum em `Webhooks::processar()`):

```php
// application/controllers/Webhooks.php — contrato dos passos comuns
private function processar(string $gateway, string $eventoId, string $corpoBruto, callable $consultar): void
// 1. evento já com processado_em != null        → 200 repetido (sem consulta)
// 2. $pagamento = $consultar()                    → ['charge_id' => string, 'status' => string, 'valor_centavos' => int]
//    exceção/timeout                              → log + 503
// 3. cobrança por (charge_id, payment_gateway)    → inexistente: grava evento resultado='ignorado', 200 ignorado
// 4. valor_centavos !== (int) cobrancas.total     → grava evento resultado='divergente', log nível error "alerta", 200 divergente
// 5. trans_begin: INSERT evento (ou UPDATE se já existia com processado_em null);
//    UPDATE cobrancas.status (pula se status atual ∈ PAGOS e novo ∉ PAGOS);
//    se novo ∈ PAGOS e há lançamento: UPDATE lancamentos SET baixado=1, data_pagamento=CURDATE() WHERE idLancamentos=? AND baixado=0;
//    UPDATE webhook_eventos SET processado_em=NOW(), resultado='aplicado'|'sem_efeito';
//    trans_status() false → rollback + 503; violação do UNIQUE no INSERT → rollback + 200 repetido
// 6. commit → log_info('Pagamento confirmado por webhook. Cobrança ' . $id) → 200 aplicado
```

**API do cliente** (`application/config/routes_api.php`):

```
POST /api/v1/client/auth            body {email, password}            -- nome do campo mantido (compatibilidade)
  200 {status:true, message, result:{access_token, refresh_token, token_type:"Bearer", expires_in:<=3600, troca_de_senha_obrigatoria:bool}}
  401 {status:false, message:"Os dados de acesso estão incorretos."}
  429 {status:false, message: Limite_login::MENSAGEM} + Retry-After

POST /api/v1/client/refresh         body {refresh_token}
  200 {status:true, message:"Token renovado!", result:{access_token, refresh_token, token_type, expires_in}}
  401 {status:false, message:"Faça o login novamente!"}   -- inválido, expirado, revogado, reutilizado (revoga a família), tipo usuario

POST /api/v1/client/logout          body {refresh_token}          → 204 sem corpo (sempre)

PUT  /api/v1/client/password        Authorization: Bearer; body {senha_atual, senha_nova}
  200 {status:true, message:"Senha alterada.", result:{access_token, refresh_token, token_type, expires_in}}
  401 token ausente/inválido · 422 {status:false, message, erros:{senha_atual?|senha_nova?}} · 429 confirmacao bloqueada

POST /api/v1/client/os              → 201 {status:true, message, os_id} | 422 validação | 429 {status:false, message:"Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência."} + Retry-After

Demais rotas autenticadas com senha_trocar=1 → 403 {status:false, code:"troca_de_senha_obrigatoria", message:"Defina uma nova senha antes de continuar."}
Token ausente/inválido/expirado/de operador/família revogada → 401 {status:false, message:"Faça o login novamente!"}
```

**API do operador:**

```
PUT  /api/v1/os/{id}     → 200 | 401 | 403 sem eOs | 403 "OS Faturada/Cancelada não pode ser alterada." | 404 OS inexistente | 422 datas/status Faturado
POST /api/v1/clientes    → 201 {status:true, message, result:{...cliente sem senha, senha_inicial?:string, email_enviado?:bool}}  (senha_inicial só se gerada) ; Cache-Control: no-store
GET  listagens           → perPage 1..100 (padrão 20), page >= 0 ; 422 se não inteiro
Falta de permissão       → 403 {status:false, message}
Token ausente/inválido/expirado/de cliente/usuário ou grupo desativado → 401 {status:false, message:"Faça o login novamente!"}
Exceção não tratada      → 500 {status:false, message:"Erro interno"}
```

Padrões reutilizáveis (assinaturas fixadas):

```php
// application/libraries/REST_Controller.php
protected function exigirPermissao(string $permissao, string $mensagem): void;   // 403 se negar; substitui os ~40 checkPermission + HTTP_UNAUTHORIZED
public function logged_client(bool $permitirTrocaPendente = false);               // 401 / 403 troca_de_senha_obrigatoria
protected function paginacaoDaApi(): array;                                       // [int $limite, int $offset] ou 422

// application/libraries/Refresh_token.php — tipo com padrão 'usuario' (chamadas atuais seguem iguais)
public function emitir(int $donoId, ?string $ip = null, ?string $userAgent = null, ?string $familia = null, string $tipo = 'usuario'): ?string;
public function girar(string $token, ?string $ip = null, ?string $userAgent = null, string $tipo = 'usuario'): array; // linha de outro tipo = ['ok'=>false,'motivo'=>'invalido']
public function revogarDoUsuario(int $donoId, string $tipo = 'usuario'): void;
public function familiaDoToken(string $token): ?string;  // inalterado

// application/libraries/Limite_requisicoes.php
public function segundosParaLiberar(string $escopo, string $ip, int $limitePorMinuto): int; // 0 = liberado; chave = hmac(escopo|ip)

// application/libraries/Retencao.php
public function limpar(int $lote = 500): array; // ['webhook_eventos'=>n, 'api_refresh_tokens'=>n, 'email_queue'=>n]

// application/models/Clientes_model.php
public function registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool; // transação: senha, senha_trocar, sessao_versao+1, token_versao+1, revoga refresh tipo cliente
public function excluirComDependentes(int $idCliente): bool;                                  // transação; revoga refresh tipo cliente

// application/models/Conecte_model.php
public function aberturasDeOsHoje(int $idCliente): int;
public function abrirOsDoCliente(int $idCliente, array $dados, int $limite): array; // ['ok'=>true,'id'=>int] | ['ok'=>false,'motivo'=>'limite'|'erro']; transação com SELECT ... FROM clientes WHERE idClientes=? FOR UPDATE, conta, insere OS + registrarStatus

// application/helpers/clientes_helper.php
function clienteSenhaAleatoria(int $tamanho = 16): string; // random_int sobre 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'

// application/libraries/Gateways/Exceptions/GatewayRecusouException.php     extends \RuntimeException (mensagem segura p/ usuário)
// application/libraries/Gateways/Exceptions/GatewayIndisponivelException.php extends \RuntimeException (mensagem genérica)
```

**Painel (AJAX / formulários):**

```
Sessão expirada (AJAX, X-Requested-With)  → 401 {result:false, message:"Sessão expirada", redirect:"<site_url('login')>"}   (área do cliente: site_url('mine'))
POST /login/verificarLogin, POST /mine/login (Content-Type: application/json)
  200 {result:true, destino?}  ·  422 {result:false, message, erros:{email?, senha?}, <csrf_name>:hash}
  401 {result:false, message:"Os dados de acesso estão incorretos.", <csrf_name>:hash}
  403 {result:false, message:<motivoDeBloqueio>, <csrf_name>:hash}
  429 {result:false, message: Limite_login::MENSAGEM, retry_after:<s>, <csrf_name>:hash} + Retry-After
POST /login/sair, POST /mine/sair (CSRF) → 302 login ; GET → 405 Allow: POST
POST /cobrancas/adicionar → 200 cobrança | 400/403 {result:false, message} | 422 {result:false, message:<amigável>} | 502 {result:false, message:"O provedor de pagamento não respondeu. Tente novamente em instantes."}
GET|POST /mine/definirSenha → tela; POST válido → 302 mine/painel + flash success
```

Formato de log (linha única `chave=valor`, via `log_message`): `webhook gateway=<g> evento=<id> cobranca=<id> resultado=<aplicado|repetido|ignorado|divergente|recusado|falha> http=<cod> duracao_ms=<n>`; `gateway op=<operacao> gateway=<g> cobranca=<id> http=<cod> duracao_ms=<n> erro=<classe>`; `email_fila ciclo=<process|retry> reservados=<n> enviados=<n> falhas=<n> definitivos=<n>`; `api excecao classe=<c> mensagem=<m> local=<arquivo:linha> rota=<uri>`; `transacao rollback operacao=<op> id=<id>`; `refresh reuso tipo=<usuario|cliente> dono=<id>` (nível error). **Nunca:** senha (inclusive a gerada), JWT/refresh, segredos de webhook, path do webhook Efí (logar `rota=webhooks/efi/***`), corpo de e-mail, payload bruto do gateway, CPF/CNPJ completo.

### Fluxo de Dados

- **Webhook:** gateway → `Webhooks` (síncrono) → autentica → consulta o gateway (síncrona, timeout 10 s) → transação MySQL (`webhook_eventos`, `cobrancas`, `lancamentos`) → 200. O retry é do gateway (reentrega em 5xx).
- **Senha inicial:** operador (painel/API) → `clienteSenhaAleatoria()` → hash em `clientes` → e-mail síncrono `MY_Email::send(true)` → resposta única com a senha → descarte.
- **Primeiro acesso:** cliente → login → `senha_trocar=1` → `/mine/definirSenha` (web) ou 403 até `PUT /client/password` (API) → `registrarTrocaDeSenha()`.
- **Fila:** telas → `email_queue` (`pending`) → cron `email/process` (lock + reserva) → SMTP → `sent`/`failed`; cron `email/retry` → reabre/expira + `Retencao::limpar()`.
- **Sessão expirada:** `fetch` → 401 JSON → `http.js` toast + `location.assign(redirect)`.

### Impacto em Arquivos Existentes

| Arquivo / Módulo | Mudança | Observação |
| --- | --- | --- |
| `application/controllers/Webhooks.php` | **novo** | RF-01..RF-07 |
| `application/config/routes.php`, `config/config.php` | rotas de webhook; `csrf_exclude_uris` | RF-01 |
| `application/config/routes_api.php` | `client/refresh`, `client/logout`, `client/password` | RF-22, RF-20 |
| `application/libraries/Gateways/{GerencianetSdk,MercadoPago,Asaas}.php` | `notification_url`, timeouts, exceções tipadas, consulta para webhook, descrição | RF-08, RF-39..RF-42 |
| `application/libraries/Gateways/Exceptions/*` | **novo** | RF-42 |
| `application/config/payment_gateways.php` | `timeout` MP/Asaas; Efí padrão 10 | RF-41 |
| `application/controllers/Cobrancas.php` | 422/502 com `result`; mensagens amigáveis; exclusão em transação | RF-33, RF-42 |
| `application/libraries/REST_Controller.php` | `\Throwable`; 401×403; `exigirPermissao`; `logged_user` grupo; `logged_client` sid/troca; `paginacaoDaApi`; usa `Limite_requisicoes` | RF-11..RF-13, RF-19, RF-21, RF-32 |
| `application/libraries/Refresh_token.php` | parâmetro `tipo` | RF-21, RF-22 |
| `application/libraries/Limite_requisicoes.php`, `Retencao.php` | **novos** | RF-07, RF-48 |
| `application/controllers/api/v1/*.php` | `exigirPermissao`, allow-list, paginação, datas, `insert_id`, exclusões em transação, `senha_inicial` | RF-09..RF-16, RF-33 |
| `application/controllers/api/v1/client/*.php` | refresh/logout/password, teto 1 h, limite de OS, paginação, QR venda | RF-19..RF-22, RF-25, RF-55, RF-57 |
| `application/controllers/api/v1/ApiController.php` | `$events` | RF-56 |
| `application/controllers/Clientes.php`, `helpers/clientes_helper.php` | senha aleatória, tela única, exclusão em transação | RF-16, RF-17, RF-33 |
| `application/controllers/Mine.php`, `helpers/conecte_helper.php`, `models/Conecte_model.php` | `definirSenha`, revalidação, limite de OS, sair POST, login JSON, reset atômico | RF-18, RF-23..RF-25, RF-27, RF-29, RF-30 |
| `application/models/Clientes_model.php`, `Mapos_model.php` | `registrarTrocaDeSenha`, `excluirComDependentes`, catches | RF-23, RF-34 |
| `application/controllers/Login.php`, `Mapos.php` | sair POST, login JSON, 2FA com `confirmarSenha` | RF-27..RF-29 |
| `application/core/MY_Controller.php` | 401 JSON em AJAX sem sessão | RF-49 |
| `application/controllers/{Os,Vendas,Produtos,Servicos}.php` | exclusão em transação; retorno de faturamento conferido | RF-33, RF-35 |
| `application/libraries/MY_Email.php`, `controllers/Email.php` | lock, reserva, tentativas, log de cabeçalhos, exit code, retenção | RF-43..RF-48 |
| `application/controllers/Tools.php`, `database/seeds/Usuarios.php` | `marcarSenhasLegadas`; seed só em development | RF-26, RF-31 |
| `application/helpers/armazenamento_helper.php`, `general_helper.php` | `.htaccess` 2.4, sem `@` | RF-36 |
| `application/config/database.php` | `save_queries` | RF-38 |
| `application/database/migrations/20261103*` | **novas** (5) | §3 |
| `application/views/clientes/senha_inicial.php`, `views/conecte/definir_senha.php` | **novas** | RF-17, RF-18 |
| `application/views/tema/{conteudo,menu}.php`, `views/conecte/{template,conta}.php`, `views/mapos/{login_dois_fatores,banco-desatualizado}.php` | "Sair" como form POST | RF-27 |
| `assets/js/lib/http.js`, `assets/js/app.js`, `modules/layout/shell.js`, `modules/login/login.js` | 401, timeout, JSON inválido, `unhandledrejection`, login por status | RF-50..RF-53 |
| `assets/js/modules/layout/sair.js`, `modules/clientes/senha-inicial.js` | **novos** | RF-27, RF-17 |
| `application/.env.example`, `docker/etc/nginx/*.conf`, `docker/etc/php/cron-jobs`, `scripts/smoke-seguranca.sh` | variáveis, log, smoke | RF-58..RF-60 |
| `docs/*.md`, `CHANGELOG.md` | documentação | RF-61 |

## 5. Telas e Interface

### Fundação Visual

**Base:** `DESIGN` (fundação já decidida — `DESIGN.md`, `assets/src/tokens.css`, shell `.v5-shell`/`.v5-conteudo`, telas de entrada em `.superficie-entrada`). Esta feature **não acrescenta nem diverge** de tipografia, reset, app shell ou tokens.

**Design System:** só tokens semânticos existentes (`warning` senha exibida uma vez/troca obrigatória, `danger` falha, `success` confirmação, `muted` apoio). Um padrão por problema: feedback transitório em `toast`; aviso persistente que exige leitura em `alert`; erro de campo inline no componente. Formulários seguem `formulario/padrao` (`novalidate`, erro inline com texto do servidor, botão em loading, Enter submete, POST com CSRF).

### Contratos de Componente

Nenhum componente novo no inventário. Reuso:

| Componente | Variantes / estados | Contrato obrigatório |
| --- | --- | --- |
| `alert` | warning · danger · success | Aviso de senha gerada e de sessão expirada com `role="status"`; erro com `role="alert"`; estado nunca só por cor (ícone + texto) |
| `input` (password) | default / focus / error / disabled / readonly | Senha gerada em campo `readonly` com rótulo visível "Senha inicial", fonte monoespaçada (`font-mono` do token); erro ligado por `aria-describedby` |
| `button` | primary (Salvar) · outline (Copiar senha) · ghost (Sair) · loading | Copiar com rótulo **visível** "Copiar senha"; mostrar/ocultar senha com rótulo acessível **não renderizado visualmente** ("Mostrar senha"/"Ocultar senha") e `aria-pressed`; alvo ≥ 44px no celular |
| `toast` | warning · danger · success | "Copiado" anunciado em região `aria-live="polite"`; toast de erro `role="alert"` |
| `card` | default | Formulário "Defina sua nova senha" |
| `modal-confirm` | closed / open | Exclusão com falha: o modal fecha e o erro vem por toast `danger` (flashdata) |

**Composição "senha exibida uma única vez"** (não é componente; usada só em `clientes/senha_inicial`): `alert` warning + `input` readonly + `button` outline "Copiar senha" + texto de envio. Módulo `assets/js/modules/clientes/senha-inicial.js` (Clipboard API; sem `localStorage`/`sessionStorage`; sem `console.log` da senha).

### /clientes/adicionar (POST com sucesso) — Senha inicial do cliente · **modificada**

**Compõe:** `alert` (warning), `input` password readonly, `button` outline, `button` ghost/link, `toast`.
**Estrutura:** wrapper da tela (`flex flex-col gap-4 pt-2 pb-8 max-w-4xl`), `h1` "Cliente cadastrado", um `card` com o `alert` no topo e, abaixo, campo + Copiar na mesma linha (empilham abaixo de 640px); ações de navegação ao fim.

```
[!] Senha inicial do cliente: exibida apenas agora
[ Senha inicial  •••••••••••••••• (mono, readonly) ] [Copiar senha]
Também enviada por e-mail ao cliente.          [Ver cliente] [Voltar à lista]
```

**Conteúdo:** nome do cliente, senha gerada, texto de envio: `email_enviado` true → "Também enviada por e-mail ao cliente."; false → "Não foi possível enviar por e-mail: repasse a senha ao cliente." (nunca afirmar envio que não ocorreu). Sem senha gerada (operador digitou a senha) → redirect com toast como hoje.
**Ações disponíveis:** Copiar senha; Ver cliente (`clientes/visualizar/<id>`); Voltar à lista (`clientes`).
**Navegação:** sair ou recarregar não reexibe a senha (recarregar reenvia o POST, recusado pelo CSRF regenerado). Resposta com `Cache-Control: no-store`.
**Estados:** Loading: n/a (resposta já traz o dado). Vazio: n/a. Erro: Clipboard indisponível → seleciona o texto do campo e toast "Copie manualmente".

### /mine/definirSenha — Defina sua nova senha · **criada**

**Compõe:** `card`, `alert` (warning), `input` password ×3, `button` primary, `button` ghost (Sair, form POST).
**Estrutura:** superfície de entrada escura (`.superficie-entrada`, como `conecte/login`), card estreito centralizado, coluna única.
**Conteúdo:** título "Defina sua nova senha"; uma frase "Por segurança, troque a senha que você recebeu antes de continuar."; regra visível antes do erro ("Mínimo de 8 caracteres"); campos `senha_atual`, `senha`, `senha_confirmacao`.
**Ações disponíveis:** Salvar; Sair.
**Navegação:** com troca pendente, toda rota autenticada de `Mine` redireciona aqui; salvar → `mine/painel` com toast success "Senha definida."; sessão segue válida (a sessão atual recebe a nova `sessao_versao`).
**Estados:**

- Loading: Salvar em loading, campos desabilitados.
- Vazio: n/a.
- Erro: senha atual incorreta / nova fraca / confirmação divergente → erro inline (422 da validação do servidor, re-render com `erros`); bloqueio do `confirmarSenha` → alert danger com `Limite_login::MENSAGEM`; falha técnica → alert danger "Não foi possível salvar. Tente novamente." Senhas sempre voltam limpas; `autocomplete="current-password"` / `"new-password"`.

### Qualquer tela (painel e área do cliente) — Sessão expirada · **modificada (comportamento global)**

**Compõe:** `toast` (warning).
**Conteúdo:** "Sua sessão expirou. Entre novamente." por 1,5 s antes do redirecionamento; a mesma mensagem reaparece no login pelo flashdata (RF-49).
**Navegação:** login do painel ou da área do cliente (sempre o início após entrar).
**Estados:** rede/timeout → toast danger "Sem conexão ou a resposta demorou demais. Tente novamente."; JSON inválido → "Resposta inesperada do servidor." (nunca "sem conexão").

### /login e /mine — Respostas do login por status · **modificada**

**Compõe:** `alert`, `input`, `button` primary (inalterados).
**Estados:** 401 → mensagem genérica única no alert; 403 → motivo no alert; 429 → alert "Muitas tentativas de acesso. Tente de novo em N min." (de `retry_after`); 422 → erro inline por campo; rede → alert com `MENSAGEM_REDE`. Foco no primeiro campo com erro; o botão sai do loading em todo desfecho. Um só local por erro (alert **ou** inline, nunca com toast).

### Menu do usuário e telas de entrada — "Sair" · **modificada**

**Compõe:** `button` (mesma variante/ícone/posição de hoje) dentro de `<form method="post" action="…/sair" <?= js_module('layout/sair') ?>>` com o campo CSRF. Pontos: `views/tema/conteudo.php` (menu), `views/tema/menu.php` (sidebar), `views/conecte/template.php`, `views/conecte/conta.php`, `views/mapos/login_dois_fatores.php` ("Voltar ao login"), `views/mapos/banco-desatualizado.php`.
**Navegação:** login com toast "Você saiu."; GET na URL antiga → 405, sessão intacta.
**Estados:** token do campo vencido → `layout/sair` copia o valor atual do cookie CSRF para o campo no `submit`.

### Mensagens de erro — Exclusões, cobranças, limite de OS · **modificadas**

- Exclusão com rollback: modal fecha, registro permanece, toast danger "Não foi possível excluir. Nada foi alterado.".
- Cobrança: 422 → mensagem amigável do `GatewayRecusouException` no alert/toast; 502 → "O provedor de pagamento não respondeu. Tente novamente em instantes."; nunca texto cru nem stack. Pagamento baixado por webhook aparece só pelo `pill-status` existente.
- `/mine/adicionarOs` acima do limite: alert danger "Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência." no topo do formulário, valores preservados.

### Responsivo, Performance e A11y

**Responsivo:** breakpoints do DESIGN (sm 640 / md 768 / lg 1024); telas novas em coluna única; campo + Copiar empilham abaixo de 640px; alvos ≥ 44px (Copiar, Salvar, Sair).
**Performance:** um módulo JS por tela (`clientes/senha-inicial`, `layout/sair`), sem dependência nova; timeout padrão de 30 s no cliente HTTP; alert de senha/erro de login renderizado no servidor no topo do conteúdo (sem deslocar campos após o primeiro paint); toast em camada fixa.
**A11y:** contraste AA com os tokens já validados; foco visível pelo `outline-ring`; ao abrir `definirSenha` o foco vai a `senha_atual`; após erro, foco no primeiro campo inválido; `role="alert"` em erro, `role="status"` em aviso; `aria-live="polite"` no "Copiado"; rótulo acessível não visível só no mostrar/ocultar senha.

### Impacto em Telas Existentes

| Tela / Rota | Mudança | Observação |
| --- | --- | --- |
| `/clientes/adicionar` | modificada | sucesso com senha gerada renderiza `clientes/senha_inicial` |
| `/mine/definirSenha` | **criada** | bloqueia a área do cliente até a troca |
| `/login`, `/mine` (login) | modificada | respostas por status |
| Menu/sidebar do painel, topo e conta da área do cliente, 2FA, banco desatualizado | modificada | "Sair" vira form POST |
| `/mine/adicionarOs` | modificada | mensagem de limite diário |
| Exclusões (OS, vendas, clientes, produtos, serviços, cobranças), `/cobrancas/*` | modificada | mensagens de erro, sem sucesso falso |
| Todas (AJAX) | modificada | sessão expirada, timeout, JSON inválido |

### Fluxos de Usuário

**Cadastro de cliente com senha gerada:** operador salva cliente sem senha → tela "Cliente cadastrado" com a senha e o texto do e-mail → Copiar senha → Voltar à lista.
Desvio: e-mail falhou → texto "Não foi possível enviar por e-mail: repasse a senha ao cliente."; validação falhou → formulário com valores e erros, sem senha gerada.

**Primeiro acesso do cliente:** login com a senha recebida → `/mine/definirSenha` → atual + nova + confirmação → Salvar → painel do cliente.
Desvio: atual errada/nova fraca → erro no campo; abrir outra rota → volta aqui; API → 403 `troca_de_senha_obrigatoria` até `PUT /api/v1/client/password`.

**Sessão expirada em AJAX:** ação → 401 JSON → toast → login (com a mensagem) → início.
Desvio: resposta seguiu redirect para HTML (`response.redirected`) → tratada como sessão expirada.

**Sair:** menu → Sair (POST) → login com "Você saiu.".
Desvio: campo CSRF velho → renovado do cookie no submit.

**Limite de OS:** 11ª abertura do dia → mensagem de limite (tela) / 429 + `Retry-After` (API), formulário preservado.

**Pagamento por webhook:** cliente paga → gateway notifica → cobrança vira paga e lançamento baixado → operador vê `pill-status` "Paga".
Desvio: gateway fora na consulta → 503 → gateway reentrega; consulta manual `Cobrancas::atualizar` continua disponível.

## 6. Boundaries

**In scope:**

- Tudo de RF-01 a RF-61.
- Breaking changes (CHANGELOG + `docs/api-autenticacao.md` + `docs/migracao-4x-para-5.md`): falta de permissão na API 401→403 e token inválido 403→401; login do painel/área do cliente com status HTTP e campo CSRF de nome configurável; logout só por POST; API do cliente com access token de 1 h + refresh obrigatório; `perPage` com teto 100; `POST /client/os` com limite diário.

**Out of scope:**

- Migração do CodeIgniter 3; zerar a baseline do PHPStan (só não pode subir); medição de cobertura.
- Mudança do bloqueio por e-mail do `Limite_login`.
- Assinatura de autoria do `Github_updater` e janela do instalador (só documentar).
- Senhas do `docker/.env` de desenvolvimento.
- Estorno automático de lançamento em `refunded`/`contested` vindo do webhook (só atualiza o status da cobrança e loga).
- Voltar à tela de origem após login por sessão expirada (vai sempre ao início).
- Webhook para cobranças por link de pagamento além do que a consulta de cada SDK já cobre.
- Forçar troca em massa de senha de clientes legados (há o comando opcional RF-26).

## 7. Constraints

- Sem dependência nova (composer/npm); sem edição de `banco.sql`; schema só por migration reversível com conferência pós-DDL.
- Guards do CI não sobem: `php scripts/check-escape.php` (baseline `escape-baseline.json`), `php scripts/check-inline-script.php` (baseline `inline-script-baseline.json`), `composer analyse` (baseline do PHPStan), `composer format` em dry-run; depois de mudar view/CSS, `npm ci && npm run build` com `assets/dist` commitado.
- Todo valor impresso em view por `e()`; sem `<script>` inline (módulos por `js_module()`); sem cor/fonte fixa fora dos tokens; sem jQuery.
- Toda mudança de segurança com teste do caso negado em `tests/Unit/<Assunto>Test.php` (classe `final` estendendo `MaposTestCase`, SQLite em memória, executando o comportamento) e JS em `tests/js/*.mjs`.
- Webhook: autenticação antes de consulta/escrita; nenhuma URL/host do payload é usada; transação não fica aberta durante a chamada HTTP ao gateway.
- Rate limits e limites por env var (`WEBHOOK_RATE_LIMIT`, `CLIENTE_OS_LIMITE_DIARIO`, `API_RATE_LIMIT`), nunca hardcoded.
- **Quirks registrados:** PHP converte `data.id` da query em `$_GET['data_id']`; `ts` do `x-signature` do MP pode vir em ms; `GET_LOCK` é liberado no fim da conexão (processo morto não prende a fila) e não existe no SQLite dos testes — isolar em método substituível; `trans_status()` não captura exceção de `db_debug` ligado em development — conferir os dois; Asaas envia **todos** os pagamentos da conta ao webhook (por isso cobrança desconhecida = 200, ou a Asaas pausa a fila após falhas seguidas); timeouts de MP/Asaas: aplicar pelo mecanismo do SDK instalado; se a versão do `composer.lock` não expuser timeout, injetar um HTTP client com `CURLOPT_CONNECTTIMEOUT`/`CURLOPT_TIMEOUT` — o critério de aceite é o timeout efetivo, não o mecanismo.
- **Smoke (RF-60)**, com `API_ENABLED=true`:

| O quê | Alvo | Veredito |
| --- | --- | --- |
| processo | `GET /` | 200 ou 302 |
| API | `GET /index.php/api/v1` | 401 |
| webhook MP | `POST /index.php/webhooks/mercadopago` sem assinatura | 401 |
| webhook Asaas | `POST /index.php/webhooks/asaas` sem token | 401 |
| webhook Efí | `POST /index.php/webhooks/efi/invalido` | 401 |
| login | `POST /index.php/login/verificarLogin` sem corpo | 403 (CSRF) ou 422; nunca 404/5xx |
| logout | `GET /index.php/login/sair`, `GET /index.php/mine/sair` | 405 |
| API cliente | `POST /index.php/api/v1/client/refresh` sem corpo | 401 |
| API cliente | `PUT /index.php/api/v1/client/password`, `POST /index.php/api/v1/client/os` sem token | 401 |
| API operador | `PUT /index.php/api/v1/os/1` sem token | 401 |

## 8. Acceptance Criteria

### RF-01
- [ ] `POST /webhooks/mercadopago` com `API_ENABLED=false` responde 401 (não 404) — teste chamando o controller.
- [ ] POST sem token CSRF a qualquer webhook não é recusado pelo CSRF; `config/config.php` exclui só as três rotas de webhook além das existentes.
- [ ] `GET` em webhook → 405 com `Allow: POST`.

### RF-02
- [ ] Assinatura MP inválida, `ts` fora de 300 s, segredo Efí errado e token Asaas errado → 401, sem chamada ao gateway (consulta mockada não invocada) e sem linha em `webhook_eventos`.
- [ ] `MP_WEBHOOK_SECRET` vazio → 401 mesmo com assinatura calculada com string vazia.
- [ ] `grep -n "hash_equals" application/controllers/Webhooks.php` encontra a comparação dos três gateways.

### RF-03
- [ ] Corpo dizendo `status: approved` com consulta devolvendo `pending` não baixa nada.
- [ ] `grep -nE "notification_url|http" application/controllers/Webhooks.php` não mostra uso de URL vinda do payload.

### RF-04
- [ ] Mesmo evento enviado duas vezes: segunda resposta 200 `repetido`, uma só baixa, consulta chamada uma vez.
- [ ] Evento com `processado_em` nulo (falha anterior) é reprocessado no reenvio.

### RF-05
- [ ] Pagamento confirmado: `cobrancas.status` pago, `lancamentos.baixado = 1`, `data_pagamento` de hoje, `webhook_eventos.processado_em` preenchido, uma linha em `logs`.
- [ ] Dois eventos distintos para a mesma cobrança paga não alteram `data_pagamento` nem regridem o status (evento `pending` depois de `paid` mantém pago).
- [ ] Falha simulada no UPDATE do lançamento → rollback de cobrança e evento, resposta 503.

### RF-06
- [ ] Valor consultado ≠ `cobrancas.total` → sem baixa, `resultado = 'divergente'`, log de erro, 200.

### RF-07
- [ ] Corpo de 70 KB → 413; consulta lançando exceção → 503 e evento sem `processado_em`; cobrança desconhecida → 200 `ignorado`; acima de `WEBHOOK_RATE_LIMIT` → 429 com `Retry-After`.

### RF-08
- [ ] `grep -rnE "mapos\.com\.br|seusite\.com\.br" application/libraries/Gateways` não encontra nada.
- [ ] Com `EFI_WEBHOOK_SECRET` vazio a cobrança Efí é montada sem `notification_url`; preenchido, a URL é `site_url('webhooks/efi/<segredo>')` (teste do array enviado ao SDK).

### RF-09
- [ ] `PUT /api/v1/os/{id}` em OS `Faturado`, `Cancelado` ou `faturado = 1` → 403 e OS intacta.
- [ ] PUT com `faturado: 1` ou `status: "Faturado"` → 422 / `faturado` inalterado no banco.

### RF-10
- [ ] PUT/POST com campos extras (`faturado`, `valorTotal`, `lancamento`, `senha_trocar`, `token_versao`) não altera esses campos.
- [ ] `PUT /api/v1/usuarios/{id}` com `permissoes_id` de grupo fora de `podeDelegarGrupo()` → 403.

### RF-11
- [ ] Usuário sem `eOs` → 403; sem header `Authorization` → 401; token de cliente em rota de operador → 401.
- [ ] `grep -rn "checkPermission" application/controllers/api/v1 | grep -c HTTP_UNAUTHORIZED` = 0.

### RF-12
- [ ] Grupo do usuário com `situacao = 0`: `GET /api/v1/os` → 401 e `POST /api/v1/refresh` → 401 com refresh revogado.

### RF-13
- [ ] `perPage=1000000` → 100 itens no máximo; `perPage=abc` → 422; `page=2&perPage=10` → offset 20.

### RF-14
- [ ] `from=31/02/2026`, `from=2026-01-01` e janela de 400 dias → 422; `dataInicial` inválida no POST/PUT de OS → 422 sem warning no log.

### RF-15
- [ ] `grep -rn "lastRow(" application/controllers` não encontra nada; `produtos_post` devolve o id do item inserido.

### RF-16
- [ ] Cliente criado sem senha no painel, no cadastro rápido e na API: `password_verify(<documento>, senha)` é falso, `senha_trocar = 1`.
- [ ] `clienteSenhaAleatoria()` devolve 16 caracteres do alfabeto definido e dois valores seguidos diferentes.

### RF-17
- [ ] `POST /api/v1/clientes` sem senha → 201 com `senha_inicial` (16 caracteres) e `email_enviado`; `GET /api/v1/clientes/{id}` não tem `senha_inicial`; resposta com `Cache-Control: no-store`.
- [ ] Nenhuma linha nova em `email_queue` com a senha; a senha não aparece em `application/logs/*`, na tabela `logs` nem em `ci_sessions` (teste procurando a string).
- [ ] Falha de SMTP simulada → `email_enviado: false` e a tela mostra o texto de falha.

### RF-18
- [ ] Cliente com `senha_trocar = 1` em `/mine/painel`, `/mine/os`, `/mine/conta` → redirect para `/mine/definirSenha`; `/mine/sair` (POST) funciona.
- [ ] Salvar senha válida → `senha_trocar = 0`, redirect `mine/painel`, sessão continua válida.

### RF-19
- [ ] `GET /api/v1/client/os` com `senha_trocar = 1` → 403 `code = "troca_de_senha_obrigatoria"`; `PUT /api/v1/client/password` não é bloqueado.

### RF-20
- [ ] Senha atual errada → 422 `erros.senha_atual`; nova < 8 ou igual à atual → 422 `erros.senha_nova`; sucesso → tokens novos funcionam e o access token antigo → 401.

### RF-21
- [ ] Token do cliente com `exp - iat <= 3600` mesmo com `API_TOKEN_EXPIRE_TIME=2592000`; refresh do cliente gravado com `tipo = 'cliente'`.

### RF-22
- [ ] Refresh reutilizado após a tolerância → 401 e família revogada (access token com aquele `sid` → 401); refresh de cliente em `/api/v1/refresh` → 401 e vice-versa; logout → 204 e refresh seguinte → 401.

### RF-23
- [ ] Troca de senha pelo operador no painel → próxima requisição da sessão web do cliente encerrada e refresh do cliente → 401; exclusão do cliente revoga os refresh tokens dele.

### RF-24
- [ ] Cliente excluído ou com `sessao_versao` incrementada → próxima tela de `Mine` vai ao login com aviso.

### RF-25
- [ ] 10 aberturas no dia passam; a 11ª → 429 com `Retry-After` = segundos até 00:00 (API) / mensagem na tela, sem OS criada; OS criada pelo operador para o cliente não conta.
- [ ] `CLIENTE_OS_LIMITE_DIARIO=0` desliga o limite; `POST /api/v1/client/os` grava `os_historico`.

### RF-26
- [ ] `php index.php tools marcarSenhasLegadas` marca só o cliente cuja senha é o documento e imprime "1 cliente(s) marcado(s)"; nada de senha/documento na saída.

### RF-27
- [ ] `GET /login/sair` e `GET /mine/sair` → 405 e sessão continua; POST com CSRF encerra.
- [ ] `grep -rnE "href=.*(login|mine)/sair" application/views` não encontra nada.

### RF-28
- [ ] 5 senhas erradas na ativação do 2FA → bloqueio (`SENHA_BLOQUEADA`) e o 6º pedido nem confere o código.

### RF-29
- [ ] Login do painel e da área do cliente: e-mail inválido → 422 com `erros.email`; senha errada → 401; bloqueado → 429 com `Retry-After` e `retry_after`; resposta com `Content-Type: application/json`.
- [ ] Com `APP_CSRF_TOKEN_NAME=OUTRO`, a resposta traz o campo `OUTRO` e não `MAPOS_TOKEN`.

### RF-30
- [ ] Duas chamadas a `senhaSalvar` com o mesmo token: só a primeira troca a senha (segunda → "Token inválido").

### RF-31
- [ ] `Usuarios` seed com `ENVIRONMENT = 'production'` não insere `admin@admin.com`.

### RF-32
- [ ] Controller de API que lança `\TypeError` → 500 JSON `{status:false, message:"Erro interno"}`, sem arquivo/linha no corpo, com linha `api excecao` no log.

### RF-33
- [ ] Falha simulada no meio da exclusão de OS (painel e API) → OS, itens e lançamento continuam no banco e a mensagem é de erro; o mesmo para venda, cliente, produto, serviço e cobrança.

### RF-34
- [ ] Erro de banco em `Clientes_model` (linhas citadas) com `db_debug` desligado → retorno `false` e linha no log.

### RF-35
- [ ] Faturamento com falha na criação do lançamento → OS/venda não vira faturada e a tela mostra erro; `registrarStatus`/`desativarTotp` com falha geram log.

### RF-36
- [ ] `.htaccess` gerado contém `<IfModule mod_authz_core.c>` e `Require all denied`; `.htaccess` antigo sem `mod_authz_core` é regravado; diretório sem permissão de escrita → linha de erro no log.
- [ ] `grep -nE "@(file_put_contents|mkdir|unlink|chmod)" application/helpers/armazenamento_helper.php application/helpers/general_helper.php` não encontra nada.

### RF-37
- [ ] Executar os testes dos fluxos RF-09, RF-14, RF-16 e RF-29 não gera `Deprecated` de `null` em função de string (handler de erro do teste falha em `E_DEPRECATED`).

### RF-38
- [ ] `application/config/database.php` tem `'save_queries' => ENVIRONMENT !== 'production'`.

### RF-39
- [ ] `MPApiException` com conteúdo array na criação de boleto → `GatewayRecusouException` com mensagem string (sem `TypeError`); descrição de venda = `"Venda #<id>"`.

### RF-40
- [ ] Falha simulada de cada gateway gera uma linha `gateway op=…` sem CPF, token ou corpo (teste lendo o log).

### RF-41
- [ ] `payment_gateways.php` expõe `timeout` em MercadoPago e Asaas lido do `.env` (padrão 10) e o cliente HTTP do SDK recebe esse valor (teste do cliente configurado).

### RF-42
- [ ] `Cobrancas::adicionar` com `GatewayRecusouException` → 422 `{result:false, message:<amigável>}`; com `GatewayIndisponivelException` → 502; nenhuma resposta contém o texto cru do gateway.

### RF-43
- [ ] Segunda execução concorrente de `email/process` (lock simulado ocupado) sai com código 0, loga "ignorado" e não envia nada.

### RF-44
- [ ] Duas execuções seguidas não reservam o mesmo e-mail; só e-mails com `tentativas < 5` são reservados.

### RF-45
- [ ] E-mail que falha 5 vezes fica `failed` e `email/retry` não o reabre; exceção num e-mail não impede os demais do lote; reserva com mais de 15 min volta a `pending`.

### RF-46
- [ ] Falha de envio não escreve o corpo nem o link de reset no log (teste com corpo contendo marcador).

### RF-47
- [ ] Ciclo normal → `exit(0)` e linha `email_fila ciclo=process …`; erro de banco no ciclo → `exit(1)` (código de saída isolado em método testável).

### RF-48
- [ ] `Retencao::limpar()` apaga `webhook_eventos` de 91 dias e mantém os de 89; apaga no máximo 500 por tabela por chamada.

### RF-49
- [ ] Requisição AJAX sem sessão a uma rota do painel → 401 JSON com `redirect` igual a `site_url('login')`; para `Mine` → `site_url('mine')`; sem AJAX → redirect como hoje.

### RF-50
- [ ] Teste node: `requisicao()` com resposta 401 `{redirect}` chama o toast warning e `location.assign(redirect)` e a promessa não resolve; resposta `redirected` com HTML idem.

### RF-51
- [ ] Teste node: fetch que não responde em `timeout` → `ErroHttp` status 0 com a mensagem de conexão; corpo JSON inválido → "Resposta inesperada do servidor."; `app.js` registra `unhandledrejection`.

### RF-52
- [ ] `grep -n "catch {" assets/js/modules/layout/shell.js` não encontra catch vazio; cada catch chama `mostrarToast`.

### RF-53
- [ ] Teste node de `login.js`: 422 marca o campo, 429 mostra o tempo, 403 sem JSON mostra `MENSAGEM_SESSAO`; o token é lido pela meta `csrf-token-name`.

### RF-54
- [ ] Views novas (`clientes/senha_inicial.php`, `conecte/definir_senha.php`) usam só `component()` do inventário e classes de token; `php scripts/check-escape.php` e `php scripts/check-inline-script.php` passam sem subir baseline.
- [ ] Família tipográfica herdada da base (nenhuma view nova declara `font-family`/classe de fonte fora dos tokens) e nenhum `h1`/`a`/`p` nativo sem classe ou regra base nas views novas.
- [ ] A tela da senha usa o wrapper com `max-w-4xl` e a de definir senha o card estreito centralizado da área do cliente — conteúdo não ocupa o viewport inteiro.
- [ ] O botão mostrar/ocultar senha tem rótulo acessível em `sr-only`/`aria-label`, não renderizado visualmente; "Copiar senha" tem rótulo visível.
- [ ] A senha aparece uma só vez na tela (só no campo readonly); o toast diz apenas "Copiado".
- [ ] `AcessibilidadeTest` cobre `aria-describedby` dos campos de `definir_senha.php`.

### RF-55
- [ ] QR da compra do cliente usa `Vendas_model::getQrCode()` (teste com venda sem OS de mesmo id).

### RF-56
- [ ] `GET /api/v1/calendario` devolve `result.events` com os eventos montados.

### RF-57
- [ ] `grep -rn "segment(3)" application/controllers/api/v1/client` não encontra nada; `?page=1&perPage=5` devolve o 2º bloco.

### RF-58
- [ ] As oito variáveis de RF-58 estão no `.env.example` sem valor real nos segredos.

### RF-59
- [ ] `docker/etc/nginx/default.template.conf` tem `access_log … if=$loggable` com `map` excluindo `/webhooks/efi/`; `cron-jobs` mantém `email/process` e `email/retry`.

### RF-60
- [ ] `scripts/smoke-seguranca.sh` executa a tabela de smoke de §7, derruba o ambiente e retorna ≠ 0 quando um veredito falha.

### RF-61
- [ ] Os cinco documentos de RF-61 existem/atualizados e o CHANGELOG lista as breaking changes de §6.

## 9. Quality Gates

- Cobertura: não medida no projeto (STACK) — o gate é teste do caso negado por RF de segurança.
- `composer test`, `composer analyse` (baseline não sobe), `composer format` dry-run, `composer audit`, `npm run test:js`, `php scripts/check-escape.php`, `php scripts/check-inline-script.php`, `npm run build` sem diff em `assets/dist`.

## 10. Decisões e Trade-offs

| Decisão | Alternativa descartada | Justificativa |
| --- | --- | --- |
| Cobrança desconhecida no webhook → 200 `ignorado` (registrado) | 404 (discovery e `eb9b3ea292`) | O webhook do Asaas é da conta inteira e envia pagamentos que o Map-OS não criou; 404 faz o gateway reentregar por dias e o Asaas pausa a fila após falhas seguidas, travando os pagamentos legítimos. A mitigação de IDOR do consultor (cobrança localizada pelo id da consulta + valor conferido) é mantida |
| Segredo ausente no `.env` → 401 | 503 (opção do `eb9b3ea292`) | Uniforme com autenticação inválida, fail-closed igual, e o smoke do `27ccda5a4d` exige 401 sem segredo; o motivo vai só para o log |
| Senha inicial por e-mail **síncrono** (`send(true)`), fora da `email_queue` | Link de definição de senha (preferência do `eb9b3ea292`/`72eb861755`) · senha na fila com corpo apagado após envio | O discovery fixou senha exibida + e-mail; o envio síncrono garante que a senha em claro nunca é persistida (requisito do `72eb861755`) e dá o `email_enviado` real que o `17430d0227` pede. A troca obrigatória no primeiro acesso faz a senha "expirar no primeiro uso", como o consultor aceitou. Custo: o POST espera o SMTP (limitado por `smtp_timeout`) |
| Tela da senha renderizada na resposta do POST (sem redirect/flashdata) | PRG com flashdata (padrão do projeto) | Flashdata grava em `ci_sessions` no banco; o `eb9b3ea292` proíbe a senha em flashdata persistido. Reenvio por recarga é barrado pelo CSRF regenerado |
| `api_refresh_tokens` com coluna `tipo` | Tabela própria de refresh do cliente | Mesmo fluxo de rotação/reuso; duplicar `Refresh_token` duplicaria a superfície de segurança (`72eb861755`) |
| Não atualizar `banco.sql` | "atualizar o schema de instalação (`banco.sql`)" (`72eb861755`, Para tasks) | PATTERNS: schema só por migration, nunca editando `banco.sql`; o instalador já roda as migrations depois do `banco.sql` 4.x |
| Limite de OS contado em `os_historico` (abertura pelo cliente) com `SELECT … FOR UPDATE` na linha do cliente | Contar `os.dataInicial` do dia · tabela de rate limit | `dataInicial` também vale para OS do operador e é editável; o histórico distingue a abertura pelo cliente; o lock na linha do cliente serializa a corrida sem tabela nova |
| Retenção executada pelo `email/retry` | Comando/cron novo | Não obriga o administrador a cadastrar outro cron; lotes de 500 mantêm o ciclo curto |
| Lock do cron por `GET_LOCK` | Arquivo de lock / linha de controle | Liberado automaticamente na morte do processo (sem lock preso); MySQL e MariaDB suportam |
| Reutilizar `API_TOKEN_EXPIRE_TIME` com teto 3600 para o cliente | `API_CLIENT_TOKEN_EXPIRE` (discovery) | Mesmo teto do operador já imposto em código por `TETO_COM_REFRESH`; variável nova não muda o comportamento |
| Campo `password` mantido em `POST /client/auth` | `senha` (contrato do discovery) | Não quebrar os apps instalados; o endpoint novo `PUT /client/password` usa `senha_atual`/`senha_nova` |
| Anexos da OS pela API continuam permitidos em OS fechada | Bloquear anexos (`eb9b3ea292`: auditar todas as rotas filhas) | Paridade com o painel (`Os::anexar` não bloqueia: foto de entrega é legítima); produtos, serviços, desconto e anotações já bloqueiam e ganham teste |
| Identificadores e schema em português | Inglês (regra geral de nomes) | Convenção do projeto (PATTERNS: tabelas/colunas em português, identificador no padrão do arquivo vizinho); misturar criaria duas convenções no mesmo banco |
| Circuit breaker não implementado | Breaker por gateway | `27ccda5a4d`: PHP sem estado entre requisições; timeout + falha rápida cobrem |

## 11. Ambiguity Report

| Dimensão            | Score | Mín   | Status | Notas |
| ------------------- | ----- | ----- | ------ | ----- |
| Goal Clarity        | 0.92  | 0.75  | ok     | Lacunas enumeradas com arquivo/linha |
| Boundary Clarity    | 0.88  | 0.70  | ok     | Out of scope explícito; breaking changes listadas |
| Constraint Clarity  | 0.85  | 0.65  | ok     | Quirks de SDK (timeout) com critério pelo efeito |
| Acceptance Criteria | 0.88  | 0.70  | ok     | 1:1 por RF, verificáveis por teste/grep |
| **Ambiguity**       | 0.12  | ≤0.20 | ok     | Resíduo: API exata de timeout dos SDKs (vendor não instalado na análise) |
