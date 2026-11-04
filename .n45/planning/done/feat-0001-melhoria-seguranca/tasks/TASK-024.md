---
checkpoint: null
complexity: G
created_at: "2026-11-03 22:59:29"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testLoginGuardaAVersaoDaSessao tests/Unit/AreaDoClienteSessaoTest.php
      text: Login do cliente grava cliente_sessao_versao com a sessao_versao do banco
    - done: true
      test: application/vendor/bin/phpunit --filter testSessaoComVersaoAntigaEEncerrada tests/Unit/AreaDoClienteSessaoTest.php
      text: Sessão com versão diferente da do banco é encerrada com 'A senha desta conta foi alterada. Entre novamente.' e redirect mine
    - done: true
      test: application/vendor/bin/phpunit --filter 'testClienteExcluidoEncerraASessao|testFalhaNaRevalidacaoEncerraASessao' tests/Unit/AreaDoClienteSessaoTest.php
      text: Cliente excluído ou falha na consulta encerra a sessão com 'Sua sessão expirou. Entre novamente.' (fail-closed, com log 'cliente sessao falha id=')
    - done: true
      test: application/vendor/bin/phpunit --filter testTrocaPendenteLevaParaDefinirSenha tests/Unit/AreaDoClienteSessaoTest.php
      text: Com senha_trocar 1, painel, os e conta redirecionam para mine/definirSenha
    - done: true
      test: application/vendor/bin/phpunit --filter testDefinirSenhaSoComTrocaPendente tests/Unit/AreaDoClienteSessaoTest.php
      text: GET definirSenha com troca pendente renderiza conecte/definir_senha e sem troca pendente vai para mine/painel
    - done: true
      test: application/vendor/bin/phpunit --filter testDefinirSenhaValidaLiberaAArea tests/Unit/AreaDoClienteSessaoTest.php
      text: POST definirSenha válido zera senha_trocar, atualiza cliente_sessao_versao para a do banco, mantém a sessão válida, flash 'Senha definida.' e vai para mine/painel
    - done: true
      test: application/vendor/bin/phpunit --filter testDefinirSenhaComNovaInvalidaNaoConfereAAtual tests/Unit/AreaDoClienteSessaoTest.php
      text: POST definirSenha com senha curta ou confirmação diferente devolve o erro do campo sem contar falha de confirmação
    - done: true
      test: application/vendor/bin/phpunit --filter testDefinirSenhaConfereAAtualComLimite tests/Unit/AreaDoClienteSessaoTest.php
      text: POST definirSenha com senha atual errada devolve erros.senha_atual e conta 1 falha; nova igual à atual devolve erros.senha; bloqueado devolve _geral Limite_login::MENSAGEM
    - done: true
      test: application/vendor/bin/phpunit --filter testEditarDadosComSenhaDerrubaOutrasSessoes tests/Unit/AreaDoClienteSessaoTest.php
      text: Troca por editarDados passa por registrarTrocaDeSenha (versões +1, refresh cliente revogado) e a sessão atual continua válida; sem senha as versões não mudam
    - done: true
      test: application/vendor/bin/phpunit --filter testTokenDeResetSoValeUmaVez tests/Unit/AreaDoClienteSessaoTest.php
      text: 'Duas chamadas a senhaSalvar com o mesmo token: só a primeira troca a senha e a segunda recebe ''Token inválido ou expirado. Solicite uma nova recuperação de senha.'''
    - done: true
      test: grep -qE "where\('token_utilizado', 0\)" application/models/Conecte_model.php
      text: Conecte_model::consumirTokenDeReset consome com UPDATE condicionado a token_utilizado = 0
    - done: true
      test: application/vendor/bin/phpunit --filter 'testAjaxSemSessaoNaAreaDoClienteResponde401|testAjaxComTrocaPendenteResponde403' tests/Unit/AreaDoClienteSessaoTest.php
      text: AJAX a Mine sem sessão responde 401 JSON com redirect site_url('mine') e flash 'Sua sessão expirou. Entre novamente.'; com troca pendente responde 403 com redirect mine/definirSenha
    - done: true
      test: application/vendor/bin/phpunit --filter 'testAjaxSemSessaoNoPainelResponde401|testSemAjaxContinuaORedirect' tests/Unit/SessaoExpiradaAjaxTest.php
      text: AJAX ao painel sem sessão responde 401 JSON {result:false, message:'Sessão expirada', redirect:site_url('login')} e grava o flash; sem AJAX continua o redirect para login
    - done: true
      test: application/vendor/bin/phpunit --filter testAjaxComSessaoEncerradaResponde401ComMotivo tests/Unit/SessaoExpiradaAjaxTest.php
      text: AJAX ao painel com sessão encerrada pela revalidação responde 401 JSON e o flash traz o motivo
    - done: true
      test: '! grep -nE "edit\(''clientes'', \[''senha''|resetSenhas_model->edit" application/controllers/Mine.php'
      text: Mine não grava mais a senha por edit() nem marca o token depois da troca
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/AreaDoClienteSessaoTest.php tests/Unit/SessaoExpiradaAjaxTest.php tests/Unit/AreaDoClienteTest.php tests/Unit/SessaoELimiteTest.php tests/Unit/DefinirSenhaViewTest.php tests/Unit/ApiAutenticacaoTest.php tests/Unit/BancoDesatualizadoTest.php
      text: Suítes da área do cliente e de sessão passam
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/controllers/Mine.php application/models/Conecte_model.php application/core/MY_Controller.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-009
    - TASK-016
