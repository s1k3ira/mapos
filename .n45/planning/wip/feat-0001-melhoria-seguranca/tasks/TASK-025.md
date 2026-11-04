---
checkpoint: null
complexity: G
created_at: "2026-11-04 01:14:38"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testEmailInvalidoResponde422ComErrosPorCampo tests/Unit/LoginPorStatusTest.php
      text: Login do painel com validação falha responde 422 JSON com erros por campo, message 'Confira os campos destacados.' e sem contar falha
    - done: false
      test: application/vendor/bin/phpunit --filter testSenhaErradaResponde401ComMensagemGenerica tests/Unit/LoginPorStatusTest.php
      text: Login do painel com senha errada responde 401 application/json com {result:false, message:'Os dados de acesso estão incorretos.', <csrf>:hash} e conta a falha
    - done: false
      test: application/vendor/bin/phpunit --filter testEmailDesconhecidoResponde401ComAMesmaMensagem tests/Unit/LoginPorStatusTest.php
      text: Login do painel com e-mail sem conta responde 401 com a mesma message da senha errada
    - done: false
      test: application/vendor/bin/phpunit --filter testContaBloqueadaResponde403SoComASenhaCerta tests/Unit/LoginPorStatusTest.php
      text: Conta expirada e grupo desativado respondem 403 com o motivo só depois da senha certa (senha errada na mesma conta continua 401)
    - done: false
      test: application/vendor/bin/phpunit --filter testBloqueadoResponde429ComRetryAfter tests/Unit/LoginPorStatusTest.php
      text: 'Login do painel bloqueado responde 429 com header Retry-After: 60 e retry_after 60 no corpo, sem conferir a senha'
    - done: false
      test: application/vendor/bin/phpunit --filter 'testSucessoResponde200EmJson|testDoisFatoresResponde200ComDestino' tests/Unit/LoginPorStatusTest.php
      text: Login do painel certo responde 200 {result:true} em JSON e abre a sessão; com 2FA responde 200 {result:true, destino:site_url('login/doisFatores')} sem logado na sessão
    - done: false
      test: application/vendor/bin/phpunit --filter testTokenCsrfVemNoCampoDeNomeConfigurado tests/Unit/LoginPorStatusTest.php
      text: Com get_csrf_token_name() = 'OUTRO' a resposta de falha traz a chave OUTRO com o hash e não traz MAPOS_TOKEN
    - done: false
      test: application/vendor/bin/phpunit --filter testRespostasDoLoginNuncaTrazemRedirect tests/Unit/LoginPorStatusTest.php
      text: Nenhuma resposta de login traz a chave redirect (o http.js a trataria como sessão expirada)
    - done: false
      test: application/vendor/bin/phpunit --filter 'testGetEmLogoutResponde405SemEncerrarASessao|testPostEmLogoutEncerraASessaoEVaiAoLogin' tests/Unit/LoginPorStatusTest.php
      text: 'GET /login/sair responde 405 com Allow: POST e a sessão continua; POST encerra a sessão (sess_destroy) e vai para site_url(''login?saiu=1'')'
    - done: false
      test: application/vendor/bin/phpunit --filter 'testIndexMostraVoceSaiuComSaiu|testIndexDescartaODoisFatoresPendente' tests/Unit/LoginPorStatusTest.php
      text: Login::index passa sucesso 'Você saiu.' com ?saiu=1 (null sem) e descarta o dois_fatores pendente da sessão
    - done: false
      test: application/vendor/bin/phpunit --filter testLoginSemDeprecated tests/Unit/LoginPorStatusTest.php
      text: Fluxos 422, 401 e 200 do login do painel rodam sem Deprecated do PHP (semDeprecated)
    - done: false
      test: application/vendor/bin/phpunit --filter 'testLoginDoClienteComEmailInvalidoResponde422|testLoginDoClienteComSenhaErradaResponde401' tests/Unit/AreaDoClienteSessaoTest.php
      text: Login do cliente com validação falha responde 422 com erros por campo e com senha errada responde 401 com a mensagem genérica e o token CSRF no campo configurado
    - done: false
      test: application/vendor/bin/phpunit --filter 'testLoginDoClienteBloqueadoResponde429ComRetryAfter|testLoginDoClienteComSucessoResponde200EmJson' tests/Unit/AreaDoClienteSessaoTest.php
      text: 'Login do cliente bloqueado responde 429 com Retry-After: 60 e retry_after 60; certo responde 200 {result:true} application/json e grava a sessão'
    - done: false
      test: application/vendor/bin/phpunit --filter 'testGetEmLogoutDoClienteResponde405SemEncerrarASessao|testPostEmLogoutDoClienteEncerraASessao|testIndexDoClienteMostraVoceSaiu' tests/Unit/AreaDoClienteSessaoTest.php
      text: 'GET /mine/sair responde 405 com Allow: POST sem encerrar a sessão; POST encerra e vai para mine?saiu=1; Mine::index mostra ''Você saiu.'' com ?saiu=1'
    - done: false
      test: '! grep -nE ''echo json_encode|MAPOS_TOKEN'' application/controllers/Login.php application/controllers/Mine.php'
      text: Login.php e Mine.php não têm mais echo json_encode nem o campo fixo MAPOS_TOKEN
    - done: false
      test: application/vendor/bin/phpunit --filter 'testLoginEmDoisPassosNaoDaSessaoAntesDoCodigo|testTodosOsLoginsGastamOBcryptAntesDeTestarAConta' tests/Unit/DoisFatoresTest.php tests/Unit/SessaoELimiteTest.php
      text: Testes de fonte do 2FA e do bcrypt antes da conta continuam verdes sem edição
    - done: false
      test: application/vendor/bin/phpunit tests/Unit/LoginPorStatusTest.php tests/Unit/AreaDoClienteSessaoTest.php tests/Unit/LoginTest.php tests/Unit/DoisFatoresTest.php tests/Unit/SessaoELimiteTest.php tests/Unit/AreaDoClienteTest.php tests/Unit/BancoDesatualizadoTest.php
      text: Suítes do login, da área do cliente e da sessão passam
    - done: false
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/controllers/Login.php application/controllers/Mine.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-024
files:
    - application/controllers/Login.php
    - application/controllers/Mine.php
    - tests/Unit/LoginPorStatusTest.php
    - tests/Unit/AreaDoClienteSessaoTest.php
