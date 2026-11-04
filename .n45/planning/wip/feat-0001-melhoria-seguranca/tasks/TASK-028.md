---
checkpoint: null
complexity: M
created_at: "2026-11-04 01:17:41"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter 'testListagemDeOsDoClienteUsaPageEPerPage|testListagemDeOsSemParametrosUsaOPadrao' tests/Unit/ApiClientePaginacaoTest.php
      text: GET /api/v1/client/os?page=1&perPage=5 devolve o 2º bloco de 5 (ids 7, 6, 5, 4, 3) e sem parâmetros devolve as 12 OS
    - done: true
      test: application/vendor/bin/phpunit --filter 'testPerPageAcimaDoTetoViraCem|testPerPageNaoInteiroResponde422' tests/Unit/ApiClientePaginacaoTest.php
      text: perPage=1000000 na listagem de OS do cliente consulta com limite 100 e perPage=abc responde 422 com erros.perPage 'Informe um número inteiro de 1 a 100.'
    - done: true
      test: application/vendor/bin/phpunit --filter 'testListagemDeComprasUsaPageEPerPage|testPageNegativoNasComprasResponde422' tests/Unit/ApiClientePaginacaoTest.php
      text: GET /api/v1/client/compras?page=1&perPage=5 devolve as compras 7, 6, 5, 4, 3 e page=-1 responde 422 com erros.page
    - done: true
      test: application/vendor/bin/phpunit --filter 'testListagemDeCobrancasUsaPageEPerPage|testPerPageZeroNasCobrancasResponde422' tests/Unit/ApiClientePaginacaoTest.php
      text: GET /api/v1/client/cobrancas?page=2&perPage=5 devolve as cobranças 2 e 1 e perPage=0 responde 422
    - done: true
      test: application/vendor/bin/phpunit --filter testQrDaCompraVemDoValorDaVenda tests/Unit/ApiClientePaginacaoTest.php
      text: QR da compra 9 vem de vendas_model->getQrCode(9, ...) e os_model->getQrCode não é chamado
    - done: true
      test: application/vendor/bin/phpunit --filter testCompraDeOutroClienteResponde404 tests/Unit/ApiClientePaginacaoTest.php
      text: Compra de outro cliente continua 404 'Compra Não encontrada'
    - done: true
      test: '! grep -n ''segment(3)'' application/controllers/api/v1/client/ClientOsController.php application/controllers/api/v1/client/ClientComprasController.php application/controllers/api/v1/client/ClientCobrancasController.php'
      text: Nenhum segment(3) nos controllers da API do cliente
    - done: true
      test: grep -q 'paginacaoDaApi()' application/controllers/api/v1/client/ClientOsController.php && grep -q 'paginacaoDaApi()' application/controllers/api/v1/client/ClientComprasController.php && grep -q 'paginacaoDaApi()' application/controllers/api/v1/client/ClientCobrancasController.php
      text: As três listagens paginam por paginacaoDaApi()
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/ApiClientePaginacaoTest.php tests/Unit/LimiteDeOsDoClienteTest.php tests/Unit/ApiClienteTokensTest.php tests/Unit/ApiBaseTest.php
      text: Suítes da API do cliente passam
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/controllers/api/v1/client/ClientOsController.php application/controllers/api/v1/client/ClientComprasController.php application/controllers/api/v1/client/ClientCobrancasController.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-003
    - TASK-027
diff_base: 715664687f274bbc01a2de22a92cc36c31fb6eaf
diff_tip: 49230920dfcd23d073ab432a78761a92197751f7
files:
    - application/controllers/api/v1/client/ClientOsController.php
    - application/controllers/api/v1/client/ClientComprasController.php
    - application/controllers/api/v1/client/ClientCobrancasController.php
    - tests/Unit/ApiClientePaginacaoTest.php
id: TASK-028
linter: composer analyse
n45_version: 1.1.0
outcome: |
    # Outcome TASK-028 — Paginação e QR de venda da API do cliente

    - `GET /api/v1/client/os`, `/compras`, `/cobrancas` (Bearer de cliente): query `perPage` (inteiro 1..100, padrão 20, >100 vira 100) e `page` (inteiro >= 0, padrão 0), `offset = page * perPage`, via `paginacaoDaApi()`. Inválido → 422 `{status:false, message:'Parâmetros de paginação inválidos.', erros:{perPage?, page?}}`. Listagem vazia: compras 404 'Nenhuma compra encontrada', cobranças 404 'Nenhum resultado encontrado'.
    - `GET /api/v1/client/compras/{id}`: `result.QrCode` vem de `vendas_model->getQrCode()` (valor da venda); compra de outro cliente segue 404 'Compra Não encontrada'.
    - `os_post()` intocado; nenhum `segment(3)` nos controllers da API do cliente.
