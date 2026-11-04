---
checkpoint: null
complexity: G
created_at: "2026-11-03 18:50:01"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDaOsApagaTudoQueEDela tests/Unit/OsVendasTransacaoTest.php
      text: Os_model::excluirComDependentes(3, ...) apaga OS, serviços, produtos, anexos, anotações e histórico e devolve true
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNoMeioDaExclusaoDaOsMantemTudo tests/Unit/OsVendasTransacaoTest.php
      text: Falha no DELETE da OS mantém serviços 2, produtos 1, anexos 1, anotações 1, histórico 2 e a OS, devolve false e loga 'transacao rollback operacao=excluir_os id=3'
    - done: true
      test: application/vendor/bin/phpunit --filter testDevolucaoDeEstoqueRodaDentroDaTransacao tests/Unit/OsVendasTransacaoTest.php
      text: O callable de devolução de estoque roda uma vez, antes de apagar os itens, e entra no rollback
    - done: true
      test: application/vendor/bin/phpunit --filter 'testExclusaoDaOsFaturadaApagaALancamento|testExclusaoDaOsApagaACobrancaCancelada' tests/Unit/OsVendasTransacaoTest.php
      text: Exclusão de OS faturada apaga o lançamento em aberto e a cobrança cancelada quando pedido
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDeOsInexistenteDevolveFalse tests/Unit/OsVendasTransacaoTest.php
      text: OS inexistente devolve false
    - done: true
      test: application/vendor/bin/phpunit --filter 'testExclusaoDaVenda' tests/Unit/OsVendasTransacaoTest.php
      text: Vendas_model::excluirComDependentes apaga itens, venda e lançamento 41; com falha no DELETE da venda os itens continuam e loga 'transacao rollback operacao=excluir_venda id=9'
    - done: true
      test: application/vendor/bin/phpunit --filter testFaturarOsGravaTudo tests/Unit/OsVendasTransacaoTest.php
      text: Os_model::faturar devolve o id do lançamento e grava faturado 1, lancamento e status Faturado com histórico Aberto → Faturado
    - done: true
      test: application/vendor/bin/phpunit --filter testFaturarComFalhaNaOsNaoCriaLancamento tests/Unit/OsVendasTransacaoTest.php
      text: Os_model::faturar com falha no UPDATE da OS devolve null, não cria lançamento e mantém faturado 0
    - done: true
      test: application/vendor/bin/phpunit --filter testFaturarComFalhaNoHistoricoReverte tests/Unit/OsVendasTransacaoTest.php
      text: Os_model::faturar com falha no histórico devolve null e reverte lançamento e OS
    - done: true
      test: application/vendor/bin/phpunit --filter testFaturarVenda tests/Unit/OsVendasTransacaoTest.php
      text: Vendas_model::faturar grava lancamentos_id; com falha no UPDATE devolve null sem lançamento
    - done: true
      test: application/vendor/bin/phpunit --filter testRegistrarStatus tests/Unit/OsVendasTransacaoTest.php
      text: registrarStatus devolve true ao gravar ou sem nada a gravar e false com log 'os historico falha os=3' quando o INSERT falha
    - done: true
      test: grep -q 'excluirComDependentes(' application/controllers/Os.php && grep -q 'excluirComDependentes(' application/controllers/Vendas.php && grep -q 'Não foi possível excluir. Nada foi alterado.' application/controllers/Os.php && grep -q 'Não foi possível excluir. Nada foi alterado.' application/controllers/Vendas.php
      text: Os::excluir e Vendas::excluir usam excluirComDependentes e mostram 'Não foi possível excluir. Nada foi alterado.' na falha
    - done: true
      test: grep -q 'os_model->faturar(' application/controllers/Os.php && grep -q 'vendas_model->faturar(' application/controllers/Vendas.php && ! grep -nE 'trans_(start|complete)\(' application/controllers/Os.php application/controllers/Vendas.php
      text: Os faturar() dos dois controllers delegam ao model e não abrem transação no controller
    - done: true
      test: grep -q 'armazenamentoApagar(' application/controllers/Os.php && ! grep -n '@unlink' application/controllers/Os.php
      text: Os arquivos dos anexos saem por armazenamentoApagar, sem @unlink
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/OsListagemTest.php tests/Unit/VendasListagemTest.php tests/Unit/VendasTelaTest.php tests/Unit/OsTelaTest.php tests/Unit/OsFormularioTest.php
      text: As suítes de OS e vendas existentes continuam verdes com as asserções de código atualizadas
