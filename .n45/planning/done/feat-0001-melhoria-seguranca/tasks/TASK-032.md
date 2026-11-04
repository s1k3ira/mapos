---
checkpoint: null
complexity: M
created_at: "2026-11-04 06:56:49"
criteria:
    - done: true
      test: grep -q 'webhooks/efi/' docs/webhooks-de-pagamento.md && grep -q 'webhooks/mercadopago' docs/webhooks-de-pagamento.md && grep -q 'webhooks/asaas' docs/webhooks-de-pagamento.md
      text: webhooks-de-pagamento.md traz as três rotas de webhook
    - done: true
      test: test $(grep -oE 'EFI_WEBHOOK_SECRET|MP_WEBHOOK_SECRET|ASAAS_WEBHOOK_TOKEN|WEBHOOK_RATE_LIMIT' docs/webhooks-de-pagamento.md | sort -u | wc -l) -eq 4
      text: webhooks-de-pagamento.md cita as quatro variáveis dos webhooks
    - done: true
      test: grep -qx '## Cadastrar a URL em cada gateway' docs/webhooks-de-pagamento.md && grep -qx '## HTTPS obrigatório' docs/webhooks-de-pagamento.md && grep -qx '## Respostas' docs/webhooks-de-pagamento.md && grep -qx '## Trocar um segredo' docs/webhooks-de-pagamento.md && grep -qx '## Esconder o caminho da Efí nos logs' docs/webhooks-de-pagamento.md && grep -qx '## Reprocessar um evento' docs/webhooks-de-pagamento.md && grep -qx '## Destravar a fila de e-mail' docs/webhooks-de-pagamento.md && grep -qx '## Conferir' docs/webhooks-de-pagamento.md
      text: webhooks-de-pagamento.md tem as seções de cadastro, HTTPS, respostas, troca de segredo, logs, reprocessar, fila e conferir
    - done: true
      test: grep -qF 'if=$loggable' docs/webhooks-de-pagamento.md && grep -qE 'SetEnvIf.*webhooks/efi/' docs/webhooks-de-pagamento.md && grep -qF 'env=!' docs/webhooks-de-pagamento.md
      text: Receitas de nginx (if=$loggable) e Apache (SetEnvIf + env=!) para tirar webhooks/efi/ do access log
    - done: true
      test: grep -qF 'recusado' docs/webhooks-de-pagamento.md && grep -qF '413' docs/webhooks-de-pagamento.md && grep -qF 'tente_novamente' docs/webhooks-de-pagamento.md
      text: Respostas documentadas incluem 401 recusado, 413 e 503 tente_novamente
    - done: true
      test: grep -qE 'DELETE FROM webhook_eventos WHERE gateway' docs/webhooks-de-pagamento.md && grep -qE "UPDATE email_queue SET status = 'pending', tentativas = 0" docs/webhooks-de-pagamento.md
      text: Reprocessar evento por SQL em webhook_eventos e destravar e-mail definitivo zerando tentativas
    - done: true
      test: grep -qF 'scripts/smoke-seguranca.sh' docs/webhooks-de-pagamento.md && grep -qF 'SMOKE_SEM_DOCKER' docs/webhooks-de-pagamento.md
      text: webhooks-de-pagamento.md aponta o smoke de segurança
    - done: true
      test: grep -qF 'client/refresh' docs/api-autenticacao.md && grep -qF 'client/logout' docs/api-autenticacao.md && grep -qF 'client/password' docs/api-autenticacao.md && grep -qF 'troca_de_senha_obrigatoria' docs/api-autenticacao.md
      text: api-autenticacao.md documenta refresh, logout e troca de senha do cliente e o 403 troca_de_senha_obrigatoria
    - done: true
      test: grep -qx '## 401 ou 403' docs/api-autenticacao.md && grep -qF 'senha_inicial' docs/api-autenticacao.md && grep -qF 'email_enviado' docs/api-autenticacao.md && grep -qF 'perPage' docs/api-autenticacao.md && grep -qF 'CLIENTE_OS_LIMITE_DIARIO' docs/api-autenticacao.md
      text: api-autenticacao.md documenta 401 x 403, senha_inicial, perPage e o limite de OS
    - done: true
      test: '! grep -nE ''dura o configurado|passa a dar .403|recebe .403. na hora'' docs/api-autenticacao.md'
      text: Afirmações antigas removidas de api-autenticacao.md (cliente 'dura o configurado' e token revogado/desativado dando 403)
    - done: true
      test: grep -qF 'mod_authz_core' docs/servidor-web.md && grep -qF 'Require all denied' docs/servidor-web.md && grep -qF 'default.template.conf' docs/servidor-web.md && grep -qF 'webhooks-de-pagamento.md' docs/servidor-web.md
      text: servidor-web.md documenta o .htaccess 2.4 e o template do nginx do Docker
    - done: true
      test: test $(grep -oE 'EFI_WEBHOOK_SECRET|MP_WEBHOOK_SECRET|ASAAS_WEBHOOK_TOKEN|WEBHOOK_RATE_LIMIT|CLIENTE_OS_LIMITE_DIARIO|PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT|PAYMENT_GATEWAYS_ASAAS_TIMEOUT|PAYMENT_GATEWAYS_EFI_TIMEOUT' docs/migracao-4x-para-5.md | sort -u | wc -l) -eq 8
      text: migracao-4x-para-5.md lista as oito variáveis novas
    - done: true
      test: test $(grep -oE '20261103000[0-4]00_[a-z_]+' docs/migracao-4x-para-5.md | sort -u | wc -l) -eq 5
      text: migracao-4x-para-5.md lista as cinco migrations pelo nome
    - done: true
      test: grep -qF 'marcarSenhasLegadas' docs/migracao-4x-para-5.md && grep -qE 'login/sa[i]r' docs/migracao-4x-para-5.md && grep -qF 'verificarLogin' docs/migracao-4x-para-5.md && grep -qF 'scripts/smoke-seguranca.sh' docs/migracao-4x-para-5.md
      text: migracao-4x-para-5.md cobre marcarSenhasLegadas, logout por POST, login por status e o smoke
    - done: true
      test: awk '/^## \[Unreleased\]/{f=1;next} /^## \[/{f=0} f && /^- \*\*Breaking:\*\*/{n++} END{exit n!=7}' CHANGELOG.md
      text: 'CHANGELOG: exatamente 7 linhas ''- **Breaking:**'' dentro de [Unreleased]'
    - done: true
      test: awk '/^## \[Unreleased\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md | grep -qF 'perPage' && awk '/^## \[Unreleased\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md | grep -qF 'CLIENTE_OS_LIMITE_DIARIO' && awk '/^## \[Unreleased\]/{f=1;next} /^## \[/{f=0} f' CHANGELOG.md | grep -qx '### Security'
      text: CHANGELOG [Unreleased] cita perPage, CLIENTE_OS_LIMITE_DIARIO e a seção Security
    - done: true
      test: grep -qx '## \[5.2.2\] - 2026-10-29' CHANGELOG.md && test $(grep -c '^## \[' CHANGELOG.md) -eq 139
      text: Seções de versões anteriores do CHANGELOG preservadas (mesmo número de títulos de versão)