persona: backend
phase: Phase 4 — Login por status, logout seguro e limite de OS do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/ApiClientePaginacaoTest.php
title: Paginação e QR de venda da API do cliente
updated_at: "2026-11-04 06:32:59"
---
## Contexto

As listagens da API do cliente paginam por `$this->uri->segment(3)`: nas rotas `api/v1/client/os`, `api/v1/client/compras` e `api/v1/client/cobrancas` o 3º segmento é `client`, então o offset nunca vem do app (vira `(int) 'client'` → 0 ou erro de tipo), e o tamanho da página vem de `configuracoes.per_page` ou é fixo em 20. Esta task aplica às três listagens a mesma regra da API do operador (RF-57 + RF-13): `perPage` inteiro de 1 a 100 (padrão 20; acima de 100 vira 100; não inteiro ou ≤ 0 → 422) e `page` inteiro ≥ 0, com `offset = page * perPage`. Também corrige o QR Code Pix da compra do cliente (RF-55): `ClientComprasController` gera o QR por `os_model->getQrCode($vendaId, ...)`, que calcula o valor de uma **OS** com o mesmo id da venda — o cliente paga o valor errado (ou não recebe QR) quando existe OS com aquele número.

Contrato já entregue que esta task consome (`REST_Controller`, task de base da API):

```php
/** @return array{0: int, 1: int} [limite, offset]; parâmetro inválido responde 422 */
protected function paginacaoDaApi(): array
// lê $this->get('perPage', true) e $this->get('page', true) e aplica apiPaginacao():
// 422 {status:false, message:'Parâmetros de paginação inválidos.', erros:{perPage?:'Informe um número inteiro de 1 a 100.', page?:'Informe um número inteiro maior ou igual a 0.'}}
```

`Vendas_model::getQrCode($id, $pixKey, $emitente)` já existe e devolve o QR do valor da **venda** (`$this->pix(...)?->getQRCode()`). Os métodos de listagem do `Conecte_model` (`getOs`, `getCompras`, `getCobrancas`) recebem `($table, $fields, $where, $perpage, $start, $one, $array, $cliente)` e fazem `limit($perpage, $start)` — não mudam. A task anterior desta fase já mexeu em `ClientOsController::os_post()` (limite diário): não alterar o `os_post()` aqui.

## Comportamento Esperado

Cliente 7 com 12 OS, 12 compras e 12 cobranças (ids 1..12; as listagens ordenam do id mais alto para o mais baixo):

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `GET /api/v1/client/os?page=1&perPage=5` | 200; `result.Os` com os ids `[7, 6, 5, 4, 3]` (2º bloco de 5) |
| `GET /api/v1/client/os` (sem parâmetros) | 200; 12 OS (padrão 20 cobre todas) |
| `GET /api/v1/client/os?perPage=1000000` | 200; `getOs` chamado com `limite = 100` |
| `GET /api/v1/client/os?perPage=abc` | 422 `{"status":false,"message":"Parâmetros de paginação inválidos.","erros":{"perPage":"Informe um número inteiro de 1 a 100."}}` |
| `GET /api/v1/client/compras?page=1&perPage=5` | 200; `result.Compras` com os ids `[7, 6, 5, 4, 3]` |
| `GET /api/v1/client/compras?page=-1` | 422 com `erros.page` = `"Informe um número inteiro maior ou igual a 0."` |
| `GET /api/v1/client/cobrancas?page=2&perPage=5` | 200; `result` com 2 cobranças (`[2, 1]`) |
| `GET /api/v1/client/cobrancas?perPage=0` | 422 com `erros.perPage` |
| `GET /api/v1/client/compras/9` (venda 9 do cliente 7; existe OS 9 de outro valor) | 200; `result.QrCode` = o retorno de `vendas_model->getQrCode(9, <pix_key>, <emitente>)`; `os_model->getQrCode` **não** é chamado |
| `GET /api/v1/client/compras/9` de outro cliente | 404 `"Compra Não encontrada"` (como hoje) |

## TDD

Arquivo novo `tests/Unit/ApiClientePaginacaoTest.php` (`final class ApiClientePaginacaoTest extends MaposTestCase`). Cada controller é instanciado como **subclasse anônima** (nome repetido entre arquivos de teste derruba a suíte com `Cannot redeclare class`), no padrão de `tests/Unit/ApiClienteTokensTest.php::controller()`: propriedades públicas injetadas (`db`, `Conecte_model`, `vendas_model`, `os_model`, `mapos_model`, `format`, `load`, `CI`), `response()` lançando `RuntimeException(json_encode(['code' => $http_code, 'data' => $data]))`, `logged_client()` devolvendo `(object) ['usuario' => (object) ['idClientes' => 7]]`, `get($key = null, $xss_clean = null)` lendo um array de query do teste, e `__destruct()` vazio. Para as listagens, `Conecte_model` real sobre SQLite (`os`, `vendas`, `cobrancas`, `usuarios`, `clientes`) **ou** um dublê que registra `($perpage, $start)` recebidos e devolve linhas — os dois servem, desde que o teste asserte os ids/valores exatos. Para o QR, dublês de `vendas_model` (devolve `'qr-da-venda-9'` e registra a chamada) e `os_model` (registra se foi chamado).

