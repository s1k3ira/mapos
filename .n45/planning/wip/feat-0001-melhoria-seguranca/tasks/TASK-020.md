---
checkpoint: null
complexity: G
created_at: "2026-11-03 22:54:26"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testPutEmOsFechadaResponde403 tests/Unit/ApiOsTest.php
      text: PUT em OS Faturado/faturado=1 e em OS Cancelado responde 403 'OS Faturada/Cancelada não pode ser alterada.' e a OS fica intacta
    - done: false
      test: application/vendor/bin/phpunit --filter testPutEmOsInexistenteResponde404 tests/Unit/ApiOsTest.php
      text: PUT em OS inexistente responde 404 'OS não encontrada.'
    - done: false
      test: application/vendor/bin/phpunit --filter 'testPutNaoAceitaStatusFaturado|testPutRecusaStatusDesconhecido' tests/Unit/ApiOsTest.php
      text: PUT com status Faturado responde 422 com erros.status 'Para faturar a OS, use o faturamento no painel.' e status inválido responde 422 'Status inválido.'
    - done: false
      test: application/vendor/bin/phpunit --filter testPutIgnoraCamposForaDaAllowList tests/Unit/ApiOsTest.php
      text: PUT com faturado, valorTotal, lancamento e idOs no corpo não altera esses campos no banco
    - done: false
      test: application/vendor/bin/phpunit --filter testPostSempreCriaOsNaoFaturada tests/Unit/ApiOsTest.php
      text: POST com faturado 1 no corpo cria a OS com faturado 0
    - done: false
      test: application/vendor/bin/phpunit --filter testDatasInvalidasDoCorpoRespondem422 tests/Unit/ApiOsTest.php
      text: dataInicial 31/02/2026 no PUT e 2026-11-01 no POST respondem 422 erros.dataInicial sem Deprecated nem warning
    - done: false
      test: application/vendor/bin/phpunit --filter testPutSemDataFinalUsaHoje tests/Unit/ApiOsTest.php
      text: PUT sem dataFinal grava dataInicial 2026-11-01 e dataFinal de hoje
    - done: false
      test: application/vendor/bin/phpunit --filter testFiltroDeDatasValidado tests/Unit/ApiOsTest.php
      text: GET com from=31/02/2026 e from=2026-01-01 responde 422 erros.from; janela de 400 dias responde 422 erros.to 'O período pode ter no máximo 366 dias.'
    - done: false
      test: application/vendor/bin/phpunit --filter testPaginacaoDaListagem tests/Unit/ApiOsTest.php
      text: GET com perPage=1000000 limita a 100, perPage=abc responde 422 e page=2&perPage=10 chega ao model com offset 20
    - done: false
      test: application/vendor/bin/phpunit --filter testSemPermissaoResponde403 tests/Unit/ApiOsTest.php
      text: Sem permissão a rota responde 403 (index_get, index_put, index_delete, produtos_post)
    - done: false
      test: '! grep -n ''HTTP_UNAUTHORIZED'' application/controllers/api/v1/OsController.php'
      text: OsController não responde mais 401 por falta de permissão
    - done: false
      test: application/vendor/bin/phpunit --filter testInsercaoDeItemDevolveOIdInserido tests/Unit/ApiOsTest.php
      text: produtos_post e anotacoes_post devolvem o id da linha inserida pela própria requisição (insert_id)
    - done: false
      test: '! grep -rn ''lastRow('' application/controllers/api/v1/OsController.php'
      text: Nenhum controller usa lastRow(
    - done: false
      test: application/vendor/bin/phpunit --filter testExclusaoComFalhaNaoApagaNada tests/Unit/ApiOsTest.php
      text: DELETE com falha simulada no DELETE de os responde 500 'Não foi possível excluir. Nada foi alterado.' e mantém OS, itens e anotações
    - done: false
      test: application/vendor/bin/phpunit --filter testExclusaoDeOsAbertaApagaTudo tests/Unit/ApiOsTest.php
      text: DELETE de OS aberta sem cobrança apaga OS e itens e responde 200
    - done: false
      test: application/vendor/bin/phpunit --filter testExclusaoComCobrancaAtivaResponde400 tests/Unit/ApiOsTest.php
      text: DELETE com cobrança não cancelada responde 400 e não apaga nada
    - done: false
      test: application/vendor/bin/phpunit --filter testOsDadosDaApiAllowList tests/Unit/ApiOsTest.php
      text: osDadosDaApi lê só os campos da allow-list e não devolve faturado na edição
    - done: false
      test: '! grep -n ''@unlink'' application/controllers/api/v1/OsController.php'
      text: anexos_delete não silencia falha com @unlink
    - done: false
      test: application/vendor/bin/phpunit tests/Unit/ApiOsTest.php tests/Unit/ApiOsSegurancaTest.php
      text: ApiOsSegurancaTest atualizado (403 sem permissão) e ApiOsTest passam
    - done: false
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/controllers/api/v1/OsController.php application/helpers/os_helper.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-003
    - TASK-015
files:
    - application/controllers/api/v1/OsController.php
    - application/helpers/os_helper.php
    - tests/Unit/ApiOsTest.php
    - tests/Unit/ApiOsSegurancaTest.php
id: TASK-020
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
tests: application/vendor/bin/phpunit tests/Unit/ApiOsTest.php tests/Unit/ApiOsSegurancaTest.php tests/Unit/ApiBaseTest.php tests/Unit/OsFormularioTest.php
title: 'API de OS: OS fechada, allow-list, datas, paginação e exclusão'
updated_at: "2026-11-03 22:54:26"
---
## Contexto

A auditoria achou na API de OS (`application/controllers/api/v1/OsController.php`):

- `PUT /api/v1/os/{id}` edita OS faturada/cancelada e **zera `faturado`** (o array de dados sempre leva `'faturado' => 0`); aceita `status = "Faturado"` sem passar pelo faturamento (que cria o lançamento).
- Falta de permissão responde **401** (deveria ser 403) em todas as rotas: `checkPermission(...)` seguido de `HTTP_UNAUTHORIZED`, 16 ocorrências.
- `GET /api/v1/os` com `perPage` sem teto e o offset multiplicado duas vezes (`$page = page * perPage` e de novo `$start = perPage * $page`, que nem é usado); `from`/`to` convertidos por `explode('/')` sem validar (`31/02/2026` vira data inválida, `2026-01-01` gera warning de índice).
- `POST`/`PUT` montam `dataInicial`/`dataFinal` com `explode` dentro de `try/catch (Exception)` — que não pega warning, então entrada inválida grava lixo.
- `produtos_post`, `servicos_post` e `anotacoes_post` buscam o id inserido com `Api_model::lastRow()` (a última linha da tabela, que numa corrida é de outra requisição).
- `DELETE /api/v1/os/{id}` apaga itens, anexos e lançamento em chamadas soltas e não usa a transação que o painel já usa; `anexos_delete` usa `@unlink`.

Contratos já entregues que esta task consome:

- `REST_Controller` (TASK-003): `protected exigirPermissao(string $permissao, string $mensagem): void` → 403 `{status:false, message:$mensagem}` e encerra; `protected paginacaoDaApi(): array` → `[int $limite, int $offset]` (`perPage` 1..100, padrão 20, acima de 100 vira 100; `page` ≥ 0; `offset = page * perPage`) ou 422 `{status:false, message:'Parâmetros de paginação inválidos.', erros:{perPage?|page?}}`; `logged_user()` → 401 em qualquer falha de token.
- `api_helper.php` (TASK-003): `apiDataValida(?string $valor, string $formato): ?string` → `Y-m-d` ou `null` (recusa `31/02`); `apiJanelaValida(string $inicioYmd, string $fimYmd, int $maxDias = 366): bool` (fim ≥ início e ≤ 366 dias).
- `Os_model::excluirComDependentes(int $idOs, bool $excluirCobranca, ?int $idLancamentoFatura, bool $faturada, ?callable $dentroDaTransacao = null): bool` (TASK-015): `true` tudo apagado e commitado; `false` rollback com log `transacao rollback operacao=excluir_os id=<id>`. O callable roda após o BEGIN e entra no rollback.
- `Os_model::faturaBaixada(int $idOs, ?int $idLancamento): bool`, `osArquivosDosAnexos(iterable $anexos, string $raizLegada): array` (os_helper) e `armazenamentoApagar(string $caminho): void` (armazenamento_helper, TASK-006, loga a falha) — usados pelo `Os::excluir()` do painel, que é o modelo para a exclusão pela API.
- Status de OS conhecidos (`OS_STATUS_VARIANTES` em `status_helper.php`): `Orçamento`, `Negociação`, `Aberto`, `Aprovado`, `Em Andamento`, `Aguardando Peças`, `Finalizado`, `Faturado`, `Cancelado`.

Regras: escrita por **allow-list** explícita (`faturado`, `valorTotal`, `lancamento`, `idOs`, `desconto`, `valor_desconto` nunca vêm do corpo); faturar é só pelo fluxo do painel; `null` convertido antes de função de string (`(string)`/`?? ''`) — os testes rodam sob `semDeprecated()`; RF-14 vale para formato e janela do filtro; `POST`/`PUT` validam o formato das datas.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `PUT /api/v1/os/3` (OS 3 `status = 'Faturado'`, `faturado = 1`), `control_editos = '0'` | 403 `{status:false, message:'OS Faturada/Cancelada não pode ser alterada.'}`; OS 3 intacta |
| `PUT /api/v1/os/4` (OS 4 `status = 'Cancelado'`, `faturado = 0`) | 403, mesma mensagem; OS 4 intacta |
| `PUT /api/v1/os/99` (inexistente) | 404 `{status:false, message:'OS não encontrada.'}` |
| `PUT /api/v1/os/1` (aberta) com `status: "Faturado"` | 422 `{status:false, message:'Dados inválidos.', erros:{status:'Para faturar a OS, use o faturamento no painel.'}}`; OS 1 intacta |
| `PUT /api/v1/os/1` com `status: "Qualquer"` | 422 `erros.status = 'Status inválido.'` |
| `PUT /api/v1/os/1` com `faturado: 1, valorTotal: 999, lancamento: 5, idOs: 77` e dados válidos | 200; no banco `faturado`, `valorTotal`, `lancamento` e `idOs` da OS 1 continuam os anteriores |
| `PUT /api/v1/os/1` com `dataInicial: "31/02/2026"` | 422 `erros.dataInicial = 'Informe a data no formato dd/mm/aaaa.'`; nenhum warning/Deprecated |
| `PUT /api/v1/os/1` com `dataInicial: "01/11/2026"`, sem `dataFinal` | 200; `dataInicial = '2026-11-01'`, `dataFinal` = data de hoje `Y-m-d` |
| `POST /api/v1/os` com `dataInicial: "2026-11-01"` (formato errado) | 422 `erros.dataInicial`; nenhuma OS criada |
| `POST /api/v1/os` válido com `faturado: 1` no corpo | 201; a OS criada tem `faturado = 0` |
| `GET /api/v1/os?from=31/02/2026` | 422 `{status:false, message:'Datas inválidas.', erros:{from:'Informe a data no formato dd/mm/aaaa.'}}` |
| `GET /api/v1/os?from=2026-01-01` | 422 `erros.from` |
| `GET /api/v1/os?from=01/01/2025&to=05/02/2026` (400 dias) | 422 `erros.to = 'O período pode ter no máximo 366 dias.'` |
| `GET /api/v1/os?perPage=1000000` com 120 OS | 200 com 100 itens |
| `GET /api/v1/os?perPage=abc` | 422 `message = 'Parâmetros de paginação inválidos.'` |
| `GET /api/v1/os?page=2&perPage=10` com OS ids 1..30 | `getOs` recebe limite 10 e offset 20 (uma multiplicação) |
| qualquer rota da OS sem a permissão (`vOs`/`aOs`/`eOs`/`dOs`) | **403** com a mensagem atual da rota (antes 401) |
| `POST /api/v1/os/1/produtos` válido | 201 com `result.idProdutos_os` = `$this->db->insert_id()` da inserção (não a última linha da tabela) |
| `POST /api/v1/os/1/anotacoes` válido, com outra anotação inserida depois do `add` e antes da resposta simulada | `result.idAnotacoes` = id da anotação desta requisição |
| `DELETE /api/v1/os/2` com falha simulada no `DELETE` de `os` | 500 `{status:false, message:'Não foi possível excluir. Nada foi alterado.'}`; itens, anotações e a OS 2 continuam |
| `DELETE /api/v1/os/2` com cobrança não cancelada | 400 com a mensagem atual (`Existe uma cobrança associada...`); nada apagado |
| `DELETE /api/v1/os/2` aberta, sem cobrança | 200 `OS excluída com sucesso!`; OS e itens apagados; estoque devolvido dentro da transação (callable) |

## TDD

Suíte nova `tests/Unit/ApiOsTest.php`, no mesmo molde de `tests/Unit/ApiOsSegurancaTest.php` (subclasse anônima de `OsController` com `response()` virando `RuntimeException` com `{code, data}`, `logged_user()`/`permission`/`getConfig()`/`log_app()` stubados, `put()`/`post()`/`get()` lendo um array `entrada`, SQLite em memória). Para a paginação, um `os_model` stub que registra `limite`/`offset` recebidos por `getOs()`.

```php
public function testPutNaoAceitaStatusFaturado(): void
{
    $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [1], $this->corpoValido(['status' => 'Faturado'])));

    $this->assertSame(422, $resposta['code']);
    $this->assertSame('Para faturar a OS, use o faturamento no painel.', $resposta['data']['erros']['status']);
    $this->assertSame('Aberto', $this->db->get_where('os', ['idOs' => 1])->row()->status);
}
```

Cobrir os demais casos da tabela (nomes nos critérios). Em `tests/Unit/ApiOsSegurancaTest.php`, `testSemPermissaoEOsAbertaNaoMudaNada` passa a esperar **403** (as demais assertions do arquivo continuam valendo).

**Refatoração:** a leitura do corpo de `POST`/`PUT` vira uma única função de allow-list (`osDadosDaApi()`), e as 16 checagens de permissão viram `exigirPermissao()`.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/api/v1/OsController.php` | Modificar | `exigirPermissao`, `paginacaoDaApi`, filtros de data, allow-list, `osParaEditar` no PUT, `insert_id`, exclusão em transação, sem `@unlink` |
| `application/helpers/os_helper.php` | Modificar | Nova `osDadosDaApi(array $corpo, bool $novo): array` |
| `tests/Unit/ApiOsTest.php` | Criar | Suíte nova |
| `tests/Unit/ApiOsSegurancaTest.php` | Modificar | 401 → 403 sem permissão |

### Detalhamento Técnico

1. **Permissão:** toda ocorrência de `if (! $this->permission->checkPermission($this->logged_user()->level, 'X')) { $this->response([...], HTTP_UNAUTHORIZED); }` vira `$this->exigirPermissao('X', '<mesma mensagem>');`. `isEditable()` (que só devolve bool) não muda.
2. **`osDadosDaApi(array $corpo, bool $novo): array{ok: bool, erros: array<string,string>, dados: array<string,mixed>}`** em `os_helper.php`:
   - lê só `dataInicial`, `dataFinal`, `clientes_id`, `usuarios_id`, `garantia`, `garantias_id`, `termoGarantia`, `descricaoProduto`, `defeito`, `status`, `observacoes`, `laudoTecnico`; qualquer outra chave é ignorada;
   - `dataInicial`: `apiDataValida((string) ($corpo['dataInicial'] ?? ''), 'd/m/Y')`; `null` → `erros.dataInicial = 'Informe a data no formato dd/mm/aaaa.'`;
   - `dataFinal`: vazia/ausente → `date('Y-m-d')`; senão mesma validação → `erros.dataFinal`;
   - `status`: `'Faturado'` → `erros.status = 'Para faturar a OS, use o faturamento no painel.'`; fora de `array_keys(OS_STATUS_VARIANTES)` → `'Status inválido.'` (carregar `status_helper` se a constante não existir);
   - `garantias_id`: inteiro positivo de `garantias_id` (o campo `termoGarantia` legado só liga/desliga: vazio → `null`), senão `null`;
   - textos com `(string) (... ?? '')` (sem Deprecated);
   - `$novo === true` → `dados['faturado'] = 0`; `$novo === false` → **sem** chave `faturado`.
   - Devolve `ok = erros === []`.
3. **`index_post`**: mantém `form_validation->run('os')` (400 como hoje para obrigatórios); depois `osDadosDaApi($corpo, true)`; `! ok` → 422 `{status:false, message:'Dados inválidos.', erros}`.
4. **`index_put($id)`**: `$this->exigirPermissao('eOs', ...)`; `$os = $this->osParaEditar($id, 'OS Faturada/Cancelada não pode ser alterada.', 'OS não encontrada.')` (404/403); obrigatórios conferidos por `$this->put()` (não por `isset($_POST)`) → 400 como hoje; `osDadosDaApi($corpo, false)` → 422; grava só `dados`. A lógica de devolver/debitar estoque ao entrar/sair de `Cancelado` continua, com `strtolower((string) ...)`.
5. **`index_get`**: `[$limite, $offset] = $this->paginacaoDaApi();` passado a `getOs(...)`; `from`/`to`: quando presentes, `apiDataValida(..., 'd/m/Y')`; inválida → 422 `{status:false, message:'Datas inválidas.', erros:{from|to: 'Informe a data no formato dd/mm/aaaa.'}}`; os dois válidos e `! apiJanelaValida($de, $ate)` → 422 `erros.to = 'O período pode ter no máximo 366 dias.'`; válidas viram `where_array['de'|'ate']` em `Y-m-d`.
6. **`produtos_post` / `servicos_post` / `anotacoes_post`**: `$idInserido = (int) $this->db->insert_id();` logo após o `add()` bem-sucedido; o resultado usa esse id. Nenhuma chamada a `lastRow(` sobra em `application/controllers`.
7. **`index_delete($id)`**: espelha `Os::excluir()` do painel — mesma checagem de cobrança (não cancelada → 400), `faturaBaixada` (→ 400 `A fatura desta OS já tem pagamento baixado: estorne o recebimento antes de excluir a OS.`), arquivos coletados por `osArquivosDosAnexos($this->os_model->getAnexos($id), FCPATH . 'assets' . DIRECTORY_SEPARATOR . 'anexos')` **antes**, `excluirComDependentes($id, isset($os->idCobranca), <lancamento>, <faturada>, <status != cancelado ? fn () => $this->devolucaoEstoque($id) : null>)`; `false` → 500 `Não foi possível excluir. Nada foi alterado.`; `true` → `armazenamentoApagar()` de cada arquivo, `log_app`, 200. Carregar o helper `armazenamento`.
8. **`anexos_delete`**: `@unlink($arquivo)` → `armazenamentoApagar($arquivo)`.

**Contrato HTTP:**

```
PUT /api/v1/os/{id}
Authorization: Bearer <token>
Content-Type: application/json
{
  "dataInicial": "01/11/2026",        // obrigatório, dd/mm/aaaa
  "dataFinal": "05/11/2026",          // opcional, dd/mm/aaaa; vazio = hoje
  "status": "Em Andamento",           // enum: "Orçamento" | "Negociação" | "Aberto" | "Aprovado" | "Em Andamento" | "Aguardando Peças" | "Finalizado" | "Cancelado"  ("Faturado" → 422)
  "clientes_id": 1, "usuarios_id": 1, // obrigatórios
  "garantia": "90", "garantias_id": 2, "descricaoProduto": "...", "defeito": "...", "observacoes": "...", "laudoTecnico": "..."
}
Response 200: {status:true, message:"OS editada com sucesso!", result:{...os sem senha}}
Response 400: {status:false, message:"Preencha os campos obrigatórios"}
Response 401: token ausente/inválido · 403: sem eOs | "OS Faturada/Cancelada não pode ser alterada." · 404: "OS não encontrada."
Response 422: {status:false, message:"Dados inválidos.", erros:{dataInicial?|dataFinal?|status?}}

GET /api/v1/os?perPage=20&page=0&from=01/10/2026&to=31/10/2026&status=Aberto&search=tela
Response 200: {status:true, message:"Listando OSs", result:[...]}
Response 422: {status:false, message:"Parâmetros de paginação inválidos.", erros:{perPage?|page?}} | {status:false, message:"Datas inválidas.", erros:{from?|to?}}

DELETE /api/v1/os/{id}
Response 200: {status:true, message:"OS excluída com sucesso!"} · 400 cobrança ativa / fatura baixada · 403 sem dOs · 500 {status:false, message:"Não foi possível excluir. Nada foi alterado."}
```

**Contrato com camadas adjacentes:**

```
Consome de: Os_model::excluirComDependentes(...): bool
  - false → HTTP 500 "Não foi possível excluir. Nada foi alterado." (o model já logou o rollback)
Consome de: apiDataValida(...): ?string / apiJanelaValida(...): bool
  - null / false → HTTP 422 com erros por campo
```