deps:
    - TASK-006
    - TASK-011
    - TASK-012
    - TASK-019
    - TASK-022
    - TASK-023
    - TASK-025
    - TASK-026
    - TASK-027
    - TASK-028
    - TASK-031
diff_base: 44fd14da280fb98e7821bedf97d91385f3cfb236
diff_tip: 89a3896c740b8e40fc998472d063b03040360e5f
files:
    - docs/webhooks-de-pagamento.md
    - docs/api-autenticacao.md
    - docs/servidor-web.md
    - docs/migracao-4x-para-5.md
    - CHANGELOG.md
id: TASK-032
n45_version: 1.1.0
outcome: |
    Documentação entregue (sem código de produção):
    - `docs/webhooks-de-pagamento.md`: rotas dos três webhooks, variáveis, respostas (200/401/405/413/429/503), HTTPS, troca de segredo, receitas nginx/Apache para não logar `webhooks/efi/`, reprocessar evento e destravar fila de e-mail por SQL, smoke `scripts/smoke-seguranca.sh`.
    - `docs/api-autenticacao.md`: refresh/logout/password do cliente, 403 `troca_de_senha_obrigatoria`, 401 x 403, `senha_inicial`/`email_enviado`, `perPage`, limite diário de OS; afirmações antigas removidas.
    - `docs/servidor-web.md`: `.htaccess` Apache 2.4 e template nginx do Docker.
    - `docs/migracao-4x-para-5.md`: oito variáveis, cinco migrations, `marcarSenhasLegadas`, logout por POST, login por status, smoke.
    - `CHANGELOG.md`: [Unreleased] com 7 linhas Breaking e seção Security.
