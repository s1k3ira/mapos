---
checkpoint: null
complexity: M
created_at: "2026-11-03 22:56:34"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testPostSemSenhaDevolveSenhaInicialUmaVez tests/Unit/ApiClientesTest.php
      text: POST sem senha responde 201 com senha_inicial de 16 caracteres do alfabeto, email_enviado true, sem senha no result, Cache-Control no-store, senha_trocar 1, documento não é a senha e a senha não aparece em log
    - done: true
      test: application/vendor/bin/phpunit --filter testPostComFalhaNoEnvioInformaEmailEnviadoFalse tests/Unit/ApiClientesTest.php
      text: POST sem senha com falha no envio responde 201 com email_enviado false
    - done: true
      test: application/vendor/bin/phpunit --filter testPostComSenhaInformadaNaoDevolveSenha tests/Unit/ApiClientesTest.php
      text: POST com senha informada responde 201 sem senha_inicial e sem email_enviado, grava senha_trocar 1 e não chama o envio
    - done: true
      test: application/vendor/bin/phpunit --filter testSenhaInicialNaoPassaPelaFila tests/Unit/ApiClientesTest.php
      text: POST com senha gerada não cria linha em email_queue
    - done: true
      test: application/vendor/bin/phpunit --filter testPostIgnoraCamposForaDaAllowList tests/Unit/ApiClientesTest.php
      text: POST ignora senha_trocar, token_versao, sessao_versao e asaas_id do corpo
    - done: true
      test: application/vendor/bin/phpunit --filter testGetDoClienteNaoTrazSenhaInicial tests/Unit/ApiClientesTest.php
      text: GET do cliente criado não traz senha_inicial nem senha
    - done: true
      test: '! grep -nE "preg_replace\([^)]*documento.*\?:|\?: preg_replace" application/controllers/api/v1/ClientesController.php'
      text: O documento não é mais usado como senha na API
    - done: true
      test: application/vendor/bin/phpunit --filter testSemPermissaoResponde403 tests/Unit/ApiClientesTest.php
      text: Sem vCliente o GET responde 403 e sem dCliente o DELETE responde 403 sem apagar
    - done: true
      test: '! grep -n ''HTTP_UNAUTHORIZED'' application/controllers/api/v1/ClientesController.php'
      text: ClientesController não responde mais 401 por falta de permissão
    - done: true
      test: application/vendor/bin/phpunit --filter testPaginacaoDeClientes tests/Unit/ApiClientesTest.php
      text: perPage=1000000 devolve 100 clientes, perPage=0 responde 422 e page=1&perPage=5 chega ao model com offset 5
    - done: true
      test: application/vendor/bin/phpunit --filter testPutComSenhaDerrubaSessoesEExigeTroca tests/Unit/ApiClientesTest.php
      text: 'PUT com senha passa por registrarTrocaDeSenha: senha_trocar 1, sessao_versao e token_versao +1 e refresh tipo cliente revogado'
    - done: true
      test: application/vendor/bin/phpunit --filter testPutComFalhaNaTrocaResponde500 tests/Unit/ApiClientesTest.php
      text: PUT com registrarTrocaDeSenha falso responde 500 'Não foi possível alterar a senha do cliente.'
    - done: true
      test: application/vendor/bin/phpunit --filter testPutSemSenhaNaoMexeNasVersoes tests/Unit/ApiClientesTest.php
      text: PUT sem senha mantém as versões e PUT com senha_trocar/token_versao no corpo não altera esses campos
    - done: true
      test: application/vendor/bin/phpunit --filter testClienteInexistenteResponde404 tests/Unit/ApiClientesTest.php
      text: PUT e DELETE de cliente inexistente respondem 404 'Cliente não encontrado.'
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoComFalhaNaoApagaNada tests/Unit/ApiClientesTest.php
      text: DELETE com falha simulada responde 500 'Não foi possível excluir. Nada foi alterado.' e mantém OS, vendas e o cliente
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDoClienteResponde200 tests/Unit/ApiClientesTest.php
      text: DELETE ok responde 200 e chama log_app
    - done: true
      test: grep -q 'excluirComDependentes(' application/controllers/api/v1/ClientesController.php && ! grep -nE 'removeClient(Os|Vendas)' application/controllers/api/v1/ClientesController.php
      text: A exclusão pela API usa excluirComDependentes e não mais removeClientOs/removeClientVendas
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/ApiClientesTest.php
      text: Suíte ApiClientesTest passa
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/controllers/api/v1/ClientesController.php
      text: PHPStan não sobe a baseline no arquivo da task