deps:
    - TASK-001
diff_base: a5c8f783a5fa6ac3a9122bb936a3a071b4a83967
diff_tip: 9498e35785228750d1bb42d24fdccf1cf221a5fa
files:
    - application/models/Os_model.php
    - application/models/Vendas_model.php
    - application/controllers/Os.php
    - application/controllers/Vendas.php
    - tests/Unit/OsVendasTransacaoTest.php
    - tests/Unit/OsListagemTest.php
    - tests/Unit/VendasListagemTest.php
    - tests/Unit/VendasTelaTest.php
id: TASK-015
linter: composer analyse
n45_version: 1.1.0
outcome: |
    **Backend — contrato entregue (TASK-015)**

    - `Os_model::excluirComDependentes(int $idOs, bool $excluirCobranca, ?int $idLancamentoFatura, bool $faturada, ?callable $dentroDaTransacao = null): bool` — `true` = tudo apagado e commitado; `false` = rollback (nada mudou), log `error` `transacao rollback operacao=excluir_os id=<id>`. O callable roda uma vez, após o BEGIN e antes de apagar itens, e entra no rollback. OS inexistente devolve `false`.
    - `Vendas_model::excluirComDependentes(int $idVenda, bool $excluirCobranca, ?int $idLancamentoFatura, bool $faturada, ?callable $dentroDaTransacao = null): bool` — idem, log `operacao=excluir_venda`.
    - `Os_model::faturar(int $idOs, array $lancamento, array $dadosDaOs, string $statusAnterior, ?int $usuario): ?int` — id do lançamento criado; `null` = rollback, log `operacao=faturar_os`. Grava `os.lancamento` e o histórico `statusAnterior → status`.
    - `Vendas_model::faturar(int $idVenda, array $lancamento, array $dadosDaVenda): ?int` — grava `vendas.lancamentos_id`; `null` = rollback, log `operacao=faturar_venda`.
    - `Os_model::registrarStatus(...)`: agora `bool` — `true` gravado ou nada a gravar; `false` com log `os historico falha os=<id>`.
    - Painel: `Os::excluir`/`Vendas::excluir` mostram flashdata `error` "Não foi possível excluir. Nada foi alterado." na falha; arquivos de anexo saem por `armazenamentoApagar()` só após o commit. `Os::faturar`/`Vendas::faturar` respondem 500 `{"result": false, "message": "Não foi possível faturar a OS|venda. Nada foi alterado."}` quando o model devolve `null`.
persona: backend
phase: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/OsVendasTransacaoTest.php tests/Unit/OsListagemTest.php tests/Unit/VendasListagemTest.php tests/Unit/VendasTelaTest.php tests/Unit/OsTelaTest.php
title: Exclusão e faturamento de OS e vendas em transação
updated_at: "2026-11-03 19:47:40"
---
## Contexto

Excluir uma OS ou uma venda apaga várias tabelas em sequência **sem transação** e responde sucesso incondicional: se o banco falhar no meio (com `db_debug` desligado em produção nenhuma exceção aparece), sobram itens sem OS, lançamento sem venda, estoque devolvido de algo que não foi apagado — e o usuário vê "excluída com sucesso". O faturamento já usa `trans_start()`, mas não confere o retorno da gravação da OS/venda nem do histórico de status (`Os_model::registrarStatus()` devolve `void`). Esta task leva os dois fluxos para **métodos transacionais no model** (testáveis com SQLite), com rollback, log e mensagem de erro ao usuário (RF-33 para OS/venda no painel, RF-35 para faturamento e `registrarStatus`).

Estado atual (ler antes de mexer):