id: TASK-025
linter: composer analyse
n45_version: 1.1.0
outcome: ""
persona: backend
phase: Phase 4 — Login por status, logout seguro e limite de OS do cliente
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/LoginPorStatusTest.php tests/Unit/AreaDoClienteSessaoTest.php
title: Login por status HTTP e logout só por POST no painel e na área do cliente
updated_at: "2026-11-04 01:14:38"
---
## Contexto

O login do painel (`POST /login/verificarLogin`) e o da área do cliente (`POST /mine/login`) respondem hoje sempre com status 200 e `echo json_encode(...)` sem `Content-Type: application/json`, com o token CSRF novo num campo de nome fixo `MAPOS_TOKEN` (que quebra quando a instalação troca `APP_CSRF_TOKEN_NAME`). O cliente HTTP do front (`assets/js/lib/http.js`) já decide pelo status: lança `ErroHttp(status, message, corpo)` para status >= 400 e só interpreta o corpo como JSON quando o `Content-Type` contém `application/json`. Esta task faz os dois logins responderem por status HTTP (RF-29) e torna o logout (`login/sair` e `mine/sair`) exclusivo de POST com CSRF (RF-27): GET passa a responder 405 sem encerrar a sessão.

Estado atual relevante:

- `application/controllers/Login.php` (`class Login extends CI_Controller`): `verificarLogin()` valida com `form_validation` (`email`: `valid_email|required|trim`, `senha`: `required|trim`), consulta `Limite_login` escopo `'usuario'` (`bloqueado()` antes da senha, `registrarFalha()` em e-mail/senha errados, `registrarSucesso()` ao entrar), confere com `senhaConfere()` (bcrypt gasto mesmo sem conta), `motivoDeBloqueio($user)` (conta expirada / grupo desativado, só depois da senha certa) e, com 2FA ativo, responde `{result:true, destino: site_url('login/doisFatores')}`. `falha()` devolve `['result'=>false,'message'=>..., 'MAPOS_TOKEN'=>hash]`. `sair()` faz `sess_destroy()` e redireciona, em qualquer método. Não há seams de saída (`exit()` direto).
- `application/controllers/Mine.php` (`class Mine extends CI_Controller`): `login()` tem a mesma sequência no escopo `'cliente'` (sem `motivoDeBloqueio`: cliente não tem conta desativada/expirada). Já existem `private function responderJson(int $status, array $corpo): void` (status + `application/json` + `_display()` + `encerrar()`), e os seams `protected function irPara(string $rota)` (redirect) e `protected function encerrar()` (exit). `sair()` faz `sess_destroy()` + `redirect('mine')` em qualquer método. `index()` passa `erro` e `sucesso` (flashdata) para `conecte/login`.
- O CSRF do CodeIgniter já protege todo POST de `login/*` e `mine/*` (não estão em `csrf_exclude_uris`): POST sem token é recusado pelo framework com 403 antes do controller.
- Testes de leitura de fonte que **precisam continuar verdes sem edição**: `tests/Unit/DoisFatoresTest.php::testLoginEmDoisPassosNaoDaSessaoAntesDoCodigo` (em `verificarLogin`, o literal `'destino' => site_url('login/doisFatores')` e `set_userdata('dois_fatores'` aparecem antes de `registrarSucesso` e de `$this->iniciarSessao($user)`; nenhum `'logado' => true` dentro de `verificarLogin`) e `tests/Unit/SessaoELimiteTest.php::testTodosOsLoginsGastamOBcryptAntesDeTestarAConta` (em `verificarLogin` e em `Mine::login`, a chamada `senhaConfere(` vem antes do primeiro `if (! $user` / `if (! $cliente`).

