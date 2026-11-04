---
checkpoint: null
complexity: G
created_at: "2026-11-03 22:58:04"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testAccessTokenDoClienteDuraNoMaximoUmaHora tests/Unit/ApiClienteTokensTest.php
      text: Login do cliente com API_TOKEN_EXPIRE_TIME=2592000 devolve expires_in 3600, JWT com exp - iat = 3600 e sid, e grava refresh com tipo cliente
    - done: false
      test: application/vendor/bin/phpunit --filter testLoginInformaTrocaDeSenhaObrigatoria tests/Unit/ApiClienteTokensTest.php
      text: Login de cliente com senha_trocar 1 devolve troca_de_senha_obrigatoria true
    - done: false
      test: application/vendor/bin/phpunit --filter testLoginSemRefreshResponde503 tests/Unit/ApiClienteTokensTest.php
      text: Login sem refresh disponível responde 503 sem access token
    - done: false
      test: application/vendor/bin/phpunit --filter testRefreshDoClienteGiraOPar tests/Unit/ApiClienteTokensTest.php
      text: POST client/refresh com refresh válido devolve par novo com o mesmo sid
    - done: false
      test: application/vendor/bin/phpunit --filter testReusoDoRefreshRevogaAFamilia tests/Unit/ApiClienteTokensTest.php
      text: Refresh reutilizado após a tolerância responde 401, revoga a família e o access token daquele sid passa a receber 401
    - done: false
      test: application/vendor/bin/phpunit --filter testRefreshNaoCruzaTipos tests/Unit/ApiClienteTokensTest.php
      text: Refresh de operador em client/refresh responde 401 sem consumir o token, e refresh de cliente não gira como usuario
    - done: false
      test: application/vendor/bin/phpunit --filter testRefreshDeClienteExcluidoResponde401 tests/Unit/ApiClienteTokensTest.php
      text: Refresh de cliente excluído responde 401 e revoga os refresh dele
    - done: false
      test: application/vendor/bin/phpunit --filter testLogoutRevogaASessao tests/Unit/ApiClienteTokensTest.php
      text: POST client/logout responde 204 e o refresh seguinte com o mesmo token responde 401; sem corpo também 204
    - done: false
      test: application/vendor/bin/phpunit --filter testLoggedClientResponde401EmTodaFalhaDeToken tests/Unit/ApiClienteTokensTest.php
      text: logged_client responde 401 sem header, com token inválido, de operador, sem sid, de família revogada e com tv antigo
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaPendenteBloqueiaExcetoQuemPermite tests/Unit/ApiClienteTokensTest.php
      text: Cliente com senha_trocar 1 recebe 403 code troca_de_senha_obrigatoria em logged_client() e passa em logged_client(true)
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaComSenhaAtualErradaResponde422 tests/Unit/ApiClienteTokensTest.php
      text: PUT client/password com senha atual errada responde 422 erros.senha_atual e conta 1 falha no escopo confirmacao
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaComSenhaNovaInvalidaResponde422 tests/Unit/ApiClienteTokensTest.php
      text: PUT client/password com senha nova curta ou igual à atual responde 422 erros.senha_nova; a curta não conta falha
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaBloqueadaResponde429 tests/Unit/ApiClienteTokensTest.php
      text: PUT client/password com o escopo confirmacao bloqueado responde 429 com Retry-After
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaDeSenhaPelaApiRenovaOsTokens tests/Unit/ApiClienteTokensTest.php
      text: PUT client/password válido zera senha_trocar, incrementa versões, devolve par novo que funciona e o access token antigo passa a 401
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaComFalhaResponde500 tests/Unit/ApiClienteTokensTest.php
      text: PUT client/password com registrarTrocaDeSenha falso responde 500 'Não foi possível alterar a senha.'
    - done: false
      test: grep -q "api/v1/client/refresh'\] = 'api/v1/client/ClientLoginController/refresh'" application/config/routes_api.php && grep -q "api/v1/client/logout'\] = 'api/v1/client/ClientLoginController/logout'" application/config/routes_api.php && grep -q "api/v1/client/password'\] = 'api/v1/client/ClientLoginController/password'" application/config/routes_api.php
      text: As três rotas novas estão em routes_api.php
    - done: false
      test: application/vendor/bin/phpunit tests/Unit/ApiClienteTokensTest.php tests/Unit/ApiAutenticacaoTest.php tests/Unit/ApiBaseTest.php tests/Unit/ApiDesligadaTest.php tests/Unit/LimiteLoginTest.php tests/Unit/SessaoELimiteTest.php
      text: Suítes da API passam com o logged_client novo
    - done: false
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/libraries/REST_Controller.php application/controllers/api/v1/client/ClientLoginController.php application/config/routes_api.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-003
    - TASK-009