- `Os::excluir()` (`application/controllers/Os.php`): confere `dOs`; busca a OS (`getByIdCobrancas`/`getById`); com cobrança vinculada só segue se o status da cobrança é `'canceled'` (aí apaga a cobrança), senão recusa; recusa OS faturada com fatura baixada (`faturaBaixada()`); devolve estoque se o status da OS ≠ `cancelado` (`devolucaoEstoque($id)`, que chama `produtos_model->updateEstoque()` e `log_info()`); apaga os arquivos dos anexos (`osArquivosDosAnexos()` + `@unlink`); apaga `servicos_os`, `produtos_os`, `anexos`, `anotacoes_os`, `os_historico` (se existir), `os`; se faturada, `excluirFatura($id, $lancamento)` (depois da OS, por causa da FK `os.lancamento`); `log_info` e sucesso.
- `Vendas::excluir()` (`application/controllers/Vendas.php`): igual em espírito — `isEditable`, cobrança `'canceled'`, devolução de estoque se ≠ `cancelado`, apaga `itens_de_vendas` e `vendas`, depois `excluirFatura($id, $lancamentos_id)` (FK `vendas.lancamentos_id`).
- `Os::faturar()` / `Vendas::faturar()`: `trans_start()`, `add('lancamentos', $lancamento, true)`, `edit('os'|'vendas', [...])`, (OS) `registrarStatus(...)`, `trans_complete()`, `trans_status()`; respondem JSON pelo `responderJson(int $status, array $corpo)` privado.
- `Os_model::delete()`/`Vendas_model::delete()` devolvem `affected_rows() == 1`; `edit()` sempre `true`; `excluirFatura()` devolve `void`; `Os_model::historicoDisponivel()` diz se `os_historico` existe.
- Helpers usados por `excluirFatura`: `osDescricoesDaFatura()` (os_helper) e `vendaDescricoesDaFatura()` (vendas_helper), já carregados no `tests/bootstrap.php`. Para apagar arquivo sem `@`, já existe `armazenamentoApagar(string $caminho): void` (`application/helpers/armazenamento_helper.php`, loga a falha).
- Testes que **leem o código** do `excluir()` e vão quebrar com a mudança (atualizar a asserção para a chamada nova; o comportamento passa a ser provado no teste do model): `tests/Unit/OsListagemTest.php::testControllerEViewSeguemOPadrao` (exige `delete('anotacoes_os', 'os_id', $id)` e `excluirFatura(` no controller), `tests/Unit/VendasListagemTest.php` (exige `excluirFatura(`), `tests/Unit/VendasTelaTest.php::testExcluirDevolveEstoqueSalvoCanceladaEApagaAFatura` (exige `$this->devolucaoEstoque($id)` antes de `delete('itens_de_vendas'`).