persona: backend
phase: 'Phase 5 — Entrega: smoke de segurança, servidor e documentação'
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: false
    red: false
    refactor: false
title: Documentação de webhooks, API, servidor web, migração e CHANGELOG
updated_at: "2026-11-04 07:16:47"
---
## Contexto

Fecha a feature de segurança com a documentação para quem administra a instalação e para quem integra com a API. Todo o comportamento já está entregue (Phases 1 a 4) e o ambiente Docker/smoke pela TASK-031; esta task **só escreve documentação** em `docs/` e no `CHANGELOG.md` — nenhum código de produção, teste ou `.env.example` muda (as oito variáveis já estão no `application/.env.example`). Antes de afirmar algo, confira no código citado em cada item: a documentação descreve o que o código faz, nunca o que a especificação pretendia.

Os textos são em pt-BR, no tom dos documentos vizinhos (frases curtas, segunda pessoa, tabelas para contratos, blocos de código para comandos). Identificadores, rotas, campos JSON e variáveis ficam como no código.

Hoje há três afirmações que ficaram **erradas** em `docs/api-autenticacao.md` e precisam sair:

- "o token da área do cliente (sem refresh token) dura o configurado" (seção "Access token") e "o do cliente dura o configurado" (tabela de variáveis) — agora o access token do cliente dura no máximo 1 h e vem com refresh token;
- "o access token já emitido passa a dar `403` na hora" — agora é `401`;
- "quem foi desativado ou removido recebe `403` na hora" — agora `401` (token de conta/grupo desativado é 401; 403 é só falta de permissão).

## Comportamento Esperado

Fatos a documentar (fonte: código entregue — confira os arquivos citados):