diff_base: 8117395d266512aadab96fd4c04ebdb8df2c0403
diff_tip: f8091ee6c62d7ff8832497b1587af87cf4eb239a
files:
    - application/controllers/Mine.php
    - application/models/Conecte_model.php
    - application/core/MY_Controller.php
    - tests/Unit/AreaDoClienteSessaoTest.php
    - tests/Unit/SessaoExpiradaAjaxTest.php
id: TASK-024
linter: composer analyse
n45_version: 1.1.0
outcome: |
    ## Outcome TASK-024

    - `Conecte_model::estadoDaSessao(int $idCliente): ?array` retorna `['sessao_versao' => int, 'senha_trocar' => int]`, ou `null` (cliente inexistente ou consulta falhou, com log `cliente sessao falha id=<id>`).
    - `Conecte_model::consumirTokenDeReset(int $idToken): bool` faz `UPDATE ... WHERE id = ? AND token_utilizado = 0`; `true` só com `affected_rows() === 1`.
    - `Mine`: sessão guarda `cliente_sessao_versao`. `clienteLogado(bool $permitirTrocaPendente = false)` revalida no banco a cada requisição (fail-closed). Sessão sem login, cliente excluído ou falha de consulta encerra com 'Sua sessão expirou. Entre novamente.'. Versão divergente encerra com 'A senha desta conta foi alterada. Entre novamente.'. Redirect para `mine`.
    - `Mine::definirSenha()` (GET/POST) para `senha_trocar = 1`. Erros de campo não gastam tentativa do escopo `confirmacao`. Sucesso chama `registrarTrocaDeSenha(id, hash, false)`, atualiza a versão da sessão, flash 'Senha definida.' e redireciona para `mine/painel`. Com `senha_trocar = 1`, painel/os/conta redirecionam para `mine/definirSenha`.
    - `editarDados` com senha e `senhaSalvar` passam por `registrarTrocaDeSenha`. `senhaSalvar` consome o token antes da troca; a segunda chamada recebe 'Token inválido ou expirado. Solicite uma nova recuperação de senha.'.
    - AJAX em `Mine`: 401 JSON `{result:false,message:'Sessão expirada',redirect:site_url('mine')}` sem sessão; 403 JSON com `redirect` `mine/definirSenha` com troca pendente. Painel (`MY_Controller::sessaoExpirada`): 401 JSON com `redirect` `site_url('login')` e flash com o motivo; não AJAX continua redirect para `login`.
    - Seams protegidos `irPara()`/`encerrar()` em `Mine` e `irPara()` em `MY_Controller`.
persona: backend
phase: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/AreaDoClienteSessaoTest.php tests/Unit/SessaoExpiradaAjaxTest.php tests/Unit/AreaDoClienteTest.php tests/Unit/SessaoELimiteTest.php tests/Unit/DefinirSenhaViewTest.php tests/Unit/BancoDesatualizadoTest.php
title: 'Área do cliente: sessão revalidada, troca obrigatória, reset atômico e 401 em AJAX'
updated_at: "2026-11-04 00:54:14"
---
## Contexto

A área do cliente (`application/controllers/Mine.php`, estende `CI_Controller`) confia só no que está na sessão: `clienteLogado()` confere `conectado` e `cliente_id` e nunca olha o banco, então um cliente excluído ou que trocou a senha em outro lugar continua navegando com a sessão antiga. Além disso:

- não existe o primeiro acesso: o cliente criado pelo operador recebe `senha_trocar = 1` (TASK-019/TASK-022), mas nada o obriga a trocar a senha;
- a troca de senha por "Meus dados" (`editarDados`) e pela recuperação (`senhaSalvar`) é um `edit()` simples: não derruba outras sessões nem os refresh tokens da API do cliente;
- `senhaSalvar` confere o token de recuperação e **depois** marca como usado (`resetSenhas_model->edit(... token_utilizado ...)`): duas requisições simultâneas com o mesmo token trocam a senha duas vezes;
- requisição AJAX sem sessão válida (painel em `MY_Controller` e área do cliente) recebe um redirect 302 para o HTML do login, que o `fetch` segue e trata como sucesso.

Contratos já entregues que esta task consome:

- `Clientes_model::registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool` (TASK-009): numa transação grava `senha` e `senha_trocar`, incrementa `sessao_versao` e `token_versao` e revoga os refresh `tipo='cliente'`; `false` = nada mudou.
- Colunas `clientes.senha_trocar` e `clientes.sessao_versao` (TASK-002, padrão 0).
- View `application/views/conecte/definir_senha.php` (TASK-016): renderizar com `$this->load->view('conecte/definir_senha', ['erros' => array<string,string>])`; chaves `senha_atual`, `senha`, `senha_confirmacao`, `_geral` (alert danger). O form faz `POST mine/definirSenha` com CSRF e os campos `senha_atual`, `senha` (8..72) e `senha_confirmacao`; tem também o form "Sair" (`POST mine/sair`). A view fica fora da moldura `conecte/template`.
- `conecteErroDaConfirmacao($post): ?string` (conecte_helper) — erro da confirmação de senha.
- `Limite_login::confirmarSenha(string $email, string $ip, string $senha, string $hash): string` (`SENHA_OK|SENHA_ERRADA|SENHA_BLOQUEADA`, escopo `confirmacao`) e `Limite_login::MENSAGEM`.
- `MY_Controller` já tem o seam `encerrar()` (usado no 503 de banco desatualizado em AJAX) e `validarSessaoDoUsuario()`/`motivoDeEncerramento()` para o painel.
- O módulo `assets/js/lib/http.js` (TASK-007) já trata **401 JSON com `redirect`** como sessão expirada (toast + navegação), só para destino da mesma origem.

Fora do escopo desta task (Phase 4): login por status HTTP, logout só por POST, limite diário de OS. `Mine::sair()` não muda aqui.