Consumidores deste contrato: a task de frontend do módulo `assets/js/modules/login/formulario.js` (lê `erro.status` e `erro.corpo` do `ErroHttp`), e a task de frontend que troca os "Sair" das views por formulários POST e mostra o "Você saiu." na tela de login do painel (`$sucesso`).

## Comportamento Esperado

**Login do painel — `POST /login/verificarLogin`** (resposta sempre `Content-Type: application/json; charset=utf-8`; campo do token CSRF = `$this->security->get_csrf_token_name()`):

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| validação falha (`form_validation->run()` falso, `error_array()` = `['email' => 'O campo E-mail deve conter um endereço de e-mail válido.']`) | 422 `{"result":false,"message":"Confira os campos destacados.","erros":{"email":"O campo E-mail deve conter um endereço de e-mail válido."},"<csrf>":"<hash>"}`; nenhuma falha contada em `login_attempts` |
| `ana@x.com` + senha errada | 401 `{"result":false,"message":"Os dados de acesso estão incorretos.","<csrf>":"<hash>"}`; `registrarFalha('usuario', ...)` chamada (1 falha por chave) |
| `ninguem@x.com` (e-mail sem conta) + qualquer senha | 401 com a **mesma** `message` `"Os dados de acesso estão incorretos."` |
| senha certa, `dataExpiracao` = `2020-01-01` | 403 `{"result":false,"message":"A conta do usuário está expirada, por favor entre em contato com o administrador do sistema.","<csrf>":"<hash>"}` |
| senha certa, grupo com `situacao = 0` | 403 com `message` `"O grupo de permissão desta conta está desativado. Fale com o administrador do sistema."` |
| e-mail bloqueado (5 falhas seguidas com relógio fixo → bloqueio de 60 s) | 429, header `Retry-After: 60`, corpo `{"result":false,"message":"Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.","retry_after":60,"<csrf>":"<hash>"}`; a senha nem é conferida |
| senha certa, sem 2FA | 200 `{"result":true}`; sessão com `logado = true` |
| senha certa, com `totp_ativado_em` | 200 `{"result":true,"destino":"http://.../index.php/login/doisFatores"}`; sessão sem `logado` |
| `security->get_csrf_token_name()` = `"OUTRO"`, senha errada | corpo tem a chave `OUTRO` com o hash e **não** tem a chave `MAPOS_TOKEN` |

**Login da área do cliente — `POST /mine/login`** (mesmo formato, escopo `'cliente'`):

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| validação falha (`error_array()` = `['senha' => 'O campo Senha é obrigatório.']`) | 422 `{"result":false,"message":"Confira os campos destacados.","erros":{"senha":"O campo Senha é obrigatório."},"<csrf>":"<hash>"}` |
| `ana@x.com` + senha errada | 401 `{"result":false,"message":"Os dados de acesso estão incorretos.","<csrf>":"<hash>"}` |
| bloqueado (5 falhas, relógio fixo) | 429, `Retry-After: 60`, `retry_after: 60`, `message` = `Limite_login::MENSAGEM` |
| senha certa | 200 `{"result":true}`; sessão com `conectado = true`, `cliente_id`, `cliente_sessao_versao` (como hoje) |