| Tema | Fato | Onde conferir |
| --- | --- | --- |
| URLs dos webhooks | `POST /webhooks/efi/<EFI_WEBHOOK_SECRET>` (form `notification=<token>`), `POST /webhooks/mercadopago` (headers `x-signature`, `x-request-id`; query `data.id`, `type=payment`), `POST /webhooks/asaas` (header `asaas-access-token`). Funcionam com `API_ENABLED` ligado ou desligado, sem sessão e fora do CSRF | `application/config/routes.php`, `application/controllers/Webhooks.php` |
| Respostas do webhook | JSON com `Cache-Control: no-store`: 200 `{"status":"aplicado"\|"repetido"\|"ignorado"\|"divergente"\|"sem_efeito"}` · 401 `{"status":"recusado"}` (segredo/token/assinatura errados **ou segredo vazio no `.env`**) · 405 com `Allow: POST` · 413 (corpo > 64 KB) · 429 com `Retry-After` (`WEBHOOK_RATE_LIMIT`, padrão 120/min por IP) · 503 `{"status":"tente_novamente"}` (consulta ao gateway, banco ou rollback falhou; o gateway reentrega) | `Webhooks.php` |
| O que o webhook faz | Autentica com `hash_equals` antes de tudo; nunca confia no corpo: reconsulta o pagamento no gateway; idempotência por `webhook_eventos (gateway, evento_id)`; valor diferente de `cobrancas.total` não baixa (`divergente`, log de erro); status pago nunca regride; baixa o lançamento da OS/venda em transação; cobrança desconhecida → 200 `ignorado` (o Asaas manda todos os pagamentos da conta) | `Webhooks.php`, `application/models/Webhooks_model.php` |
| `notification_url` | Efí: `site_url('webhooks/efi/' . EFI_WEBHOOK_SECRET)` enviada em cada cobrança; Mercado Pago: `site_url('webhooks/mercadopago')` enviada em cada cobrança só com `MP_WEBHOOK_SECRET` preenchido; sem o segredo a cobrança é criada sem `notification_url`. Asaas: a URL é cadastrada no painel do Asaas, com o token de autenticação = `ASAAS_WEBHOOK_TOKEN` | `application/libraries/Gateways/{GerencianetSdk,MercadoPago,Asaas}.php`, `BasePaymentGateway::urlDeNotificacao()` |
| Log | Linhas `webhook gateway=… evento=… cobranca=… resultado=… http=… duracao_ms=…`; o caminho da requisição (que na Efí carrega o segredo) não é registrado; nunca segredo, payload ou dado do pagador | `Webhooks.php` |
| Smoke | `scripts/smoke-seguranca.sh` (TASK-031): sobe com `cd docker && docker-compose up -d`, espera `http://localhost:8000/`, confere 12 rotas, derruba; exit 0 (ok) / 1 (veredito falhou) / 2 (ambiente não subiu ou sem compose); `SMOKE_URL`, `SMOKE_ESPERA_SEGUNDOS`, `SMOKE_SEM_DOCKER=1` (usa o ambiente já no ar); pré-requisito: instalação concluída com `API_ENABLED=true` | `scripts/smoke-seguranca.sh` |
| nginx do Docker | `docker/etc/nginx/default.template.conf` é a fonte; o compose gera o `default.conf` a partir dele ao subir; traz `map $request_uri $loggable` + `access_log … if=$loggable` | `docker/etc/nginx/` |
| `.htaccess` de proteção | Gerado em `STORAGE_PATH` e na pasta pública de upload com `<IfModule mod_authz_core.c> Require all denied </IfModule>` (Apache 2.4) e fallback `<IfModule !mod_authz_core.c>` com `Order allow,deny` / `Deny from all` (2.2); um `.htaccess` antigo sem `mod_authz_core` é regravado; falha de gravação vai para o log (`armazenamento falha operacao=gravar`) | `application/helpers/armazenamento_helper.php` (`armazenamentoGravarProtecao`, `ARMAZENAMENTO_MARCA_AUTHZ`) |
| API do operador | 403 `{status:false,message}` = falta de permissão; 401 = token ausente (`Faça login para acessar a API.`), inválido, expirado, de cliente, de usuário/grupo desativado ou expirado (`Faça o login novamente!`); `POST /api/v1/refresh` recusa grupo desativado e revoga os refresh do usuário; exceção não tratada → 500 `{status:false,message:"Erro interno"}` | `application/libraries/REST_Controller.php` |
| Listagens | `perPage` inteiro 1..100 (padrão 20; acima de 100 vira 100), `page` inteiro ≥ 0, `offset = page * perPage`; não inteiro ou ≤ 0 → 422 `Parâmetros de paginação inválidos.` com `erros`. Vale para `os`, `clientes`, `produtos`, `servicos`, `usuarios`, `audit` e para `client/os`, `client/compras`, `client/cobrancas` | `REST_Controller::paginacaoDaApi()`, `api_helper.php::apiPaginacao()` |
| Datas | `GET /api/v1/os` `from`/`to` em `dd/mm/aaaa`; `GET /api/v1/calendario` `start`/`end` em `aaaa-mm-dd`; formato inválido ou janela > 366 dias → 422; `dataInicial`/`dataFinal` de `POST`/`PUT /api/v1/os` idem | `api_helper.php`, `os_helper.php::osDadosDaApi()` |
| OS pela API | `PUT /api/v1/os/{id}` em OS `Faturado`/`Cancelado`/`faturado = 1` → 403 `OS Faturada/Cancelada não pode ser alterada.`; `status: "Faturado"` → 422; `faturado`, `valorTotal`, `lancamento` nunca vêm do corpo | `OsController.php` |
| Cliente criado pelo operador | `POST /api/v1/clientes` sem `senha` → 201 com `result.senha_inicial` (16 caracteres, só nesta resposta) e `result.email_enviado` (bool), `Cache-Control: no-store`; com `senha` informada não devolve nenhum dos dois; sempre `senha_trocar = 1` | `ClientesController.php` |
| API do cliente | `POST /api/v1/client/auth` → `result.{access_token, refresh_token, token_type:"Bearer", expires_in:3600, troca_de_senha_obrigatoria}`; access token de no máximo 1 h mesmo com `API_TOKEN_EXPIRE_TIME` maior; tokens antigos sem `sid` são recusados (401). `POST /api/v1/client/refresh` `{refresh_token}` → 200 par novo / 401 `Faça o login novamente!` (refresh de operador também é 401; reuso revoga a família). `POST /api/v1/client/logout` `{refresh_token}` → 204 sempre. `PUT /api/v1/client/password` (Bearer) `{senha_atual, senha_nova}` → 200 par novo / 422 `{erros:{senha_atual\|senha_nova}}` (nova com 8 a 72 caracteres e diferente da atual) / 429 com `Retry-After`; troca derruba os tokens anteriores. Com `senha_trocar = 1`, toda rota autenticada → 403 `{status:false, code:"troca_de_senha_obrigatoria", message:"Defina uma nova senha antes de continuar."}`, exceto `client/password`, `client/refresh`, `client/logout` | `ClientLoginController.php`, `REST_Controller::logged_client()` |
| Limite de OS do cliente | `POST /api/v1/client/os` → 201 `{status:true, message, os_id}`; acima de `CLIENTE_OS_LIMITE_DIARIO` aberturas no dia (padrão 10; `0` desliga; dia de `APP_TIMEZONE`) → 429 com `Retry-After` = segundos até 00:00 e `Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência.`; a tela `mine/adicionarOs` mostra a mesma mensagem | `ClientOsController.php`, `conecte_helper.php` |
| Login web | `POST /login/verificarLogin` e `POST /mine/login` respondem JSON: 200 · 422 `erros` por campo · 401 credenciais · 403 conta desativada/expirada (painel) · 429 com `Retry-After` e `retry_after`; o token CSRF novo vem no campo de nome configurado (`APP_CSRF_TOKEN_NAME`), não mais `MAPOS_TOKEN` fixo | `Login.php`, `Mine.php` |
| Logout | `login/sair` e `mine/sair` só por POST com CSRF; GET → 405 com `Allow: POST` sem encerrar a sessão | `Login.php`, `Mine.php` |
| Migrations novas | `20261103000000_create_webhook_eventos`, `20261103000100_add_tipo_to_api_refresh_tokens`, `20261103000200_add_senha_trocar_sessao_versao_to_clientes`, `20261103000300_add_reserva_to_email_queue`, `20261103000400_add_index_charge_to_cobrancas` | `application/database/migrations/` |
| Comando novo | `php index.php tools marcarSenhasLegadas` — marca `senha_trocar = 1` em quem ainda tem o CPF/CNPJ como senha; imprime só `<n> cliente(s) marcado(s)` | `application/controllers/Tools.php` |
| Fila de e-mail | `email/process` sob `GET_LOCK('mapos_email_fila')` (liberado quando o processo morre), lotes de 50, até 5 tentativas; `email/retry` reabre `failed` com < 5 tentativas e `sending` reservado há > 15 min, e aplica a retenção (`webhook_eventos` 90 dias; refresh tokens expirados/revogados 30 dias; `email_queue` `sent` 7 dias e `failed` 30 dias); ambos saem 0/1 | `application/libraries/MY_Email.php`, `application/controllers/Email.php`, `application/libraries/Retencao.php` |
| Variáveis novas | `EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN` (vazias = webhook daquele gateway desligado, 401), `WEBHOOK_RATE_LIMIT=120`, `CLIENTE_OS_LIMITE_DIARIO=10`, `PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT=10`, `PAYMENT_GATEWAYS_ASAAS_TIMEOUT=10`, `PAYMENT_GATEWAYS_EFI_TIMEOUT=10` (padrão da Efí caiu de 30 para 10 s) | `application/.env.example` |