files:
    - application/libraries/REST_Controller.php
    - application/controllers/api/v1/client/ClientLoginController.php
    - application/config/routes_api.php
    - tests/Unit/ApiClienteTokensTest.php
id: TASK-023
linter: composer analyse
n45_version: 1.1.0
outcome: ""
persona: backend
phase: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/ApiClienteTokensTest.php tests/Unit/ApiAutenticacaoTest.php tests/Unit/ApiBaseTest.php tests/Unit/ApiDesligadaTest.php tests/Unit/LimiteLoginTest.php tests/Unit/SessaoELimiteTest.php
title: 'Tokens da API do cliente: 1 h, refresh, logout e troca de senha'
updated_at: "2026-11-03 22:58:04"
---
## Contexto

O token da API do cliente (`POST /api/v1/client/auth`, `ClientLoginController::login_post`) é um JWT que dura `API_TOKEN_EXPIRE_TIME` (até 30 dias), sem refresh, sem logout e sem como revogar uma sessão roubada. `REST_Controller::logged_client()` ainda responde **403** para token inválido/expirado/de operador (deveria ser 401), não confere a sessão (`sid`) e não sabe da troca de senha obrigatória do primeiro acesso.

Esta task dá à API do cliente o mesmo modelo do operador: access token de no máximo 1 h + refresh token rotativo com detecção de reuso, logout que revoga a sessão, troca de senha pela API e o bloqueio `troca_de_senha_obrigatoria` enquanto `clientes.senha_trocar = 1`.

Contratos já entregues que esta task consome:

- `Refresh_token` (TASK-009), tabela `api_refresh_tokens` com coluna `tipo ENUM('usuario','cliente')`:
  - `emitir(int $donoId, ?string $ip = null, ?string $userAgent = null, ?string $familia = null, string $tipo = 'usuario'): ?string` (null se a tabela/coluna não existir);
  - `girar(string $token, ?string $ip = null, ?string $userAgent = null, string $tipo = 'usuario'): array` → `['ok' => true, 'usuario_id' => int, 'familia' => string, 'refresh_token' => string]` ou `['ok' => false, 'motivo' => 'invalido'|'revogado'|'reutilizado'|'expirado']`; token de outro tipo = `invalido` sem consumir; reuso após a tolerância revoga a família e loga `refresh reuso tipo=cliente dono=<id>`;
  - `revogar(string $token): bool` (revoga a família do token), `revogarDoUsuario(int $donoId, string $tipo = 'usuario'): void`, `familiaDoToken(string $token): ?string`, `familiaAtiva(string $familia): bool`.
- `Authorization_Token`: `generateToken(array $data, ?int $tetoExpiracao = null)`, `expiresIn(?int $teto = null): int`, `TETO_COM_REFRESH = 3600`, `versaoAtual('clientes', 'idClientes', $id): int`, `versaoVigente(object $dados, int $versaoAtual): bool`.
- `REST_Controller::sessaoDoTokenAtiva(object $dadosDoToken): bool` (privado, já usado por `logged_user()`): `sid` vazio → `true`; senão `refresh_token->familiaAtiva($sid)`.
- `Clientes_model::registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool` (TASK-009): grava senha e `senha_trocar`, incrementa `sessao_versao` e `token_versao`, revoga refresh `tipo='cliente'`; `false` = nada mudou.
- `Limite_login` (escopo `confirmacao`): `confirmarSenha(string $email, string $ip, string $senha, string $hash): string` → `SENHA_OK|SENHA_ERRADA|SENHA_BLOQUEADA`; `segundosRestantes(string $escopo, ?string $email, string $ip): int`; `MENSAGEM`.
- Modelo a seguir: `UsuariosController::refresh_post()`, `logout_post()` e `accessTokenDoUsuario()` (operador) — mesma forma de resposta.
- Rotas da API do cliente ficam em `application/config/routes_api.php` (só carregam com `API_ENABLED=true`); hoje existe `api/v1/client/auth` → `api/v1/client/ClientLoginController/login`.