deps:
    - TASK-003
    - TASK-009
    - TASK-019
diff_base: 396259c855f89ac5431be6606b767d2a7a114bf2
diff_tip: 26f71a085ce43bafea2b09538debf39483c37c14
files:
    - application/controllers/api/v1/ClientesController.php
    - tests/Unit/ApiClientesTest.php
id: TASK-022
linter: composer analyse
n45_version: 1.1.0
outcome: |
    **Backend — `application/controllers/api/v1/ClientesController.php`**

    - `GET /api/v1/clientes?perPage&page&search` → 200 lista (sem `senha`) | 403 sem `vCliente` | 422 `Parâmetros de paginação inválidos.` (perPage 1..100, padrão 20).
    - `GET /api/v1/clientes/{id}` → 200 detalhes sem `senha`/`senha_inicial`.
    - `POST /api/v1/clientes` (`aCliente`) → 201 `result` sem `senha`; sem `senha` no corpo gera senha de 16 caracteres e devolve `result.senha_inicial` + `result.email_enviado` (envio síncrono, fora da `email_queue`); com `senha` informada não devolve nenhum dos dois; sempre `senha_trocar = 1`; header `Cache-Control: no-store`; `senha_trocar`/`token_versao`/`sessao_versao`/`asaas_id` ignorados do corpo | 400 validação | 403 | 500.
    - `PUT /api/v1/clientes/{id}` (`eCliente`) → 200 | 400 documento inválido | 403 | 404 `Cliente não encontrado.` | 500 `Não foi possível alterar a senha do cliente.`; com `senha` usa `registrarTrocaDeSenha(id, hash, true)` (derruba sessões/refresh), sem `senha` não mexe nas versões.
    - `DELETE /api/v1/clientes/{id}` (`dCliente`) → 200 `Cliente excluído com sucesso!` | 400 | 403 | 404 | 500 `Não foi possível excluir. Nada foi alterado.` via `Clientes_model::excluirComDependentes`.
    - Símbolo protegido: `enviarSenhaInicial(object $cliente, string $senha): bool` (sobrescrevível em testes).
persona: backend
phase: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/ApiClientesTest.php tests/Unit/ApiBaseTest.php tests/Unit/ApiSegurancaTest.php
title: 'API de clientes: senha inicial, allow-list, paginação e exclusão'
updated_at: "2026-11-04 00:57:12"
---
## Contexto

`POST /api/v1/clientes` ainda usa o CPF/CNPJ sem pontuação como senha da área do cliente quando o integrador não manda `senha` (`ClientesController::index_post`, `$senhaCliente = ... ?: preg_replace(..., documento)`). A mesma API responde 401 para falta de permissão, aceita `perPage` sem teto, troca a senha do cliente no `PUT` com um `edit()` simples (sem derrubar sessões e refresh tokens) e exclui o cliente com `removeClientOs()`/`removeClientVendas()`/`delete()` soltos, respondendo sucesso mesmo com o rollback parcial.

Esta task leva para a API o que a TASK-019 entregou no painel. Contratos já entregues que ela consome:

- `REST_Controller` (TASK-003): `protected exigirPermissao(string $permissao, string $mensagem): void` → 403 e encerra; `protected paginacaoDaApi(): array` → `[int $limite, int $offset]` (`perPage` 1..100, padrão 20, teto 100; `page` ≥ 0) ou 422 `{status:false, message:'Parâmetros de paginação inválidos.', erros}`.
- `clienteSenhaAleatoria(int $tamanho = 16): string` (TASK-009): CSPRNG, alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789`.
- `Clientes_model::registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool` (TASK-009): grava `senha` e `senha_trocar`, incrementa `sessao_versao`/`token_versao`, revoga refresh `tipo='cliente'`; `false` = nada mudou.
- **TASK-019 (mesma fase, dependência):**
  - `clienteEnviarSenhaInicial(object $email, ?object $emitente, object $cliente, string $html): bool` (`clientes_helper.php`): envia **síncrono** (`send(true)`, fora da `email_queue`); `false` sem emitente, com e-mail do cliente inválido ou falha (já loga `email senha_inicial falha cliente=<id> erro=<classe|envio>`, sem senha).
  - View do e-mail `application/views/clientes/emails/senha_inicial.php`, variáveis `$cliente`, `$emitente`, `$senha`, `$urlAcesso`.
  - `Clientes_model::excluirComDependentes(int $idCliente): bool`: transação que apaga OS (com `servicos_os`/`produtos_os`/`anotacoes_os`/`os_historico`), vendas (com `itens_de_vendas`) e o cliente, e revoga os refresh `tipo='cliente'`; `false` = nada mudou (já loga `transacao rollback operacao=excluir_cliente id=<id>`), inclusive cliente inexistente.
  - No painel, todo cliente criado pelo operador grava `senha_trocar = 1` (senha gerada ou digitada).
- `apiSemSenha($valor)` (api_helper) já tira `senha` do objeto devolvido.

Regras da feature: `senha_inicial` só na resposta **201** do POST e só quando a senha foi gerada; resposta com `Cache-Control: no-store`; a senha em claro nunca vai para log (`log_message`, `log_app`), banco ou `email_queue`; `senha_trocar`, `sessao_versao`, `token_versao` e `asaas_id` nunca vêm do corpo.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `POST /api/v1/clientes` sem `senha`, `documento: "123.456.789-09"`, `email: "ana@x.com"`, envio OK | 201 `{status:true, message:'Cliente adicionado com sucesso!', result:{idClientes, nomeCliente, ..., senha_inicial:<16 caracteres do alfabeto>, email_enviado:true}}`; `result` sem `senha`; header `Cache-Control: no-store`; no banco `senha_trocar = 1`, `password_verify('12345678909', senha) = false` e `password_verify(senha_inicial, senha) = true`; 0 linhas em `email_queue` |
| mesmo POST com o envio devolvendo `false` | 201 com `email_enviado: false` e `senha_inicial` presente |
| `POST /api/v1/clientes` com `senha: "Digitada123"` | 201 **sem** `senha_inicial` e **sem** `email_enviado`; `senha_trocar = 1`; `password_verify('Digitada123', senha) = true`; envio não chamado |
| `POST /api/v1/clientes` com `senha_trocar: 0, token_versao: 9, sessao_versao: 9, asaas_id: "x"` no corpo | cliente criado com `senha_trocar = 1`, `token_versao`/`sessao_versao` padrão do banco e `asaas_id` nulo |
| depois do POST, `GET /api/v1/clientes/{id}` | `result` sem `senha_inicial` e sem `senha` |
| a senha gerada procurada em todos os logs capturados | não aparece (`assertLogNaoContem`) |
| `GET /api/v1/clientes` sem `vCliente` | 403 `{status:false, message:'Você não está autorizado a Visualizar Clientes'}` |
| `GET /api/v1/clientes?perPage=1000000` com 130 clientes | 100 itens |
| `GET /api/v1/clientes?perPage=0` | 422 `Parâmetros de paginação inválidos.` |
| `GET /api/v1/clientes?page=1&perPage=5` | o model recebe limite 5 e offset 5 |
| `PUT /api/v1/clientes/5` com `senha: "Outra12345"` | 200; `registrarTrocaDeSenha(5, <hash>, true)`: `senha_trocar = 1`, `sessao_versao` e `token_versao` +1, refresh `tipo='cliente'` do 5 revogados |
| `PUT /api/v1/clientes/5` com `senha` e `registrarTrocaDeSenha` falso | 500 `{status:false, message:'Não foi possível alterar a senha do cliente.'}` |
| `PUT /api/v1/clientes/5` sem `senha` | `sessao_versao`/`token_versao` inalterados |
| `PUT /api/v1/clientes/99` (inexistente) | 404 `{status:false, message:'Cliente não encontrado.'}` |
| `PUT /api/v1/clientes/5` com `senha_trocar: 0, token_versao: 0` no corpo | campos inalterados no banco |
| `DELETE /api/v1/clientes/3` sem `dCliente` | 403; nada apagado |
| `DELETE /api/v1/clientes/3` com falha simulada no `DELETE` de `clientes` | 500 `{status:false, message:'Não foi possível excluir. Nada foi alterado.'}`; OS, vendas e o cliente 3 continuam |
| `DELETE /api/v1/clientes/3` ok | 200 `Cliente excluído com sucesso!`; `log_app` chamado |
| `DELETE /api/v1/clientes/99` | 404 `Cliente não encontrado.` |

## TDD

Suíte nova `tests/Unit/ApiClientesTest.php` no molde de `tests/Unit/ApiOsSegurancaTest.php` (subclasse anônima de `ClientesController`: `response()` lança `RuntimeException(json_encode(['code' => ..., 'data' => ...]))`; `logged_user()`, `permission` (`pode`), `get()`/`post()`/`put()` lendo `entrada`, `log_app()` contando; `form_validation->run()` stub `true`; `output` stub que guarda os headers; `clientes_model` real sobre SQLite). O envio fica no método `protected function enviarSenhaInicial(object $cliente, string $senha): bool` do controller (mesmo nome do painel), sobrescrito no teste. A tabela `clientes` do teste tem `senha_trocar`, `sessao_versao`, `token_versao`, `asaas_id`; `api_refresh_tokens` com as colunas de `Refresh_token` (ver `tests/Unit/SenhaClienteTest.php`).

```php
public function testPostSemSenhaDevolveSenhaInicialUmaVez(): void
{
    $resposta = $this->chamar('index_post', [], ['nomeCliente' => 'Ana', 'documento' => '123.456.789-09', 'email' => 'ana@x.com']);

    $this->assertSame(201, $resposta['code']);
    $senha = $resposta['data']['result']['senha_inicial'];
    $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789]{16}$/', $senha);
    $this->assertTrue($resposta['data']['result']['email_enviado']);
    $this->assertArrayNotHasKey('senha', $resposta['data']['result']);
    $gravado = $this->db->get('clientes')->row();
    $this->assertSame(1, (int) $gravado->senha_trocar);
    $this->assertFalse(password_verify('12345678909', $gravado->senha));
    $this->assertTrue(password_verify($senha, $gravado->senha));
    $this->assertSame('no-store', $this->controller->output->headers['Cache-Control'] ?? null);
    $this->assertLogNaoContem($senha);
}
```

Cobrir os demais casos da tabela (nomes nos critérios). Falha de banco simulada com trigger `RAISE(ABORT)` como em `tests/Unit/OsVendasTransacaoTest.php::falhaAntesDe()`.

**Refatoração:** `index_post` e `index_put` leem o corpo por uma única lista de campos permitidos (array de nomes) em vez de repetir `$this->post(...)` campo a campo.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/api/v1/ClientesController.php` | Modificar | senha inicial, `senha_trocar`, `exigirPermissao`, paginação, troca de senha por `registrarTrocaDeSenha`, exclusão por `excluirComDependentes`, 404 |
| `tests/Unit/ApiClientesTest.php` | Criar | Suíte nova |