**Breaking changes** (exatamente estas 7, cada uma como uma linha do CHANGELOG começando com `- **Breaking:**`):

1. API: falta de permissão passa de `401` para `403`.
2. API: token ausente, inválido, expirado, de outro tipo ou de conta/grupo desativado passa de `403` para `401`.
3. Login do painel e da área do cliente responde por status HTTP (200/401/403/422/429) e o token CSRF novo vem no campo de nome configurado, não mais `MAPOS_TOKEN`.
4. Logout (`login/sair`, `mine/sair`) só por POST com CSRF; GET responde `405`.
5. API do cliente: access token de no máximo 1 h, com refresh token obrigatório (`POST /api/v1/client/refresh`); tokens antigos sem `sid` deixam de valer.
6. Listagens da API: `perPage` com teto 100 e `perPage`/`page` inválidos respondem `422`.
7. `POST /api/v1/client/os` com limite diário (`CLIENTE_OS_LIMITE_DIARIO`, `429` com `Retry-After`).

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `docs/webhooks-de-pagamento.md` | Criar | Guia dos webhooks para quem administra |
| `docs/api-autenticacao.md` | Modificar | 401×403, API do cliente, `senha_inicial`, paginação, limite de OS; remove as afirmações erradas |
| `docs/servidor-web.md` | Modificar | `.htaccess` 2.4, template do nginx do Docker, caminho da Efí fora do log |
| `docs/migracao-4x-para-5.md` | Modificar | Variáveis, migrations, `marcarSenhasLegadas`, logout POST, login por status, smoke |
| `CHANGELOG.md` | Modificar | Entradas em `## [Unreleased]` com as 7 breaking changes |