Decisão desta task: access token de cliente **sem `sid`** (emitido antes desta versão, com até 30 dias) passa a ser recusado com 401 — o app faz login de novo e recebe o par novo. É a única forma de impor o teto de 1 h aos tokens já emitidos (breaking change que a Phase 5 documenta).

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `POST /api/v1/client/auth` `{email, password}` corretos, `API_TOKEN_EXPIRE_TIME=2592000` | 200 `{status:true, message:'Login bem-sucedido', result:{access_token, refresh_token, token_type:'Bearer', expires_in:3600, troca_de_senha_obrigatoria:false}}`; JWT com `exp - iat = 3600`, `type` de cliente e `sid` = família do refresh; linha em `api_refresh_tokens` com `tipo = 'cliente'` |
| login de cliente com `senha_trocar = 1` | 200 com `troca_de_senha_obrigatoria: true` |
| login com `emitir()` devolvendo `null` (tabela sem `tipo`) | 503 `{status:false, message:'Não foi possível iniciar a sessão. Tente novamente.'}`; nenhum access token |
| `POST /api/v1/client/refresh` `{refresh_token}` válido de cliente | 200 `{status:true, message:'Token renovado!', result:{access_token, refresh_token (novo), token_type:'Bearer', expires_in:3600}}`; access token com o mesmo `sid` |
| refresh do cliente reutilizado depois de `TOLERANCIA_SEGUNDOS` | 401 `{status:false, message:'Faça o login novamente!'}`; família revogada; access token com aquele `sid` em `GET /api/v1/client/os` → 401 |
| refresh de **operador** em `/api/v1/client/refresh` | 401; o token do operador **não** é consumido (`usado_em` continua nulo) |
| refresh de **cliente** em `/api/v1/refresh` (operador) | 401 |
| refresh de cliente cujo cliente foi excluído | 401 e `revogarDoUsuario(<id>, 'cliente')` |
| `POST /api/v1/client/logout` `{refresh_token}` válido | 204 sem corpo; refresh seguinte com esse token → 401 |
| `POST /api/v1/client/logout` sem corpo ou token desconhecido | 204 |
| `GET /api/v1/client/os` sem `Authorization` | 401 `Faça login para acessar a API.` |
| `GET /api/v1/client/os` com token inválido, expirado, de operador, sem `sid`, de família revogada ou com `tv` antigo | 401 `Faça o login novamente!` |
| `GET /api/v1/client/os` com cliente `senha_trocar = 1` | 403 `{status:false, code:'troca_de_senha_obrigatoria', message:'Defina uma nova senha antes de continuar.'}` |
| `PUT /api/v1/client/password` com `senha_trocar = 1` e corpo válido | não é bloqueado pelo 403 (passa por `logged_client(true)`) |
| `PUT /api/v1/client/password` `{senha_atual:'errada', senha_nova:'NovaSenha123'}` | 422 `{status:false, message:'Dados inválidos.', erros:{senha_atual:'A senha atual não confere.'}}`; 1 falha no escopo `confirmacao` |
| `PUT /api/v1/client/password` `{senha_atual:<certa>, senha_nova:'curta'}` | 422 `erros.senha_nova = 'A senha deve ter de 8 a 72 caracteres.'`; **nenhuma** falha contada (a atual nem é conferida) |
| `PUT /api/v1/client/password` com `senha_nova` igual à atual | 422 `erros.senha_nova = 'A nova senha precisa ser diferente da atual.'` |
| `PUT /api/v1/client/password` com o escopo `confirmacao` bloqueado | 429 `{status:false, message:Limite_login::MENSAGEM}` + header `Retry-After: <segundos>` |
| `PUT /api/v1/client/password` válido | 200 `{status:true, message:'Senha alterada.', result:{access_token, refresh_token, token_type:'Bearer', expires_in:3600}}`; `senha_trocar = 0`; `token_versao`/`sessao_versao` +1; refresh antigos revogados; o access token **antigo** em `GET /api/v1/client/os` → 401; o **novo** → passa |
| `PUT /api/v1/client/password` com `registrarTrocaDeSenha` falso | 500 `{status:false, message:'Não foi possível alterar a senha.'}` |

## TDD

Suíte nova `tests/Unit/ApiClienteTokensTest.php` (classe `final`, `MaposTestCase`, SQLite em memória). Para `logged_client()`, siga `tests/Unit/ApiBaseTest.php` (seção "401 em logged_user": instância com `response()` lançando exceção com `{code, data}`, `$_SERVER['HTTP_AUTHORIZATION']` montado e restaurado no `tearDown`, `Authorization_Token` real com chave de teste). Para `ClientLoginController`, subclasse anônima com `response()` lançando, `post()`/`put()` lendo um array `entrada`, `input` stub (`ip_address()`, `user_agent()`), `refresh_token = new Refresh_token(['db' => $this->db, ...])` real, `limite_login = new Limite_login(['db' => $this->db, 'segredo' => 'segredo-de-teste', 'auditar' => fn () => null])` real e `Clientes_model` real. Tabelas: `clientes` (`idClientes, nomeCliente, email, senha, senha_trocar, sessao_versao, token_versao`), `api_refresh_tokens` (colunas usadas por `Refresh_token`, ver `tests/Unit/SenhaClienteTest.php`), `login_attempts` (ver `tests/Unit/LimiteLoginTest.php`).