Regras mantidas: cobrança ativa impede excluir; OS com fatura baixada não é excluída; venda não editável não é excluída; estoque só volta quando o status não é `cancelado`. A API de OS (fase seguinte) vai reutilizar `Os_model::excluirComDependentes()` — a assinatura é contrato.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `Os_model::excluirComDependentes(3, false, null, false)` com OS 3, 2 serviços, 1 produto, 1 anexo, 1 anotação, 2 linhas de histórico | `true`; nenhuma linha com `os_id = 3`/`idOs = 3` sobra nas seis tabelas |
| Mesma chamada com trigger que faz o `DELETE` de `os` falhar | `false`; serviços, produtos, anexo, anotação, histórico e a OS **continuam**; log `error` contendo `transacao rollback operacao=excluir_os id=3` |
| `excluirComDependentes(3, true, null, false)` com cobrança `os_id = 3` | cobrança também apagada; com falha → cobrança continua |
| `excluirComDependentes(3, false, 40, true)` com lançamento 40 em aberto | lançamento 40 apagado junto (pelas regras de `excluirFatura`) |
| `excluirComDependentes(3, false, null, false, $devolver)` | `$devolver` é chamado **uma vez**, dentro da transação e **antes** de apagar os itens (no momento da chamada, `produtos_os` da OS 3 ainda tem as linhas); falha posterior desfaz o que ele gravou |
| `excluirComDependentes(99, ...)` (OS inexistente) | `false` (o `DELETE` de `os` não afetou 1 linha); log de rollback |
| `Vendas_model::excluirComDependentes(9, false, 41, true)` com 2 itens e lançamento 41 | `true`; itens, venda e lançamento 41 apagados |
| `Vendas_model::excluirComDependentes(9, ...)` com falha no `DELETE` de `vendas` | `false`; os 2 itens continuam; log `transacao rollback operacao=excluir_venda id=9` |
| `Os_model::faturar(3, $lancamento, ['faturado' => 1, 'status' => 'Faturado', ...], 'Aberto', 1)` | devolve o id do lançamento criado (int > 0); `os.faturado = 1`, `os.lancamento = <id>`, `os.status = 'Faturado'`; histórico `Aberto → Faturado` gravado |
| `Os_model::faturar(...)` com falha no `UPDATE` da OS | `null`; **nenhum** lançamento criado; `os.faturado` continua 0; log `transacao rollback operacao=faturar_os id=3` |
| `Os_model::faturar(...)` com falha na gravação do histórico | `null`; lançamento e OS revertidos |
| `Os_model::registrarStatus(3, 'Aberto', 'Faturado', 1)` | `true` e uma linha em `os_historico` |
| `registrarStatus(3, 'Aberto', 'Aberto', 1)` ou sem a tabela | `true` (nada a gravar) |
| `registrarStatus(...)` com o INSERT falhando (`db_debug` desligado) | `false` e log `error` `os historico falha os=3` |
| `Vendas_model::faturar(9, $lancamento, ['faturado' => 1, 'status' => 'Faturado', ...])` | id do lançamento; `vendas.lancamentos_id = <id>`; falha no UPDATE → `null`, sem lançamento |
| Painel: `POST /os/excluir` quando o model devolve `false` | flashdata `error` `Não foi possível excluir. Nada foi alterado.`; nenhum arquivo de anexo apagado; volta à listagem |
| Painel: `POST /os/excluir` com sucesso | arquivos dos anexos apagados **depois** do commit, por `armazenamentoApagar()`; `log_info` e flashdata `success` como hoje |
| Painel: `POST /os/faturar` / `POST /vendas/faturar` quando o model devolve `null` | 500 `{"result": false, "message": "Não foi possível faturar a OS. Nada foi alterado."}` / `"... a venda. Nada foi alterado."`; nada de flashdata de sucesso |

## TDD

Arquivo novo `tests/Unit/OsVendasTransacaoTest.php` (`final class OsVendasTransacaoTest extends MaposTestCase`), com `require_once` de `APPPATH . 'models/Os_model.php'` e `APPPATH . 'models/Vendas_model.php'`. Models montados como nos testes existentes (`makeInstance` + `$model->db = $this->db`, ver `tests/Unit/OsListagemTest.php::model()`). Tabelas mínimas no SQLite: `os`, `servicos_os`, `produtos_os`, `anexos`, `anotacoes_os`, `os_historico`, `cobrancas`, `lancamentos`, `vendas`, `itens_de_vendas`, só com as colunas usadas. Falhas por trigger, ex.: `CREATE TRIGGER falha BEFORE DELETE ON os BEGIN SELECT RAISE(ABORT, 'falha simulada'); END`.

```php
public function testFalhaNoMeioDaExclusaoDaOsMantemTudo(): void
{
    $this->osCompleta(3);
    $this->db->query("CREATE TRIGGER falha BEFORE DELETE ON os BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

    $this->assertFalse($this->osModel()->excluirComDependentes(3, false, null, false));

    $this->assertSame(
        ['servicos_os' => 2, 'produtos_os' => 1, 'anexos' => 1, 'anotacoes_os' => 1, 'os_historico' => 2, 'os' => 1],
        $this->contagensDaOs(3)
    );
    $this->assertLogContem('error', 'transacao rollback operacao=excluir_os id=3');
}

public function testFaturarComFalhaNaOsNaoCriaLancamento(): void
{
    $this->db->insert('os', ['idOs' => 3, 'status' => 'Aberto', 'faturado' => 0]);
    $this->db->query("CREATE TRIGGER falha BEFORE UPDATE ON os BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

    $this->assertNull($this->osModel()->faturar(3, ['descricao' => 'Fatura de OS Nº: 3', 'valor' => 150.9, 'data_vencimento' => '2026-11-03', 'usuarios_id' => 1], ['faturado' => 1, 'status' => 'Faturado'], 'Aberto', 1));
    $this->assertSame(0, $this->db->count_all('lancamentos'));
    $this->assertSame('0', (string) $this->db->where('idOs', 3)->get('os')->row()->faturado);
}
```