### Detalhamento Técnico

1. **`docs/webhooks-de-pagamento.md` (novo).** Título `# Webhooks de pagamento`. Parágrafo de abertura: o que muda (a cobrança vira paga sozinha quando o gateway avisa; sem webhook continua valendo o "Atualizar" manual da cobrança). Seções com **estes títulos exatos**, nesta ordem:
   - `## Como funciona` — as três URLs, a regra "nunca confia no corpo, reconsulta o gateway", idempotência, valor conferido, baixa do lançamento, cobrança desconhecida = `ignorado`.
   - `## Cadastrar a URL em cada gateway` — uma subseção por gateway (`### Efí`, `### Mercado Pago`, `### Asaas`): o que preencher no `.env`, se a URL vai sozinha na cobrança (Efí, Mercado Pago) ou se é cadastrada no painel (Asaas, com o token de autenticação = `ASAAS_WEBHOOK_TOKEN`; Mercado Pago: a assinatura secreta é gerada no painel de webhooks da aplicação e vai em `MP_WEBHOOK_SECRET`). Gere segredos com `openssl rand -hex 32`. Não invente nomes de menu dos painéis além de "painel de webhooks"/"integrações"; diga para conferir a documentação do gateway.
   - `## HTTPS obrigatório` — a URL sai de `APP_BASEURL` (`site_url()`); precisa ser HTTPS público acessível pelo gateway; atrás de proxy, `APP_PROXY_IPS`.
   - `## Respostas` — tabela com os códigos do Comportamento Esperado (200 por resultado, 401, 405, 413, 429, 503) e o que o gateway faz com cada um (5xx = reentrega).
   - `## Trocar um segredo` — Efí: o segredo está na URL gravada em cada cobrança já criada, então cobranças antigas passam a receber 401 após a troca; para as pendentes use "Atualizar" na cobrança. Mercado Pago e Asaas: troque no painel e no `.env` ao mesmo tempo; o Asaas pausa a fila de webhooks depois de falhas seguidas — reative no painel.
   - `## Esconder o caminho da Efí nos logs` — o segredo vai no caminho. nginx (já aplicado no Docker, `docker/etc/nginx/default.template.conf`):

     ```nginx
     map $request_uri $loggable {
         ~webhooks/efi/ 0;
         default        1;
     }
     # dentro do server:
     access_log /var/log/nginx/access.log combined if=$loggable;
     ```

     Apache:

     ```apache
     SetEnvIf Request_URI "webhooks/efi/" mapos_sem_log
     CustomLog ${APACHE_LOG_DIR}/access.log combined env=!mapos_sem_log
     ```

     Nota: o `error_log` do servidor ainda pode registrar a linha da requisição quando o próprio servidor falha; o log do Map-OS (`application/logs`) não registra o caminho.
   - `## Reprocessar um evento` — evento que falhou (503) fica sem `processado_em` e é reprocessado sozinho na reentrega. Para reprocessar um já gravado (`ignorado` porque a cobrança ainda não existia, `divergente` depois de corrigir o valor), apague a linha e peça a reentrega no painel do gateway (ou use "Atualizar" na cobrança). SQL numa linha só: `DELETE FROM webhook_eventos WHERE gateway = 'asaas' AND evento_id = 'evt_123';` (valores de `gateway`: `efi`, `mercadopago`, `asaas`). Lembrar que a retenção apaga eventos com mais de 90 dias.
   - `## Destravar a fila de e-mail` — o lock (`GET_LOCK`) solta quando o processo morre, não precisa de ação; `sending` preso volta sozinho em 15 min pelo `email/retry`; `failed` com 5 tentativas é definitivo. Para reenviar um e-mail definitivo, SQL numa linha só: `UPDATE email_queue SET status = 'pending', tentativas = 0, reservado_por = NULL, reservado_em = NULL WHERE id = 42;`. Os dois crons (`email/process` a cada 2 min e `email/retry` a cada 5 min) e os códigos de saída 0/1.
   - `## Conferir` — o smoke `scripts/smoke-seguranca.sh` (uso, pré-requisito `API_ENABLED=true`, códigos 0/1/2, `SMOKE_URL`, `SMOKE_SEM_DOCKER=1`) e um `curl -i -X POST https://seu-dominio/index.php/webhooks/asaas` que deve responder `401`.