### Detalhamento Técnico

1. Construtor: carregar também o helper `clientes` (e `api`, se ainda não estiver).
2. **Permissão:** `vCliente`, `aCliente`, `eCliente`, `dCliente` por `$this->exigirPermissao(<perm>, <mensagem atual>)`.
3. **Listagem:** `[$limite, $offset] = $this->paginacaoDaApi();` → `clientes_model->get('clientes', '*', $search, $limite, $offset)`.
4. **POST:**
   - campos permitidos: `nomeCliente`, `contato`, `documento`, `telefone`, `celular`, `email`, `rua`, `numero`, `complemento`, `bairro`, `cidade`, `estado`, `cep`, `fornecedor`, `senha` — tudo lido com `(string) ($this->post($campo, true) ?? '')`;
   - `$senhaGerada = trim($senhaInformada) === '' ? clienteSenhaAleatoria() : null`; `senha` = `password_hash($senhaGerada ?? $senhaInformada, PASSWORD_DEFAULT)`; `senha_trocar = 1` sempre; `pessoa_fisica` pelo tamanho do documento sem pontuação (como hoje);
   - depois de gravar: `$cliente = getById($id)`; `$result = (array) apiSemSenha($cliente)`; com `$senhaGerada`: `$result['senha_inicial'] = $senhaGerada; $result['email_enviado'] = $this->enviarSenhaInicial($cliente, $senhaGerada);`
   - `$this->output->set_header('Cache-Control: no-store');` antes do `response(..., 201)`;
   - `log_app('Adicionou um cliente. ID: ' . $id)` — nunca com a senha.
