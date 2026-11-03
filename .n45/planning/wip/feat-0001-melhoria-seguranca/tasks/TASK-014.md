---
checkpoint: null
complexity: M
created_at: "2026-11-03 18:48:13"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testRecusouViraQuatroVinteDoisComAMensagem tests/Unit/CobrancasErrosTest.php
      text: GatewayRecusouException vira [422, <mensagem dela>] em cobrancaRespostaDeFalha
    - done: false
      test: application/vendor/bin/phpunit --filter testIndisponivelSempreUsaAMensagemGenerica tests/Unit/CobrancasErrosTest.php
      text: GatewayIndisponivelException vira [502, 'O provedor de pagamento não respondeu. Tente novamente em instantes.'] mesmo com outro texto na exceção
    - done: false
      test: application/vendor/bin/phpunit --filter testValidacaoLocalViraQuatroVinteDois tests/Unit/CobrancasErrosTest.php
      text: \Exception exata (validação local, ex. 'Valor inválido!') vira [422, mensagem]
    - done: false
      test: application/vendor/bin/phpunit --filter testFalhaTecnicaViraQuinhentosComTextoPadrao tests/Unit/CobrancasErrosTest.php
      text: TypeError e RuntimeException técnicos viram [500, <texto padrão>] com log 'cobranca falha erro=<classe>' e sem a mensagem da exceção no retorno nem no log
    - done: false
      test: application/vendor/bin/phpunit --filter testExclusaoComSucesso tests/Unit/CobrancasErrosTest.php
      text: excluirEmTransacao(7) apaga a cobrança existente e devolve true
    - done: false
      test: application/vendor/bin/phpunit --filter testExclusaoDeCobrancaInexistente tests/Unit/CobrancasErrosTest.php
      text: excluirEmTransacao de cobrança inexistente devolve false e loga 'transacao rollback operacao=excluir_cobranca id=999'
    - done: false
      test: application/vendor/bin/phpunit --filter testExclusaoComFalhaDoBancoMantemACobranca tests/Unit/CobrancasErrosTest.php
      text: 'Falha do banco no DELETE: excluirEmTransacao devolve false, a cobrança continua e o rollback é logado'
    - done: false
      test: '! grep -n ''getMessage()'' application/controllers/Cobrancas.php'
      text: O controller não repassa mensagem de exceção diretamente (só pelo helper)
    - done: false
      test: test "$(grep -c 'cobrancaRespostaDeFalha(' application/controllers/Cobrancas.php)" -ge 3
      text: adicionar(), agirSobreCobranca() e excluir() usam cobrancaRespostaDeFalha
    - done: false
      test: test "$(grep -cE "json_encode\(\['result' => false" application/controllers/Cobrancas.php)" -ge 5
      text: Respostas JSON de erro de adicionar() carregam result false (403, 400, 422, 502, 500)
    - done: false
      test: grep -q 'excluirEmTransacao(' application/controllers/Cobrancas.php && grep -q 'Não foi possível excluir. Nada foi alterado.' application/controllers/Cobrancas.php
      text: excluir() usa a exclusão transacional e a mensagem 'Não foi possível excluir. Nada foi alterado.'
    - done: false
      test: '! grep -n ''Unauthorized'' application/controllers/Cobrancas.php'
      text: O mapeamento antigo de 'Unauthorized' saiu do controller
    - done: false
      test: application/vendor/bin/phpunit tests/Unit/CobrancasTest.php tests/Unit/CobrancaValorTest.php
      text: A suíte de cobranças existente continua verde
deps:
    - TASK-005
files:
    - application/helpers/financeiro_helper.php
    - application/controllers/Cobrancas.php
    - application/models/Cobrancas_model.php
    - tests/Unit/CobrancasErrosTest.php
id: TASK-014
linter: composer analyse
n45_version: 1.1.0
outcome: ""
persona: backend
phase: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/CobrancasErrosTest.php tests/Unit/CobrancasTest.php tests/Unit/CobrancaValorTest.php
title: 'Cobranças: respostas 422/502, mensagens amigáveis e exclusão em transação'
updated_at: "2026-11-03 18:48:13"
---
## Contexto

Os gateways de pagamento já lançam exceções tipadas com mensagem nossa (fase anterior), mas o controller `application/controllers/Cobrancas.php` ainda trata tudo como `\Exception` genérica:

- `adicionar()` responde **500** com `$e->getMessage()` cru para qualquer falha (inclui um mapeamento antigo de `'Unauthorized'`), e as respostas 403/400 não têm `result`.
- `agirSobreCobranca()` (usado por `atualizar`, `confirmarPagamento`, `cancelar`, `enviarEmail`) põe `$e->getMessage()` no flashdata — o texto de qualquer exceção, inclusive técnica.
- `excluir()` apaga a cobrança sem transação, e qualquer exceção vira flashdata com a mensagem crua.

Esta task padroniza as respostas de falha (RF-42) e põe a exclusão de cobrança em transação (RF-33).

O que já existe e esta task consome:

```php
// application/libraries/Gateways/Exceptions/ (namespace Libraries\Gateways\Exceptions, autoload PSR-4 "Libraries\\" => application/libraries)
class GatewayRecusouException extends \RuntimeException      // mensagem sempre nossa, pt-BR, segura para exibir
class GatewayIndisponivelException extends \RuntimeException  // const MENSAGEM = 'O provedor de pagamento não respondeu. Tente novamente em instantes.'
// As validações locais dos gateways e do model seguem como \Exception exata, com textos nossos:
//   'Valor inválido!', 'OS ou venda não existe!', 'Cobrança não existe!', a lista "Por favor preencher os seguintes dados..." de errosCadastro(),
//   e Cobrancas_model::carregarGateway() para gateway fora da configuração.
```

Consumidores do JSON de `cobrancas/adicionar`: `assets/js/modules/os/tela.js` (telas de OS e de venda) — usa `resposta.idCobranca` no sucesso (o JSON de sucesso, `json_encode($cobranca)`, **não muda**) e, em erro, `ErroHttp` com `corpo.message` mostrado ao usuário; 422 sem `erros` cai no aviso geral. `tests/Unit/CobrancasTest.php::testControllerSegueOPadraoDaV5` exige que `json_encode($cobranca)` continue no controller.

Padrões do projeto: JSON do painel `{ result, message, erros? }`; erro técnico por `log_message('error', ...)`, nunca dado do provedor; helpers puros testáveis em `application/helpers/financeiro_helper.php` (onde já moram `cobrancaStatusPill`, `cobrancaGatewayValido`, carregado pelo `tests/bootstrap.php`).

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `cobrancaRespostaDeFalha(new GatewayRecusouException('O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.'), 'padrão')` | `[422, 'O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.']` |
| `cobrancaRespostaDeFalha(new GatewayIndisponivelException(), 'padrão')` | `[502, 'O provedor de pagamento não respondeu. Tente novamente em instantes.']` |
| `cobrancaRespostaDeFalha(new GatewayIndisponivelException('texto qualquer'), 'padrão')` | `[502, 'O provedor de pagamento não respondeu. Tente novamente em instantes.']` (sempre a constante) |
| `cobrancaRespostaDeFalha(new \Exception('Valor inválido!'), 'padrão')` | `[422, 'Valor inválido!']` (validação local, texto nosso) |
| `cobrancaRespostaDeFalha(new \TypeError('Argument #1 ... /var/www/x.php'), 'Não foi possível gerar a cobrança. Tente novamente.')` | `[500, 'Não foi possível gerar a cobrança. Tente novamente.']` e log `error` `cobranca falha erro=TypeError` (sem a mensagem da exceção) |
| `cobrancaRespostaDeFalha(new \RuntimeException('SQLSTATE[HY000] ...'), 'padrão')` | `[500, 'padrão']` (só `\Exception` **exata** é tratada como validação nossa) |
| `POST /cobrancas/adicionar` com gateway lançando `GatewayRecusouException` | 422 `{"result": false, "message": "<mensagem da exceção>"}` |
| `POST /cobrancas/adicionar` com `GatewayIndisponivelException` | 502 `{"result": false, "message": "O provedor de pagamento não respondeu. Tente novamente em instantes."}` |
| `POST /cobrancas/adicionar` sem `aCobranca` | 403 `{"result": false, "message": "Você não tem permissão para adicionar cobrança!"}` |
| `POST /cobrancas/adicionar` com cobrança existente / gateway inválido / validação | 400 `{"result": false, "message": ...}` (mesmas mensagens de hoje) |
| `POST /cobrancas/cancelar` com o gateway indisponível | redirect com flashdata `error` = `O provedor de pagamento não respondeu. Tente novamente em instantes.` |
| `Cobrancas_model::excluirEmTransacao(7)` com a cobrança 7 existente | `true`; linha removida |
| `excluirEmTransacao(999)` (inexistente) | `false`; log `error` `transacao rollback operacao=excluir_cobranca id=999` |
| `excluirEmTransacao(7)` com falha do banco no DELETE | `false`; a linha 7 continua; log `transacao rollback operacao=excluir_cobranca id=7` |
| `POST /cobrancas/excluir`: cancelamento no gateway falha | flashdata `error` com a mensagem de `cobrancaRespostaDeFalha(..., 'Não foi possível cancelar a cobrança no provedor. Nada foi excluído.')`; a cobrança **não** é apagada |
| `POST /cobrancas/excluir`: cancelou, mas `excluirEmTransacao` devolve `false` | flashdata `error` = `Não foi possível excluir. Nada foi alterado.` |
| `POST /cobrancas/excluir` com sucesso | como hoje: `log_info`, flashdata `success` `Cobrança excluída.`, volta à listagem |

## TDD

Arquivo novo `tests/Unit/CobrancasErrosTest.php` (`final class CobrancasErrosTest extends MaposTestCase`), com `require_once APPPATH . 'models/Cobrancas_model.php'`. Model: `$model = new Cobrancas_model(); $model->db = $this->db;` com `CREATE TABLE cobrancas (idCobranca INTEGER PRIMARY KEY AUTOINCREMENT, charge_id TEXT, status TEXT, total TEXT, payment_gateway TEXT, os_id INTEGER, vendas_id INTEGER)`. Falha simulada: `CREATE TRIGGER falha_exclusao BEFORE DELETE ON cobrancas BEGIN SELECT RAISE(ABORT, 'falha simulada'); END`.

```php
public function testIndisponivelSempreUsaAMensagemGenerica(): void
{
    $this->assertSame(
        [502, 'O provedor de pagamento não respondeu. Tente novamente em instantes.'],
        cobrancaRespostaDeFalha(new GatewayIndisponivelException('texto do provedor'), 'padrão')
    );
}

public function testExclusaoComFalhaDoBancoMantemACobranca(): void
{
    $this->db->insert('cobrancas', ['idCobranca' => 7, 'status' => 'canceled']);
    $this->db->query("CREATE TRIGGER falha_exclusao BEFORE DELETE ON cobrancas BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

    $this->assertFalse($this->model()->excluirEmTransacao(7));
    $this->assertSame(1, $this->db->where('idCobranca', 7)->count_all_results('cobrancas'));
    $this->assertLogContem('error', 'transacao rollback operacao=excluir_cobranca id=7');
}
```

Cobrir os demais casos da tabela (helper e model) com os nomes fixados nos critérios. Os ramos do controller são cobertos pelos critérios de assinatura (o controller não é instanciável fora do framework).

**Refatoração:** remover o mapeamento antigo de `'unauthorized: Must provide your access_token to proceed'`/`'Unauthorized'` em `adicionar()` (agora coberto pela exceção tipada).

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/helpers/financeiro_helper.php` | Modificar | `cobrancaRespostaDeFalha(\Throwable $e, string $padrao): array` no padrão das funções vizinhas |
| `application/controllers/Cobrancas.php` | Modificar | `adicionar()` (403/400 com `result`, catch `\Throwable` pelo helper), `agirSobreCobranca()` e `excluir()` |
| `application/models/Cobrancas_model.php` | Modificar | `excluirEmTransacao(int $idCobranca): bool` |
| `tests/Unit/CobrancasErrosTest.php` | Criar | testes do helper e do model |

### Detalhamento Técnico

1. **Helper (assinatura fixada):**

```php
/**
 * Status HTTP e mensagem segura para o usuário a partir de uma falha ao falar
 * com o gateway. Só textos nossos: nunca a mensagem de exceção técnica.
 *
 * @return array{0: int, 1: string}
 */
function cobrancaRespostaDeFalha(\Throwable $e, string $padrao): array
{
    if ($e instanceof \Libraries\Gateways\Exceptions\GatewayRecusouException) {
        return [422, $e->getMessage()];
    }
    if ($e instanceof \Libraries\Gateways\Exceptions\GatewayIndisponivelException) {
        return [502, \Libraries\Gateways\Exceptions\GatewayIndisponivelException::MENSAGEM];
    }
    if (get_class($e) === \Exception::class) {
        return [422, $e->getMessage()]; // validação local dos gateways/model, texto nosso
    }

    log_message('error', 'cobranca falha erro=' . $e::class);

    return [500, $padrao];
}
```

2. **`adicionar()`**: todos os `json_encode([...])` de 403/400 ganham `'result' => false` antes de `message`; o `catch (\Exception $e)` vira `catch (\Throwable $e)` com `[$status, $mensagem] = cobrancaRespostaDeFalha($e, 'Não foi possível gerar a cobrança. Tente novamente.');` e `set_status_header($status)` + `json_encode(['result' => false, 'message' => $mensagem])`. O ramo de sucesso (`json_encode($cobranca)`) não muda.

3. **`agirSobreCobranca()`**: `catch (\Throwable $e)` → `$this->session->set_flashdata('error', cobrancaRespostaDeFalha($e, $erro)[1]);`.

4. **`excluir()`**: o cancelamento no gateway (chamada HTTP) fica **fora** da transação; só a exclusão local é transacional.

```php
try {
    $this->cobrancas_model->cancelarPagamento($id);
} catch (\Throwable $e) {
    $this->session->set_flashdata('error', cobrancaRespostaDeFalha($e, 'Não foi possível cancelar a cobrança no provedor. Nada foi excluído.')[1]);
    redirect($destino);
}

if ($this->cobrancas_model->excluirEmTransacao($id)) {
    log_info('Removeu uma cobrança. ID' . $id);
    $this->session->set_flashdata('success', 'Cobrança excluída.');
    $destino = site_url('cobrancas/cobrancas') . listagemQuery($this->filtrosDaListagem());
} else {
    $this->session->set_flashdata('error', 'Não foi possível excluir. Nada foi alterado.');
}

redirect($destino);
```

5. **`Cobrancas_model::excluirEmTransacao(int $idCobranca): bool`** — `trans_begin()`; `delete('cobrancas')` com `where('idCobranca', $id)`; sucesso só com `affected_rows() === 1` **e** `trans_status() !== false` → `trans_commit()`; senão (ou em `catch (\Throwable)`, já que com `db_debug` ligado o CI desfaz a transação e lança) → `trans_rollback()` + `log_message('error', sprintf('transacao rollback operacao=excluir_cobranca id=%d', $idCobranca))` e `false`.

**Contrato HTTP:**

```
POST /cobrancas/adicionar
Content-Type: application/x-www-form-urlencoded (com o campo CSRF)
id=12&tipo=os&forma_pagamento=boleto&gateway_de_pagamento=Asaas      // tipo: "os" | "venda"; forma_pagamento: "boleto" | "link"

Response 200: <cobrança criada, formato atual> {"idCobranca": 77, "charge_id": "...", "status": "...", "total": 15090, ...}
Response 400: {"result": false, "message": "Já existe cobrança!" | "Gateway de pagamento inválido." | <validation_errors()>}
Response 403: {"result": false, "message": "Você não tem permissão para adicionar cobrança!"}
Response 422: {"result": false, "message": "<mensagem amigável do GatewayRecusouException ou da validação local>"}
Response 502: {"result": false, "message": "O provedor de pagamento não respondeu. Tente novamente em instantes."}
Response 500: {"result": false, "message": "Não foi possível gerar a cobrança. Tente novamente."}
```

**Contrato com camadas adjacentes:**

```
Consome de: gateways (via Cobrancas_model e $this->PaymentGateway)
  - GatewayRecusouException → 422 / flashdata com a mensagem dela
  - GatewayIndisponivelException → 502 / flashdata com GatewayIndisponivelException::MENSAGEM
  - \Exception exata (validação local) → 422 / flashdata com a mensagem
  - qualquer outro \Throwable → 500 / flashdata com o texto padrão da ação + log 'cobranca falha erro=<classe>'
Produz para: assets/js/modules/os/tela.js (lê corpo.message em ErroHttp; sucesso inalterado)
```