2. **`docs/api-autenticacao.md`.**
   - Corrija as três afirmações erradas listadas no Contexto: nenhuma das frases "dura o configurado", "passa a dar `403`" e "recebe `403` na hora" pode continuar no arquivo.
   - Nova seção `## 401 ou 403` com a regra do Comportamento Esperado (403 só falta de permissão; 401 para todo problema de token/conta) e o passo 2 de "O que um app novo deve fazer" ajustado para "Ao receber `401`".
   - Nova seção `## API do cliente` com `client/auth`, `client/refresh`, `client/logout`, `client/password` (contratos da tabela), o 403 `troca_de_senha_obrigatoria` e a lista de rotas liberadas durante a troca.
   - Nova seção `## Paginação e filtros de data` (`perPage`, `page`, 422, formatos e janela de 366 dias).
   - Nova seção `## Cliente criado pela API` (`senha_inicial`, `email_enviado`, `Cache-Control: no-store`, só na resposta 201; o `GET` nunca devolve).
   - Nova seção `## Limite de abertura de OS pelo cliente` (`POST /api/v1/client/os`, 429 + `Retry-After`, `CLIENTE_OS_LIMITE_DIARIO`).
   - Em "Outras mudanças de contrato", a OS: `PUT` em OS faturada/cancelada → 403 e `status: "Faturado"` → 422.
   - Tabela de variáveis: `API_TOKEN_EXPIRE_TIME` = "Duração do access token, em segundos; do usuário e do cliente, no máximo 1 hora."; acrescente `CLIENTE_OS_LIMITE_DIARIO` e `WEBHOOK_RATE_LIMIT` (este com link para `webhooks-de-pagamento.md`).