```php
public function testListagemDeOsDoClienteUsaPageEPerPage(): void
{
    $api = $this->os(['page' => '1', 'perPage' => '5']);

    $resposta = $this->chamar($api, 'os_get');

    $this->assertSame(200, $resposta['code']);
    $this->assertSame([7, 6, 5, 4, 3], array_map(fn ($os) => (int) $os['idOs'], $resposta['data']['result']['Os']));
}

public function testQrDaCompraVemDoValorDaVenda(): void
{
    $api = $this->compras();

    $resposta = $this->chamar($api, 'index_get', [9]);

    $this->assertSame('qr-da-venda-9', $resposta['data']['result']['QrCode']);
    $this->assertSame(0, $api->os_model->chamadasQr);
}
```

Cobrir os demais casos da tabela com os nomes dos critérios.

**Refatoração:** Nenhuma.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/api/v1/client/ClientOsController.php` | Modificar | `os_get()` sem id pagina por `paginacaoDaApi()` (só esse ramo; `os_post()` intocado) |
| `application/controllers/api/v1/client/ClientComprasController.php` | Modificar | listagem por `paginacaoDaApi()`; QR por `vendas_model->getQrCode()` |
| `application/controllers/api/v1/client/ClientCobrancasController.php` | Modificar | listagem por `paginacaoDaApi()` |
| `tests/Unit/ApiClientePaginacaoTest.php` | Criar | Paginação das três listagens e QR da compra |

### Detalhamento Técnico

1. **`ClientOsController::os_get()`**, ramo sem `$id`: trocar a leitura de `configuracoes.per_page` + `pagination->initialize()` + `$this->uri->segment(3)` por

   ```php
   [$limite, $offset] = $this->paginacaoDaApi();
   $data['results'] = $this->Conecte_model->getOs('os', '*', '', $limite, $offset, '', '', $clientLogged->usuario->idClientes);
   ```

   Reutilizar `$clientLogged` (não chamar `logged_client()` de novo). Remover `$this->load->library('pagination')` do construtor se nenhum outro método usar.
2. **`ClientComprasController::index_get()`**, ramo sem id: mesma troca (`getCompras('vendas', '*', '', $limite, $offset, '', '', $clientLogged->usuario->idClientes)`); listagem vazia segue 404 `'Nenhuma compra encontrada'`. Ramo com id: `$this->load->model('vendas_model')` já existe; trocar `$this->os_model->getQrCode($vendaId, $pixKey, $emitente)` por `$this->vendas_model->getQrCode($vendaId, $pixKey, $emitente)`. Remover `os_model`/`pagination` do construtor se ficarem sem uso.
3. **`ClientCobrancasController::index_get()`**: `[$limite, $offset] = $this->paginacaoDaApi();` e `getCobrancas('cobrancas', '*', '', $limite, $offset, '', '', $clientLogged->usuario->idClientes)`; listagem vazia segue 404 `'Nenhum resultado encontrado'`.
4. Nenhum `segment(3)` sobra em `application/controllers/api/v1/client/`.

**Contrato HTTP:**

```
GET /api/v1/client/os?page=0&perPage=20
GET /api/v1/client/compras?page=0&perPage=20
GET /api/v1/client/cobrancas?page=0&perPage=20
Authorization: Bearer <access token de cliente>

Query: perPage  // inteiro 1..100, padrão 20; > 100 vira 100
       page     // inteiro >= 0, padrão 0; offset = page * perPage

Response 200 (os):       {"status": true, "message": "Listando resultados", "result": {"Os": [...]}}
Response 200 (compras):  {"status": true, "message": "Listando resultados", "result": {"Compras": [...]}}
Response 200 (cobrancas):{"status": true, "message": "Listando resultados", "result": [...]}
Response 422: {"status": false, "message": "Parâmetros de paginação inválidos.", "erros": {"perPage": "Informe um número inteiro de 1 a 100."}}
Response 404 (compras/cobrancas vazias): {"status": false, "message": "Nenhuma compra encontrada" | "Nenhum resultado encontrado"}
Response 401 / 403 troca_de_senha_obrigatoria: pelo logged_client()

GET /api/v1/client/compras/{id}
Response 200: {"status": true, "message": "Listando resultados", "result": {"Compras": {...}, "Produtos": [...], "QrCode": "<QR do valor da venda>", "ChaveFormatada": "..."}}
Response 404: {"status": false, "message": "Compra Não encontrada"}
```