```php
public function testAccessTokenDoClienteDuraNoMaximoUmaHora(): void
{
    $_ENV['API_TOKEN_EXPIRE_TIME'] = '2592000'; // restaurado no tearDown
    $resposta = $this->chamarLogin(['email' => 'ana@x.com', 'password' => 'Senha123!']);

    $this->assertSame(200, $resposta['code']);
    $this->assertSame(3600, $resposta['data']['result']['expires_in']);
    $dados = $this->decodificar($resposta['data']['result']['access_token']);
    $this->assertSame(3600, $dados->exp - $dados->iat);
    $this->assertSame('cliente', $this->db->get('api_refresh_tokens')->row()->tipo);
}
```

Cobrir os demais casos da tabela (nomes nos critérios).

**Refatoração:** o access token do cliente nasce num só método privado (`accessTokenDoCliente(object $cliente, string $familia): string`), usado por login, refresh e troca de senha.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/libraries/REST_Controller.php` | Modificar | `logged_client(bool $permitirTrocaPendente = false)`: 401 em toda falha de token, `sid` obrigatório e ativo, 403 `troca_de_senha_obrigatoria` |
| `application/controllers/api/v1/client/ClientLoginController.php` | Modificar | login com refresh e teto 1 h; `refresh_post`, `logout_post`, `password_put` |
| `application/config/routes_api.php` | Modificar | `client/refresh`, `client/logout`, `client/password` |
| `tests/Unit/ApiClienteTokensTest.php` | Criar | Suíte nova |

### Detalhamento Técnico

1. **`REST_Controller::logged_client(bool $permitirTrocaPendente = false)`** — assinatura pública (os controllers `Client*` chamam `$this->logged_client()` sem argumento e passam a ser bloqueados com troca pendente sem mudar nada neles):
   - sem `HTTP_AUTHORIZATION` → 401 `Faça login para acessar a API.` (remover a segunda chamada morta `response(['Authentication failed'], 200)`);
   - token inválido/expirado, `type` diferente de `TOKEN_TYPE_CLIENT`, cliente inexistente, `versaoVigente` falso, **`sid` vazio** ou `! $this->sessaoDoTokenAtiva($dados)` → 401 `Faça o login novamente!` (todas as respostas que hoje são `HTTP_FORBIDDEN` aqui viram `HTTP_UNAUTHORIZED`);
   - `(int) ($cliente->senha_trocar ?? 0) === 1 && ! $permitirTrocaPendente` → 403 `{status:false, code:'troca_de_senha_obrigatoria', message:'Defina uma nova senha antes de continuar.'}`;
   - o teste de código existente (`ApiAutenticacaoTest`) procura `versaoVigente` dentro de `logged_client` — manter a chamada.
2. **Rotas** (`routes_api.php`, junto de `api/v1/client/auth`):
   ```php
   $route['api/v1/client/refresh'] = 'api/v1/client/ClientLoginController/refresh';
   $route['api/v1/client/logout'] = 'api/v1/client/ClientLoginController/logout';
   $route['api/v1/client/password'] = 'api/v1/client/ClientLoginController/password';
   ```
3. **`login_post`** (mantém limite de tentativas, 401 genérico e o campo `password`): após `registrarSucesso`, `$refresh = $this->refresh_token->emitir((int) $cliente->idClientes, $ip, $ua, null, 'cliente')`; `null` → 503 `Não foi possível iniciar a sessão. Tente novamente.` + `log_message('error', 'api cliente login sem refresh token')`; senão `$familia = $this->refresh_token->familiaDoToken($refresh)` e resposta 200 com `access_token` (de `accessTokenDoCliente`), `refresh_token`, `token_type: 'Bearer'`, `expires_in: $this->authorization_token->expiresIn(Authorization_Token::TETO_COM_REFRESH)`, `troca_de_senha_obrigatoria: (int) ($cliente->senha_trocar ?? 0) === 1`.
4. **`accessTokenDoCliente(object $cliente, string $familia): string`** — `generateToken(['uid' => (int) $cliente->idClientes, 'nome' => (string) $cliente->nomeCliente, 'email' => (string) $cliente->email, 'type' => REST_Controller::TOKEN_TYPE_CLIENT, 'tv' => versaoAtual('clientes', 'idClientes', id), 'sid' => $familia], Authorization_Token::TETO_COM_REFRESH)`.
5. **`refresh_post`** — corpo JSON `{refresh_token}`; `girar($token, $ip, $ua, 'cliente')`; ok mas `Clientes_model->getById(usuario_id)` nulo → `revogarDoUsuario($id, 'cliente')` e 401; falha → 401 `Faça o login novamente!`; ok → 200 `Token renovado!` com o par novo.
6. **`logout_post`** — `$this->refresh_token->revogar((string) ($this->post('refresh_token', true) ?? ''))`; sempre `$this->response(null, 204)`.
7. **`password_put`** — `$sessao = $this->logged_client(true)`; `$cliente = $sessao->usuario`; corpo JSON `{senha_atual, senha_nova}` lido com `(string) (... ?? '')`:
   1. `mb_strlen($nova) < 8 || mb_strlen($nova) > 72` → 422 `erros.senha_nova = 'A senha deve ter de 8 a 72 caracteres.'` (sem conferir a atual);
   2. `confirmarSenha((string) $cliente->email, $ip, $atual, (string) $cliente->senha)`: `SENHA_BLOQUEADA` → `$this->output->set_header('Retry-After: ' . max(1, segundosRestantes('confirmacao', email, ip)))` (pelo `output`, não `header()`, para o teste ler o cabeçalho) + 429 `MENSAGEM`; `SENHA_ERRADA` → 422 `erros.senha_atual = 'A senha atual não confere.'`;
   3. `password_verify($nova, $cliente->senha)` → 422 `erros.senha_nova = 'A nova senha precisa ser diferente da atual.'`;
   4. `registrarTrocaDeSenha((int) $cliente->idClientes, password_hash($nova, PASSWORD_DEFAULT), false)` falso → 500 `Não foi possível alterar a senha.`;
   5. emite refresh novo (`tipo 'cliente'`), relê o cliente (nova `tv`) e responde 200 `Senha alterada.` com o par novo. `log_app`/`Audit_model` só com o texto "Cliente alterou a senha pelo APP" (nunca a senha).
   Todas as 422 com `message: 'Dados inválidos.'`.

**Contrato HTTP:**

```
POST /api/v1/client/auth
{"email":"ana@x.com","password":"Senha123!"}
200 {status:true, message:"Login bem-sucedido", result:{access_token:"<jwt>", refresh_token:"<64 hex>", token_type:"Bearer", expires_in:3600, troca_de_senha_obrigatoria:false}}
401 {status:false, message:"Os dados de acesso estão incorretos."} · 429 {status:false, message:Limite_login::MENSAGEM} + Retry-After · 503 {status:false, message:"Não foi possível iniciar a sessão. Tente novamente."}