3. **`docs/servidor-web.md`.**
   - O parágrafo que diz "o `docker/etc/nginx/default.conf` cobre o nginx do Docker" passa a dizer que a fonte é `docker/etc/nginx/default.template.conf` e que o compose gera o `default.conf` a partir dele ao subir (mudanças vão no template).
   - Nova seção `## Pastas de armazenamento (.htaccess)` com o bloco gerado (`<IfModule mod_authz_core.c>` + `Require all denied` e o fallback 2.2), a regravação automática do `.htaccess` antigo sem `mod_authz_core` e a linha de log em caso de falha.
   - Nova seção `## Webhooks de pagamento` curta: as rotas `webhooks/*` precisam continuar chegando ao `index.php` por POST, sem bloqueio nem autenticação do servidor; o caminho da Efí deve ficar fora do access log (link para `webhooks-de-pagamento.md`).
4. **`docs/migracao-4x-para-5.md`.**
   - Em "3.3. Ajuste o `application/.env`": as oito variáveis novas com padrão e o que fazem (segredos vazios = webhook desligado; Efí agora com timeout padrão 10 s).
   - Em "3.4": as cinco migrations novas pelo nome completo do arquivo (sem `.php`).
   - Novo subtítulo dentro de 3.4 ou logo depois: `php index.php tools marcarSenhasLegadas`, opcional, para quem quer obrigar a troca de quem ainda usa o documento como senha.
   - Em "3.6. Tarefas agendadas": os crons continuam os mesmos e agora saem 0/1 e também aplicam a retenção.
   - Em "3.7. Confira": o smoke `scripts/smoke-seguranca.sh` no Docker e a conferência dos webhooks (link).
   - Em "4. O que muda para cada perfil": logout só por POST (`login/sair`, `mine/sair`; personalização com link "Sair" precisa virar formulário POST com CSRF), login por status com `verificarLogin`/`mine/login` respondendo JSON por status e o campo CSRF de nome configurado, cliente novo recebendo senha aleatória e troca obrigatória no primeiro acesso, e para quem usa a API um resumo das 7 breaking changes com link para `api-autenticacao.md`.
5. **`CHANGELOG.md`** — tudo **dentro de `## [Unreleased]`** (antes de `## [5.2.2]`), no formato Keep a Changelog do arquivo: frase de abertura com "Tem migrations (5) e variáveis novas no `.env`" ; `### Security` (webhooks autenticados com reconsulta, senha inicial aleatória, token do cliente de 1 h com refresh, sessão do cliente revalidada, limite de OS, fila de e-mail com lock, `.htaccess` 2.4, seed só em development, log sem segredos); `### Changed` começando pelas **7 linhas `- **Breaking:** …`** (uma por item da lista do Comportamento Esperado, citando `perPage` e `CLIENTE_OS_LIMITE_DIARIO` nas linhas 6 e 7); `### Added` (webhooks, `client/refresh`/`logout`/`password`, `marcarSenhasLegadas`, `scripts/smoke-seguranca.sh`, `docs/webhooks-de-pagamento.md`); `### Fixed` (exclusões em transação sem sucesso falso, 500 da API em JSON, `calendario` devolvendo os eventos, QR da compra do cliente, sessão expirada em AJAX, timeouts dos gateways). Nenhuma outra linha do arquivo usa `**Breaking:**`.

**Refatoração:** Nenhuma.