Regras: a sessão do cliente guarda a versão em `cliente_sessao_versao`; sessão sem essa chave (aberta antes desta versão) vale como versão 0 (o padrão da coluna). `redirect` do 401 é sempre caminho interno fixo (`site_url('login')` no painel, `site_url('mine')` na área do cliente), nunca derivado de entrada. Erro de consulta ao revalidar = encerra a sessão (fail-closed). `Conecte_model::edit()` e `Clientes_model::edit()` continuam chamando `incrementarVersao` (há teste de código que procura a chamada).

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `Mine::login` com credenciais certas (cliente com `sessao_versao = 4`) | sessão ganha `cliente_sessao_versao = 4` além das chaves atuais |
| `/mine/painel` com sessão `cliente_sessao_versao = 4` e banco `sessao_versao = 4`, `senha_trocar = 0` | tela normal |
| `/mine/painel` com banco `sessao_versao = 5` (senha trocada em outro lugar) | sessão do cliente encerrada (`unset` das chaves do cliente), flashdata `error` = `A senha desta conta foi alterada. Entre novamente.`, redirect `mine` |
| `/mine/painel` com o cliente excluído do banco | sessão encerrada, flashdata `error` = `Sua sessão expirou. Entre novamente.`, redirect `mine` |
| `/mine/painel` com a consulta de revalidação falhando (query `false`) | sessão encerrada (fail-closed), mesmo flash, log `error` `cliente sessao falha id=<id>` |
| `/mine/painel`, `/mine/os`, `/mine/conta` com `senha_trocar = 1` | redirect `mine/definirSenha` |
| `GET /mine/definirSenha` com `senha_trocar = 1` | renderiza `conecte/definir_senha` com `erros = []` |
| `GET /mine/definirSenha` com `senha_trocar = 0` | redirect `mine/painel` |
| `POST /mine/definirSenha` `{senha_atual:<certa>, senha:'NovaSenha2026', senha_confirmacao:'NovaSenha2026'}` | `registrarTrocaDeSenha(id, <hash>, false)`; `senha_trocar = 0`; sessão atual recebe a nova `cliente_sessao_versao` (a do banco após a troca) e continua válida; flashdata `success` = `Senha definida.`; redirect `mine/painel` |
| `POST /mine/definirSenha` com `senha: 'curta'` | tela com `erros.senha = 'A senha deve ter de 8 a 72 caracteres.'`; a senha atual não é conferida (nenhuma falha contada) |
| `POST /mine/definirSenha` com confirmação diferente | `erros.senha_confirmacao` = mensagem de `conecteErroDaConfirmacao()` |
| `POST /mine/definirSenha` com senha atual errada | `erros.senha_atual = 'A senha atual não confere.'`; 1 falha no escopo `confirmacao` |
| `POST /mine/definirSenha` com a nova igual à atual | `erros.senha = 'A nova senha precisa ser diferente da atual.'` |
| `POST /mine/definirSenha` com o escopo `confirmacao` bloqueado | `erros._geral = Limite_login::MENSAGEM` |
| `POST /mine/definirSenha` com `registrarTrocaDeSenha` falso | `erros._geral = 'Não foi possível salvar. Tente novamente.'` |
| `POST /mine/editarDados` com senha nova (e senha atual certa, regra atual) | `registrarTrocaDeSenha(id, <hash>, false)`: `sessao_versao`/`token_versao` +1, refresh `tipo='cliente'` revogados; a sessão atual recebe a nova versão e segue válida; demais campos gravados por `edit()` **sem** `senha` |
| `POST /mine/editarDados` sem senha | `sessao_versao` inalterado |
| `POST /mine/senhaSalvar` com token válido | `UPDATE resets_de_senha SET token_utilizado = 1 WHERE id = ? AND token_utilizado = 0` com `affected_rows() === 1` **antes** de `registrarTrocaDeSenha(id, <hash>, false)` |
| duas chamadas a `senhaSalvar` com o mesmo token | só a primeira troca a senha; a segunda: flashdata `error` = `Token inválido ou expirado. Solicite uma nova recuperação de senha.` e a senha gravada é a da primeira |
| `senhaSalvar` com o token consumido e `registrarTrocaDeSenha` falso | flashdata `error` = `Não foi possível salvar a senha. Solicite uma nova recuperação de senha.`; redirect `mine` |
| AJAX (`X-Requested-With: XMLHttpRequest`) a uma rota de `Mine` sem sessão | 401 `application/json` `{result:false, message:'Sessão expirada', redirect:'<site_url("mine")>'}`; flashdata `error` = `Sua sessão expirou. Entre novamente.`; nada da rota executa |
| AJAX a uma rota de `Mine` com `senha_trocar = 1` | 403 JSON `{result:false, message:'Defina uma nova senha antes de continuar.', redirect:'<site_url("mine/definirSenha")>'}` |
| AJAX a uma rota do painel (`MY_Controller`) sem sessão | 401 JSON `{result:false, message:'Sessão expirada', redirect:'<site_url("login")>'}`; flashdata `error` = `Sua sessão expirou. Entre novamente.` |
| AJAX ao painel com sessão encerrada pela revalidação (ex.: conta desativada) | 401 JSON igual; flashdata `error` = o motivo de `motivoDeEncerramento()` |
| requisição **não** AJAX ao painel sem sessão | redirect `login` como hoje |

## TDD

Suítes novas:

- `tests/Unit/AreaDoClienteSessaoTest.php` — `Mine` criado por `makeInstance(Mine::class)` (sem construtor) com `session` (stub em memória com `userdata`/`set_userdata`/`unset_userdata`/`set_flashdata`/`sess_regenerate`), `input` (stub com `method()`, `post()`, `ip_address()`, `is_ajax_request()`), `output` (stub que guarda status, headers e corpo), `Conecte_model`/`clientes_model` reais sobre SQLite, `limite_login = new Limite_login(['db' => $this->db, 'segredo' => 'segredo-de-teste', 'auditar' => fn () => null])`, `load` com `__call` nulo. Para não sair do processo, isole as saídas em métodos `protected` do `Mine` sobrescritos no teste: `irPara(string $rota)` (no lugar de `redirect()`), `encerrar()` (no lugar de `exit`) e `tela`/`load->view` capturando a view e os dados.
- `tests/Unit/SessaoExpiradaAjaxTest.php` — `MY_Controller` exercitado por uma subclasse mínima com `makeInstance` e os mesmos stubs; o seam `encerrar()` já existe; o redirect não-AJAX também passa por um método `protected` sobrescrevível.