**Logout:**

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `GET /login/sair` com sessão do painel | status 405, header `Allow: POST`; `sess_destroy()` **não** chamado (sessão intacta) |
| `POST /login/sair` (CSRF válido, garantido pelo framework) | `sess_destroy()` chamado; redirect para `site_url('login?saiu=1')` |
| `GET /mine/sair` com sessão do cliente | 405, `Allow: POST`, sessão intacta |
| `POST /mine/sair` | `sess_destroy()`; redirect para `mine?saiu=1` |
| `GET /login?saiu=1` | view `mapos/login` recebe `sucesso` = `"Você saiu."`; sem `saiu` → `sucesso` = `null` |
| `GET /mine?saiu=1` sem flashdata `success` | view `conecte/login` recebe `sucesso` = `"Você saiu."`; flashdata `success` presente tem precedência |
| `GET /login` com `dois_fatores` pendente na sessão | `unset_userdata('dois_fatores')` (o "Voltar ao login" da tela do 2FA passa a apontar para `login`, então abrir o login abandona o código pendente) |

Invariantes:

- **Nenhuma** resposta de login leva a chave `redirect` (o `http.js` trata 401 com `redirect` como sessão expirada e navegaria em vez de mostrar o erro).
- A mensagem do 401 é idêntica para e-mail desconhecido e senha errada; o 403 (painel) só acontece depois da senha certa.
- O token CSRF novo vai em toda resposta diferente de 200.
- Sem `Deprecated` do PHP 8.5 ao executar esses fluxos (RF-37): campos lidos do POST passam por `(string)` antes de funções de string.

## TDD

Testes do painel em `tests/Unit/LoginPorStatusTest.php` (novo, `final class LoginPorStatusTest extends MaposTestCase`); testes do `Mine` acrescentados em `tests/Unit/AreaDoClienteSessaoTest.php`, reaproveitando o harness que já existe lá (`MineTestavel`, `SessaoDeTeste`, `EntradaDeTeste`, `SaidaDeTeste`).

Para o painel, montar um `Login` testável **com classe anônima** (`new class() extends Login { ... }`), nunca com classe nomeada: o PHPUnit carrega todos os arquivos de teste no mesmo processo e um nome repetido (`SessaoDeTeste`, `SaidaDeTeste`...) dá `Cannot redeclare class` — já aconteceu nesta feature. Injetar: `session`, `input`, `output`, `security`, `form_validation`, `limite_login` (real: `new Limite_login(['db' => $this->db, 'segredo' => 's', 'relogio' => fn () => $agora, 'auditar' => fn () => null])` sobre a tabela SQLite `login_attempts`, como em `AreaDoClienteSessaoTest::setUp()`), `Mapos_model` e `mapos_model` (o mesmo objeto; pode ser classe anônima estendendo `Mapos_model` com `check_credentials`, `grupoAtivo` e `grupoExige2fa` sobrescritos) e `load` (`__call` que devolve null). Sobrescrever `encerrar()` e `irPara()` para lançar uma exceção e capturá-la no teste. `require_once APPPATH . 'helpers/general_helper.php'` para `senhaConfere()`/`contaExpirada()`; stubs `site_url`/`log_info` com `function_exists` como no arquivo vizinho.

```php
public function testSenhaErradaResponde401ComMensagemGenerica(): void
{
    $login = $this->login(['email' => 'ana@x.com', 'senha' => 'errada']);

    $this->chamar($login, 'verificarLogin');

    $this->assertSame(401, $login->output->status);
    $this->assertSame('application/json', $login->output->tipo);
    $corpo = json_decode($login->output->corpo, true);
    $this->assertSame(['result' => false, 'message' => 'Os dados de acesso estão incorretos.', 'OUTRO' => 'hash-novo'], $corpo);
    $this->assertSame(2, (int) $this->db->select_sum('falhas')->get('login_attempts')->row()->falhas, 'Uma falha para o e-mail e uma para o IP.');
}

public function testGetEmLogoutResponde405SemEncerrarASessao(): void
{
    $login = $this->login([], 'GET', ['logado' => true, 'id_admin' => 1]);

    $this->chamar($login, 'sair');

    $this->assertSame(405, $login->output->status);
    $this->assertContains('Allow: POST', $login->output->cabecalhos);
    $this->assertFalse($login->session->destruida);
    $this->assertTrue($login->session->dados['logado']);
}
```