Cobrir os demais casos da tabela com os nomes fixados nos critérios. Nos três testes que leem o código do `excluir()` (`OsListagemTest`, `VendasListagemTest`, `VendasTelaTest`), trocar as asserções que procuram as chamadas antigas por `excluirComDependentes(` e manter as que continuam válidas (`osArquivosDosAnexos(`, `listagemQuery(`, a checagem de status `cancelado`, `devolucaoEstoque(`).

**Refatoração:** remover de `Os::excluir()`/`Vendas::excluir()` as chamadas `delete(...)`/`excluirFatura(...)` que passaram para o model, e o `@unlink`.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/models/Os_model.php` | Modificar | `excluirComDependentes()`, `faturar()`, `registrarStatus(): bool` |
| `application/models/Vendas_model.php` | Modificar | `excluirComDependentes()`, `faturar()` |
| `application/controllers/Os.php` | Modificar | `excluir()` e `faturar()` usando o model; arquivos apagados após o sucesso |
| `application/controllers/Vendas.php` | Modificar | `excluir()` e `faturar()` usando o model |
| `tests/Unit/OsVendasTransacaoTest.php` | Criar | testes dos métodos transacionais |
| `tests/Unit/OsListagemTest.php` | Modificar | asserção de código do `excluir()` |
| `tests/Unit/VendasListagemTest.php` | Modificar | asserção de código do `excluir()` |
| `tests/Unit/VendasTelaTest.php` | Modificar | asserção de código do `excluir()` |

### Detalhamento Técnico

1. **Assinaturas fixadas (a API de OS reutiliza):**

```php
// application/models/Os_model.php
/**
 * Exclui a OS e o que é dela numa transação. $dentroDaTransacao roda logo após o BEGIN
 * (ex.: devolver o estoque) e entra no mesmo rollback.
 */
public function excluirComDependentes(int $idOs, bool $excluirCobranca, ?int $idLancamentoFatura, bool $faturada, ?callable $dentroDaTransacao = null): bool;
/** @return int|null id do lançamento criado; null = rollback, nada mudou */
public function faturar(int $idOs, array $lancamento, array $dadosDaOs, string $statusAnterior, ?int $usuario): ?int;
public function registrarStatus(int $idOs, ?string $anterior, string $novo, ?int $usuario): bool; // era void