POST /api/v1/client/refresh
{"refresh_token":"<64 hex>"}
200 {status:true, message:"Token renovado!", result:{access_token, refresh_token, token_type:"Bearer", expires_in:3600}}
401 {status:false, message:"Faça o login novamente!"}        // inválido, expirado, revogado, reutilizado, de operador

POST /api/v1/client/logout
{"refresh_token":"<64 hex>"}
204 (sem corpo, sempre)

PUT /api/v1/client/password
Authorization: Bearer <access token do cliente>
{"senha_atual":"Abc23456789Defgh","senha_nova":"MinhaSenha2026"}   // senha_nova: 8 a 72 caracteres, diferente da atual
200 {status:true, message:"Senha alterada.", result:{access_token, refresh_token, token_type:"Bearer", expires_in:3600}}
401 token · 422 {status:false, message:"Dados inválidos.", erros:{senha_atual?|senha_nova?}} · 429 + Retry-After · 500 {status:false, message:"Não foi possível alterar a senha."}

Qualquer rota autenticada da API do cliente (exceto password/refresh/logout) com senha_trocar = 1:
403 {status:false, code:"troca_de_senha_obrigatoria", message:"Defina uma nova senha antes de continuar."}
```

**Contrato com camadas adjacentes:**

```
Produz para: ClientOsController, ClientComprasController, ClientCobrancasController (chamam logged_client())
  - Sucesso: objeto com ->usuario (linha de clientes) e ->data (claims)
  - 401: encerra a requisição; 403 troca_de_senha_obrigatoria: encerra a requisição
Consome de: Refresh_token::girar(..., 'cliente') → ['ok'=>false] = HTTP 401
Consome de: Clientes_model::registrarTrocaDeSenha(...) → false = HTTP 500
```