Cobrir os demais casos das tabelas com os nomes de teste dos critérios. No `AreaDoClienteSessaoTest.php`, acrescentar aos dublês: `SaidaDeTeste::set_header(string $cabecalho)` (acumula em `public array $cabecalhos = []`, devolve `$this`, zera em `limpar()`), `SessaoDeTeste::sess_destroy()` (marca `public bool $destruida = true` e esvazia `dados`) e um 4º parâmetro opcional `array $query = []` em `EntradaDeTeste` lido por `get($chave)`. Os testes existentes do arquivo continuam passando sem mudança de asserção.

**Refatoração:** depois do green, as quatro saídas do `Mine::login` e as cinco do `Login::verificarLogin` passam todas pelo método de resposta único de cada controller — nenhum `echo json_encode` nem `exit()` sobra nesses métodos.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/Login.php` | Modificar | `verificarLogin()` por status via `responderLogin()`; `sair()` só POST (GET → 405); `index()` com `sucesso` e descarte do `dois_fatores`; seams `irPara()`/`encerrar()`; remove `falha()` |
| `application/controllers/Mine.php` | Modificar | `login()` por status via `responderLogin()`; `sair()` só POST; `index()` com `sucesso` de `?saiu=1` |
| `tests/Unit/LoginPorStatusTest.php` | Criar | Login do painel: 422/401/403/429/200, nome do campo CSRF, sair 405/POST, index, sem Deprecated |
| `tests/Unit/AreaDoClienteSessaoTest.php` | Modificar | Dublês `set_header`/`sess_destroy`/query; testes do login do cliente por status e do `mine/sair` |

### Detalhamento Técnico

1. **`Login.php` — seams:** acrescentar `protected function irPara(string $url) { redirect($url); }` e `protected function encerrar() { exit; }` (mesmo formato de `Mine`).
2. **`Login.php` — `responderLogin()`** (privado; é o contrato que o front consome):

   ```php
   /**
    * Resposta do login em JSON com status HTTP (RF-29). Fora do 200 leva o
    * token CSRF novo no campo de nome configurável (APP_CSRF_TOKEN_NAME).
    */
   private function responderLogin(int $status, array $corpo, ?int $retryAfter = null): void
   {
       if ($retryAfter !== null) {
           $corpo['retry_after'] = $retryAfter;
           $this->output->set_header('Retry-After: ' . $retryAfter);
       }

       if ($status !== 200) {
           $corpo[$this->security->get_csrf_token_name()] = $this->security->get_csrf_hash();
       }

       $this->output
           ->set_status_header($status)
           ->set_content_type('application/json', 'utf-8')
           ->set_output(json_encode($corpo));
       $this->output->_display();
       $this->encerrar();
   }
   ```

3. **`Login::verificarLogin()`**, na ordem atual (manter os `header('Access-Control-...')` do início):
   - `form_validation->run() == false` → `responderLogin(422, ['result' => false, 'message' => 'Confira os campos destacados.', 'erros' => $this->form_validation->error_array()])`. Não conta falha.
   - `$email = (string) $this->input->post('email')`, `$password = (string) $this->input->post('senha')`.
   - `bloqueado('usuario', ...)` → `responderLogin(429, ['result' => false, 'message' => Limite_login::MENSAGEM], max(1, $this->limite_login->segundosRestantes('usuario', $email, $ip)))`.
   - `$senhaConfere = senhaConfere(...)` **antes** de `if (! $user || ! $senhaConfere)` (o teste de fonte do bcrypt exige essa ordem) → `registrarFalha` + `responderLogin(401, ['result' => false, 'message' => 'Os dados de acesso estão incorretos.'])`.
   - `motivoDeBloqueio($user) !== null` → `responderLogin(403, ['result' => false, 'message' => $bloqueio])`.
   - 2FA: manter `sess_regenerate(true)` + `set_userdata('dois_fatores', ...)` e responder `$this->responderLogin(200, ['result' => true, 'destino' => site_url('login/doisFatores')]);` — escrever o literal `'destino' => site_url('login/doisFatores')` exatamente assim (o `DoisFatoresTest` procura esse texto).
   - sucesso: `registrarSucesso` + `iniciarSessao($user)` + `responderLogin(200, ['result' => true])`.
   - Remover `falha()` e todo `echo json_encode`/`exit()` do método.
4. **`Login::sair()`**:

   ```php
   public function sair()
   {
       // Logout só por POST com CSRF (RF-27): um <img src=".../login/sair"> de
       // outro site não derruba a sessão.
       if ($this->input->method() !== 'post') {
           $this->output->set_status_header(405)->set_header('Allow: POST');

           return;
       }

       $this->session->sess_destroy();

       return $this->irPara(site_url('login?saiu=1'));
   }
   ```

   O "Você saiu." vai pela query string e não por flashdata: depois do `sess_destroy()` a flashdata não persiste.
5. **`Login::index()`**: `$this->session->unset_userdata('dois_fatores');` antes do `load->view`, e passar `'sucesso' => $this->input->get('saiu') === '1' ? 'Você saiu.' : null` junto do `erro`.
6. **`Mine.php` — `responderLogin()`**: mesmo corpo do item 2 (privado no `Mine`; o `responderJson()` existente continua como está para o 401/403 de AJAX). `Mine::login()` na ordem atual: 422 (`'erros' => $this->form_validation->error_array()`), 429 com `segundosRestantes('cliente', ...)`, 401 genérico, 200 `{result:true}` depois de `registrarSucesso`, `sess_regenerate`, `set_userdata` e auditoria (como hoje). Manter `senhaConfere(` antes do `if (! $cliente`.
7. **`Mine::sair()`**: mesmo formato do item 4, com `return $this->irPara('mine?saiu=1');`.
8. **`Mine::index()`**: `'sucesso' => $this->session->flashdata('success') ?: ($this->input->get('saiu') === '1' ? 'Você saiu.' : null)`.
9. Views ficam fora desta task (a de frontend troca os "Sair" e renderiza o `$sucesso` no login do painel); aqui só controllers e testes.

**Contrato com camadas adjacentes:**

```
Produz para: assets/js/modules/login/formulario.js (via lib/http.js)
  - Sucesso: 200 {result: true} | 200 {result: true, destino: "<url>"} (só painel com 2FA)
  - Validação: 422 {result:false, message:"Confira os campos destacados.", erros:{email?: string, senha?: string}, <csrf_name>: hash}
  - Credenciais: 401 {result:false, message:"Os dados de acesso estão incorretos.", <csrf_name>: hash}
  - Conta (só painel): 403 {result:false, message:<motivo>, <csrf_name>: hash}
  - Bloqueio: 429 {result:false, message: Limite_login::MENSAGEM, retry_after:<int s>, <csrf_name>: hash} + Retry-After
  - CSRF recusado pelo framework: 403 HTML (sem JSON) — o front distingue pelo corpo não-objeto
Produz para: views mapos/login e conecte/login
  - $sucesso = "Você saiu." após POST em sair (query ?saiu=1)
```

**Contrato HTTP:**

```
POST /login/verificarLogin        (também POST /mine/login)
Content-Type: application/x-www-form-urlencoded
X-Requested-With: XMLHttpRequest

Request body:
  email=ana@x.com            // obrigatório, e-mail válido
  senha=Senha123!            // obrigatório
  <APP_CSRF_TOKEN_NAME>=<hash do cookie>   // obrigatório (CSRF do framework)

Response 200: {"result": true}  |  {"result": true, "destino": "http://host/index.php/login/doisFatores"}
Response 422: {"result": false, "message": "Confira os campos destacados.", "erros": {"email": "O campo E-mail deve conter um endereço de e-mail válido."}, "<nome>": "<hash>"}
Response 401: {"result": false, "message": "Os dados de acesso estão incorretos.", "<nome>": "<hash>"}
Response 403: {"result": false, "message": "A conta do usuário está expirada, ...", "<nome>": "<hash>"}   // só painel
Response 429: {"result": false, "message": "Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.", "retry_after": 60, "<nome>": "<hash>"}  + Retry-After: 60

POST /login/sair (CSRF)  → 302 /index.php/login?saiu=1
POST /mine/sair  (CSRF)  → 302 /index.php/mine?saiu=1
GET  /login/sair, GET /mine/sair → 405, Allow: POST, sessão intacta
```