// application/models/Vendas_model.php
public function excluirComDependentes(int $idVenda, bool $excluirCobranca, ?int $idLancamentoFatura, bool $faturada, ?callable $dentroDaTransacao = null): bool;
public function faturar(int $idVenda, array $lancamento, array $dadosDaVenda): ?int;
```

2. **Molde da transação** (o mesmo nos quatro métodos; com `db_debug` ligado o CI desfaz a transação e lança, por isso o `catch`; com ele desligado a query devolve `false` e `trans_status()` vira `false`):

```php
public function excluirComDependentes(int $idOs, bool $excluirCobranca, ?int $idLancamentoFatura, bool $faturada, ?callable $dentroDaTransacao = null): bool
{
    $this->db->trans_begin();

    try {
        if ($dentroDaTransacao !== null) {
            $dentroDaTransacao();
        }
        if ($excluirCobranca) {
            $this->db->where('os_id', $idOs)->delete('cobrancas');
        }
        foreach (['servicos_os', 'produtos_os', 'anexos', 'anotacoes_os'] as $tabela) {
            $this->db->where('os_id', $idOs)->delete($tabela);
        }
        if ($this->historicoDisponivel()) {
            $this->db->where('os_id', $idOs)->delete('os_historico');
        }
        $this->db->where('idOs', $idOs)->delete('os');
        $apagou = $this->db->affected_rows() === 1;
        if ($apagou && $faturada) {
            $this->excluirFatura($idOs, $idLancamentoFatura); // depois da OS: FK os.lancamento
        }

        if ($apagou && $this->db->trans_status() !== false) {
            $this->db->trans_commit();

            return true;
        }
    } catch (\Throwable $e) {
        // segue para o rollback
    }

    $this->db->trans_rollback();
    log_message('error', sprintf('transacao rollback operacao=excluir_os id=%d', $idOs));

    return false;
}
```

   - `Vendas_model::excluirComDependentes`: mesma forma — callable, cobrança (`vendas_id`), `itens_de_vendas`, `vendas` (`affected_rows() === 1`), `excluirFatura` se faturada; log `operacao=excluir_venda`.
   - `Os_model::faturar`: `insert('lancamentos', $lancamento)` → `$id = (int) $this->db->insert_id()`; `$id > 0`; `update('os', $dadosDaOs + ['lancamento' => $id])` por `idOs`; `registrarStatus($idOs, $statusAnterior, (string) ($dadosDaOs['status'] ?? 'Faturado'), $usuario)` tem de ser `true`; `trans_status()`; commit e `return $id`; senão rollback, log `operacao=faturar_os` e `null`.
   - `Vendas_model::faturar`: igual sem histórico, com `['lancamentos_id' => $id]`; log `operacao=faturar_venda`.
   - `registrarStatus`: mantém as regras atuais de "nada a gravar" (`true`); o `insert` devolvendo `false` → `log_message('error', sprintf('os historico falha os=%d', $idOs))` e `false`. Os outros chamadores (Os::adicionar/editar, Mine, Financeiro_model) continuam funcionando com o retorno ignorado — conferir o retorno deles é de outras tasks.

3. **Controllers**:
   - `Os::excluir()`: manter todas as checagens de hoje até a decisão de excluir. Calcular **antes** `$arquivos = osArquivosDosAnexos($this->os_model->getAnexos($id), FCPATH . 'assets' . DIRECTORY_SEPARATOR . 'anexos');`. Chamar `excluirComDependentes($id, isset($os->idCobranca) /* só chega aqui com cobrança cancelada */, isset($osStockRefund->lancamento) ? (int) $osStockRefund->lancamento : null, (int) $osStockRefund->faturado === 1, strtolower($osStockRefund->status) !== 'cancelado' ? fn () => $this->devolucaoEstoque($id) : null)`. `false` → flashdata `error` `Não foi possível excluir. Nada foi alterado.` e redirect. `true` → `foreach ($arquivos as $arquivo) { armazenamentoApagar($arquivo); }`, `log_info`, sucesso.
   - `Vendas::excluir()`: idem com `devolucaoEstoque($id)` no callable quando o status ≠ `cancelado`.
   - `Os::faturar()`/`Vendas::faturar()`: trocar o bloco `trans_start … trans_status` por `$idLancamento = $this->os_model->faturar($idOs, $lancamento, ['faturado' => 1, 'valorTotal' => $totais['bruto'], 'desconto' => $totais['desconto'], 'valor_desconto' => $totais['total'], 'status' => 'Faturado'], (string) $os->status, $this->usuarioLogado());` (venda: `['faturado' => 1, 'valorTotal' => $totais['bruto'], 'valor_desconto' => $totais['total'], 'status' => 'Faturado']`). `null` → `responderJson(500, ['result' => false, 'message' => 'Não foi possível faturar a OS. Nada foi alterado.'])` (venda: `a venda`). Sucesso igual a hoje.

**Contrato com camadas adjacentes:**

```
Produz para: Os/Vendas (painel) e, na fase seguinte, a API de OS (exclusão)
  - excluirComDependentes: true = tudo apagado e commitado; false = rollback (nada mudou), log 'transacao rollback operacao=excluir_os|excluir_venda id=<id>'
  - faturar: int = id do lançamento criado; null = rollback, log 'transacao rollback operacao=faturar_os|faturar_venda id=<id>'
  - registrarStatus: true = gravado ou nada a gravar; false = falha (log 'os historico falha os=<id>')
Consome de: banco (os, servicos_os, produtos_os, anexos, anotacoes_os, os_historico, cobrancas, lancamentos, vendas, itens_de_vendas) — uma conexão, uma transação
```