```php
public function testSessaoComVersaoAntigaEEncerrada(): void
{
    $this->db->query("INSERT INTO clientes (idClientes, nomeCliente, email, senha, senha_trocar, sessao_versao) VALUES (5, 'Ana', 'ana@x.com', 'h', 0, 5)");
    $mine = $this->mine(['conectado' => true, 'cliente_id' => 5, 'cliente_sessao_versao' => 4]);

    $mine->painel();

    $this->assertSame('mine', $mine->destino);
    $this->assertSame('A senha desta conta foi alterada. Entre novamente.', $mine->session->flash['error']);
    $this->assertNull($mine->session->dados['cliente_id'] ?? null);
}
```

Cobrir os demais casos da tabela (nomes nos critérios). Falha de banco simulada com trigger `RAISE(ABORT)` + `db_debug = false` como em `tests/Unit/OsVendasTransacaoTest.php::falhaAntesDe()`.

**Refatoração:** a montagem do corpo do 401 de sessão expirada é a mesma no painel e na área do cliente — um método por controller, sem duplicar o array literal em vários pontos de cada um.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/Mine.php` | Modificar | `clienteLogado(bool $permitirTrocaPendente = false)` com revalidação, troca pendente e 401/403 AJAX; `definirSenha()`; versão na sessão no login; `editarDados` e `senhaSalvar` por `registrarTrocaDeSenha`; reset atômico; seams `irPara()`/`encerrar()` |
| `application/models/Conecte_model.php` | Modificar | `estadoDaSessao(int $idCliente): ?array` e `consumirTokenDeReset(int $idToken): bool` |
| `application/core/MY_Controller.php` | Modificar | 401 JSON em AJAX sem sessão ou com sessão encerrada; redirect por método sobrescrevível |
| `tests/Unit/AreaDoClienteSessaoTest.php` | Criar | Suíte da área do cliente |
| `tests/Unit/SessaoExpiradaAjaxTest.php` | Criar | Suíte do painel |

### Detalhamento Técnico

1. **`Conecte_model::estadoDaSessao(int $idCliente): ?array`** → `['sessao_versao' => int, 'senha_trocar' => int]` lendo `SELECT sessao_versao, senha_trocar FROM clientes WHERE idClientes = ? LIMIT 1`; cliente inexistente → `null`; query falha (`get()` devolve `false`) → `log_message('error', sprintf('cliente sessao falha id=%d', $idCliente))` e `null`.
2. **`Conecte_model::consumirTokenDeReset(int $idToken): bool`** → `$this->db->where('id', $idToken)->where('token_utilizado', 0)->update('resets_de_senha', ['token_utilizado' => 1]); return $this->db->affected_rows() === 1;`.
3. **`Mine::login`**: ao montar a sessão, `'cliente_sessao_versao' => (int) ($cliente->sessao_versao ?? 0)`. Nada mais muda no login.
4. **`Mine::clienteLogado(bool $permitirTrocaPendente = false): int`**:
   1. sem `session_id()`, `conectado` ou `cliente_id` → `encerrarSessaoDoCliente('Sua sessão expirou. Entre novamente.')`;
   2. `$estado = $this->Conecte_model->estadoDaSessao($id)`; `null` → mesmo encerramento; `$estado['sessao_versao'] !== (int) userdata('cliente_sessao_versao')` → encerramento com `A senha desta conta foi alterada. Entre novamente.`;
   3. `$estado['senha_trocar'] === 1 && ! $permitirTrocaPendente` → AJAX: 403 JSON `{result:false, message:'Defina uma nova senha antes de continuar.', redirect: site_url('mine/definirSenha')}` + `encerrar()`; senão `irPara('mine/definirSenha')`;
   4. devolve o id.
   `encerrarSessaoDoCliente(string $motivo)`: `unset_userdata(['nome','cliente_id','email','conectado','isCliente','cliente_sessao_versao'])`, `sess_regenerate(true)`, `set_flashdata('error', $motivo)`; AJAX → 401 JSON `{result:false, message:'Sessão expirada', redirect: site_url('mine')}` + `encerrar()`; senão `irPara('mine')`.
   `irPara(string $rota)` (protected) = `redirect($rota)`; `encerrar()` (protected) = `exit`.
5. **`Mine::definirSenha()`** (público, rota `mine/definirSenha`): `$id = $this->clienteLogado(true)`; sem troca pendente → `irPara('mine/painel')`; GET → view com `erros = []`; POST, nesta ordem:
   1. `senha` com `mb_strlen` fora de 8..72 → `erros.senha = 'A senha deve ter de 8 a 72 caracteres.'`; `conecteErroDaConfirmacao($this->input->post())` → `erros.senha_confirmacao`; havendo erro, re-renderiza **sem** conferir a atual;
   2. `confirmarSenha($cliente->email, $ip, (string) post('senha_atual'), $cliente->senha)`: `SENHA_BLOQUEADA` → `erros._geral = Limite_login::MENSAGEM`; `SENHA_ERRADA` → `erros.senha_atual = 'A senha atual não confere.'`;
   3. `password_verify($nova, $cliente->senha)` → `erros.senha = 'A nova senha precisa ser diferente da atual.'`;
   4. `registrarTrocaDeSenha($id, password_hash($nova, PASSWORD_DEFAULT), false)` falso → `erros._geral = 'Não foi possível salvar. Tente novamente.'`;
   5. sucesso: `set_userdata('cliente_sessao_versao', <sessao_versao lida do banco após a troca>)`, `log_info('Cliente definiu a nova senha.')`, flashdata `success` `Senha definida.`, `irPara('mine/painel')`.
   Carregar `clientes_model` e a library `Limite_login`.
6. **`Mine::editarDados()`**: mantém a regra atual da senha atual; com `$dados['senha']`, tira do array, grava o resto com `Conecte_model::edit()` e chama `registrarTrocaDeSenha($idCliente, $hash, false)`; falso → `erros._geral = 'Não foi possível salvar. Tente de novo.'`; verdadeiro → atualiza `cliente_sessao_versao` com a versão do banco.
7. **`Mine::senhaSalvar()`**: depois das validações de campo, `if (! $this->Conecte_model->consumirTokenDeReset((int) $token->id))` → flash `Token inválido ou expirado. Solicite uma nova recuperação de senha.` e `irPara('mine')`; depois `registrarTrocaDeSenha((int) $cliente->idClientes, $hash, false)`; falso → flash `Não foi possível salvar a senha. Solicite uma nova recuperação de senha.` e `irPara('mine')`. Remove o `Conecte_model->edit(... senha ...)` e o `resetSenhas_model->edit(... token_utilizado ...)`.
8. **`MY_Controller::__construct()`**: sem sessão → AJAX: `set_flashdata('error', 'Sua sessão expirou. Entre novamente.')`, 401 JSON `{result:false, message:'Sessão expirada', redirect: site_url('login')}`, `_display()`, `encerrar()`; não AJAX: redirect `login` como hoje. **`validarSessaoDoUsuario()`** com motivo: além do que já faz (unset, regenerate, flash do motivo), AJAX responde o mesmo 401 JSON e `encerrar()`. Use `$this->output->set_status_header(401)->set_content_type('application/json', 'utf-8')->set_output(json_encode(...))` como no 503 existente.

**Contrato HTTP** (comportamento global):

```
Qualquer rota do painel ou de Mine, com header X-Requested-With: XMLHttpRequest, sem sessão válida:
401 Content-Type: application/json
{"result": false, "message": "Sessão expirada", "redirect": "http://<host>/index.php/login"}    // Mine: ".../index.php/mine"

Rota de Mine via AJAX com senha_trocar = 1:
403 {"result": false, "message": "Defina uma nova senha antes de continuar.", "redirect": "http://<host>/index.php/mine/definirSenha"}

GET|POST /mine/definirSenha
POST (CSRF): senha_atual, senha (8..72), senha_confirmacao
302 mine/painel + flash success "Senha definida."  |  200 tela com erros por campo
```

**Contrato com camadas adjacentes:**

```
Produz para: Mine (todas as rotas autenticadas)
  - Conecte_model::estadoDaSessao(int): ?array{sessao_versao:int, senha_trocar:int}; null = encerrar (inexistente ou falha, esta logada)
  - Conecte_model::consumirTokenDeReset(int): bool; false = token já usado → "Token inválido ou expirado."
Consome de: Clientes_model::registrarTrocaDeSenha(int, string, bool): bool (TASK-009); false → erro '_geral', senha não mudou
Consumido por: assets/js/lib/http.js (TASK-007) — 401 JSON com redirect = sessão expirada
```