5. **`enviarSenhaInicial(object $cliente, string $senha): bool`** (protected): `mapos_model->getEmitente()`, library `email`, `$html = $this->load->view('clientes/emails/senha_inicial', ['cliente' => $cliente, 'emitente' => $emitente, 'senha' => $senha, 'urlAcesso' => site_url('mine')], true)`, `return clienteEnviarSenhaInicial($this->email, $emitente, $cliente, $html);`.
6. **PUT:** `getById($id)` nulo → 404 `Cliente não encontrado.`; mesmos campos permitidos **sem** `senha` vão para `edit()`; com `senha` não vazia → `registrarTrocaDeSenha((int) $id, password_hash(...), true)`; `false` → 500 `Não foi possível alterar a senha do cliente.`.
7. **DELETE:** `$id` inteiro positivo (senão 400 atual); `getById` nulo → 404 `Cliente não encontrado.`; `excluirComDependentes((int) $id)` falso → 500 `Não foi possível excluir. Nada foi alterado.`; verdadeiro → `log_app('Removeu um cliente. ID: ' . $id)` e 200. Remover o uso de `getAllOsByClient`/`removeClientOs`/`getAllVendasByClient`/`removeClientVendas` deste controller.

**Contrato HTTP:**

```
POST /api/v1/clientes
Authorization: Bearer <token>
Content-Type: application/json
{
  "nomeCliente": "Ana Souza",            // obrigatório (form_validation 'clientes')
  "documento": "123.456.789-09",
  "email": "ana@x.com",
  "celular": "(11) 99999-0000",
  "senha": ""                            // opcional; vazio/ausente = senha gerada
}
Response 201 (senha gerada):  {status:true, message:"Cliente adicionado com sucesso!", result:{"idClientes":12, "nomeCliente":"Ana Souza", ..., "senha_inicial":"Xk7mPq2RtW9zHn4C", "email_enviado":true}}
Response 201 (senha informada): {status:true, message:"Cliente adicionado com sucesso!", result:{"idClientes":12, ...}}   // sem senha_inicial/email_enviado
Headers: Cache-Control: no-store
Response 400 validação (atual) · 401 token · 403 sem aCliente · 500 "Não foi possível adicionar o Cliente."

PUT /api/v1/clientes/{id}      → 200 | 403 | 404 "Cliente não encontrado." | 500 "Não foi possível alterar a senha do cliente."
DELETE /api/v1/clientes/{id}   → 200 "Cliente excluído com sucesso!" | 403 | 404 | 500 "Não foi possível excluir. Nada foi alterado."
GET /api/v1/clientes?perPage=20&page=0&search=ana → 200 | 403 | 422 paginação
```

**Contrato com camadas adjacentes:**

```
Consome de: clienteEnviarSenhaInicial(...): bool (TASK-019) → vira result.email_enviado
Consome de: Clientes_model::excluirComDependentes(int): bool (TASK-019) → false = HTTP 500
Consome de: Clientes_model::registrarTrocaDeSenha(int, string, bool): bool (TASK-009) → false = HTTP 500
```
