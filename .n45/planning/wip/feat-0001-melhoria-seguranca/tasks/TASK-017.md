---
checkpoint: null
complexity: M
created_at: "2026-11-03 22:50:15"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDoProdutoApagaSoOQueEDele tests/Unit/ProdutosServicosExclusaoTest.php
      text: Produtos_model::excluirComDependentes(7) devolve true e apaga o produto 7 com suas 2 linhas de produtos_os e 1 de itens_de_vendas, mantendo o produto 8 e as linhas dele
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNaExclusaoDoProdutoMantemTudo tests/Unit/ProdutosServicosExclusaoTest.php
      text: Falha simulada no DELETE de produtos devolve false, mantém 2 linhas em produtos_os, 1 em itens_de_vendas e o produto 7, e loga 'transacao rollback operacao=excluir_produto id=7'
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDeProdutoInexistenteDevolveFalse tests/Unit/ProdutosServicosExclusaoTest.php
      text: Produto inexistente (99) devolve false e loga 'transacao rollback operacao=excluir_produto id=99'
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDoServicoApagaSoOQueEDele tests/Unit/ProdutosServicosExclusaoTest.php
      text: Servicos_model::excluirComDependentes(4) devolve true e apaga o serviço 4 com suas 3 linhas de servicos_os, mantendo o serviço 5 e a linha dele
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNaExclusaoDoServicoMantemTudo tests/Unit/ProdutosServicosExclusaoTest.php
      text: Falha simulada no DELETE de servicos devolve false, mantém as 3 linhas de servicos_os e o serviço 4, e loga 'transacao rollback operacao=excluir_servico id=4'
    - done: true
      test: grep -q 'produtos_model->excluirComDependentes(' application/controllers/Produtos.php && grep -q 'Não foi possível excluir. Nada foi alterado.' application/controllers/Produtos.php
      text: Produtos::excluir chama excluirComDependentes e, com false, grava a mensagem 'Não foi possível excluir. Nada foi alterado.'
    - done: true
      test: grep -q 'servicos_model->excluirComDependentes(' application/controllers/Servicos.php && grep -q 'Não foi possível excluir. Nada foi alterado.' application/controllers/Servicos.php
      text: Servicos::excluir chama excluirComDependentes e, com false, grava a mensagem 'Não foi possível excluir. Nada foi alterado.'
    - done: true
      test: '! grep -nE "delete\(''(produtos_os|itens_de_vendas|servicos_os)''" application/controllers/Produtos.php application/controllers/Servicos.php'
      text: Os controllers não apagam mais as tabelas filhas por delete() solto no excluir()
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/ProdutosServicosExclusaoTest.php && ! grep -n 'assertTrue(true)' tests/Unit/ProdutosServicosExclusaoTest.php
      text: Suíte ProdutosServicosExclusaoTest passa inteira e cada teste asserta contagens exatas (assertSame), sem assertTrue(true)
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/models/Produtos_model.php application/models/Servicos_model.php application/controllers/Produtos.php application/controllers/Servicos.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-001
diff_base: bfe00b36514849eb18a94dd20c663b3756e06117
diff_tip: 1c5a547b582c703e9f0a4a8250c9f6950a33359f
files:
    - application/models/Produtos_model.php
    - application/models/Servicos_model.php
    - application/controllers/Produtos.php
    - application/controllers/Servicos.php
    - tests/Unit/ProdutosServicosExclusaoTest.php
id: TASK-017
linter: composer analyse
n45_version: 1.1.0
outcome: |
    ## Backend

    Métodos de model (consumidos pela TASK-021, API de produtos/serviços):

    - `Produtos_model::excluirComDependentes(int $idProduto): bool` — transação: apaga `produtos_os`, `itens_de_vendas` e `produtos` do id. `true` = tudo apagado e commitado. `false` = produto inexistente (affected_rows != 1), falha de query (`trans_status()`) ou `\Throwable`; rollback e `log_message('error', 'transacao rollback operacao=excluir_produto id=<id>')`.
    - `Servicos_model::excluirComDependentes(int $idServico): bool` — idem para `servicos_os` e `servicos`; operação de log `excluir_servico`.

    Controllers do painel:

    - `POST /produtos/excluir` e `POST /servicos/excluir`: com `false`, flash `error` = `Não foi possível excluir. Nada foi alterado.`, sem `log_info`, redirect para a listagem com filtros; com `true`, `log_info` + flash `success`. `Servicos::excluir` valida `id` como inteiro (`<= 0` → `Erro ao tentar excluir serviço.`).
    - `delete()` dos dois models mantido.
persona: backend
phase: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/ProdutosServicosExclusaoTest.php tests/Unit/ProdutosTest.php tests/Unit/ServicosTest.php
title: Exclusão de produtos e serviços em transação
updated_at: "2026-11-03 23:45:33"
---
## Contexto

A exclusão de produto e de serviço no painel apaga várias tabelas em sequência, sem transação, e mostra "excluído com sucesso" sem conferir nada: hoje `Produtos::excluir()` apaga `produtos_os`, `itens_de_vendas` e `produtos` em três chamadas soltas a `Produtos_model::delete()`, e `Servicos::excluir()` apaga `servicos_os` e `servicos` do mesmo jeito. Com `db_debug` desligado (produção), uma falha no meio (FK, deadlock, banco fora) deixa o produto sem os itens das OS/vendas, ou os itens apagados e o produto vivo, e o operador ainda vê o toast de sucesso.

Esta task leva para produtos e serviços o mesmo padrão que a TASK-015 já entregou para OS e vendas (`Os_model::excluirComDependentes()`): um método de model que apaga tudo numa transação e devolve `bool`, com log de rollback, e o controller do painel que só mostra sucesso com `true`. A API de produtos e serviços (TASK-021, fase seguinte do mesmo lote) vai consumir estes dois métodos — a assinatura abaixo é contrato.

Regras do projeto que valem aqui:

- Model só acessa dados pelo Query Builder do CodeIgniter (nunca SQL concatenado com entrada); devolve dados ou `false`; o controller decide a mensagem.
- Erro técnico vai para `log_message('error', ...)`; a trilha do usuário (`log_info()`) só é gravada depois do commit.
- Formato de log de rollback (fixado na feature): `transacao rollback operacao=<op> id=<id>`.
- `trans_status()` não captura exceção quando `db_debug` está ligado (development): conferir os dois — `try/catch (\Throwable)` + `trans_status()`.
- O comportamento atual de **o que** é apagado não muda: produto leva junto as linhas de `produtos_os` e `itens_de_vendas` dele; serviço leva as de `servicos_os`. Só passa a ser tudo-ou-nada.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `Produtos_model::excluirComDependentes(7)` com produto 7 ligado a 2 linhas de `produtos_os` e 1 de `itens_de_vendas`, e produto 8 com 1 linha em cada | `true`; produto 7, suas 2 linhas de `produtos_os` e 1 de `itens_de_vendas` apagados; produto 8 e as linhas dele intactos (1 em `produtos`, 1 em `produtos_os`, 1 em `itens_de_vendas`) |
| `Produtos_model::excluirComDependentes(7)` com falha simulada no `DELETE` de `produtos` (trigger `RAISE(ABORT)`) | `false`; `produtos_os` continua com 2 linhas do produto 7, `itens_de_vendas` com 1, `produtos` com o 7; log `error` contém `transacao rollback operacao=excluir_produto id=7` |
| `Produtos_model::excluirComDependentes(99)` (produto inexistente) | `false`; log `error` contém `transacao rollback operacao=excluir_produto id=99` |
| `Servicos_model::excluirComDependentes(4)` com serviço 4 em 3 linhas de `servicos_os` e serviço 5 em 1 | `true`; serviço 4 e as 3 linhas apagados; serviço 5 e sua linha intactos |
| `Servicos_model::excluirComDependentes(4)` com falha simulada no `DELETE` de `servicos` | `false`; as 3 linhas de `servicos_os` e o serviço 4 continuam; log `error` contém `transacao rollback operacao=excluir_servico id=4` |
| `POST /produtos/excluir` (`id=7`) e o model devolve `false` | flashdata `error` = `Não foi possível excluir. Nada foi alterado.`; **nenhum** `log_info`; redirect para a listagem com os filtros |
| `POST /produtos/excluir` (`id=7`) e o model devolve `true` | `log_info('Removeu um produto. ID: 7')`; flashdata `success` = `Produto excluído com sucesso!` (inalterado) |
| `POST /servicos/excluir` com `id=abc` ou sem `id` | flashdata `error` = `Erro ao tentar excluir serviço.`; model não é chamado |
| `POST /servicos/excluir` (`id=4`) e o model devolve `false` | flashdata `error` = `Não foi possível excluir. Nada foi alterado.`; sem `log_info` |

## TDD

Suíte nova `tests/Unit/ProdutosServicosExclusaoTest.php` (classe `final` estendendo `MaposTestCase`, SQLite em memória). Para simular a falha como em produção (query devolve `false`, sem exceção), use o mesmo recurso de `tests/Unit/OsVendasTransacaoTest.php::falhaAntesDe()`: `$this->db->db_debug = false`, `set_error_handler` silenciando `E_WARNING` (restaurado no `tearDown`) e `CREATE TRIGGER falha BEFORE DELETE ON <tabela> BEGIN SELECT RAISE(ABORT, 'falha simulada'); END`.

```php
public function testFalhaNaExclusaoDoProdutoMantemTudo(): void
{
    $model = $this->produtosModel(); // cria produtos, produtos_os, itens_de_vendas; injeta $this->db
    $this->db->query('INSERT INTO produtos (idProdutos, descricao) VALUES (7, ?)', ['Tela']);
    $this->db->query('INSERT INTO produtos_os (os_id, produtos_id) VALUES (1, 7), (2, 7)');
    $this->db->query('INSERT INTO itens_de_vendas (vendas_id, produtos_id) VALUES (1, 7)');
    $this->falhaAntesDe('DELETE', 'produtos');

    $this->assertFalse($model->excluirComDependentes(7));

    $this->assertSame(2, $this->db->where('produtos_id', 7)->count_all_results('produtos_os'));
    $this->assertSame(1, $this->db->where('produtos_id', 7)->count_all_results('itens_de_vendas'));
    $this->assertSame(1, $this->db->where('idProdutos', 7)->count_all_results('produtos'));
    $this->assertLogContem('error', 'transacao rollback operacao=excluir_produto id=7');
}
```

Cobrir os demais casos da tabela com os nomes dos critérios: `testExclusaoDoProdutoApagaSoOQueEDele`, `testFalhaNaExclusaoDoProdutoMantemTudo`, `testExclusaoDeProdutoInexistenteDevolveFalse`, `testExclusaoDoServicoApagaSoOQueEDele`, `testFalhaNaExclusaoDoServicoMantemTudo`.

**Refatoração:** depois do green, as duas suítes de model compartilham o `falhaAntesDe()` e a criação de tabelas — manter um helper privado por suíte, sem extrair para `MaposTestCase` (arquivo de outra task).

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/models/Produtos_model.php` | Modificar | Novo `excluirComDependentes(int $idProduto): bool` |
| `application/models/Servicos_model.php` | Modificar | Novo `excluirComDependentes(int $idServico): bool` |
| `application/controllers/Produtos.php` | Modificar | `excluir()` usa o método novo e só mostra sucesso com `true` |
| `application/controllers/Servicos.php` | Modificar | `excluir()` valida o id como inteiro e usa o método novo |
| `tests/Unit/ProdutosServicosExclusaoTest.php` | Criar | Suíte dos dois models |

### Detalhamento Técnico

1. `Produtos_model::excluirComDependentes(int $idProduto): bool` — `trans_begin()`; dentro de `try`: `DELETE produtos_os WHERE produtos_id`, `DELETE itens_de_vendas WHERE produtos_id`, `DELETE produtos WHERE idProdutos`; sucesso só se o último `affected_rows() === 1` **e** `trans_status() !== false` → `trans_commit()` e `true`. Qualquer outro caminho (inclusive `catch (\Throwable)`) → `trans_rollback()`, `log_message('error', sprintf('transacao rollback operacao=excluir_produto id=%d', $idProduto))`, `false`.
2. `Servicos_model::excluirComDependentes(int $idServico): bool` — idem com `servicos_os WHERE servicos_id` e `servicos WHERE idServicos`; operação de log `excluir_servico`.
3. `Produtos::excluir()` — mantém a checagem de permissão `dProduto` e de `id <= 0`; troca as três chamadas a `delete()` por `if (! $this->produtos_model->excluirComDependentes($id)) { set_flashdata('error', 'Não foi possível excluir. Nada foi alterado.'); redirect(<listagem com filtros>); }`; `log_info` e o flash de sucesso só depois.
4. `Servicos::excluir()` — `$id = (int) $this->input->post('id')`; `$id <= 0` → `Erro ao tentar excluir serviço.` como hoje; mesmo fluxo do item 3 com `servicos_model`.
5. Os métodos `delete()` dos dois models continuam existindo (outros pontos ainda os usam).

**Contrato com camadas adjacentes:**

```
Produz para: controllers Produtos/Servicos (painel) e, na TASK-021, ProdutosController/ServicosController (API)
  - Sucesso: true (tudo apagado e commitado)
  - Registro inexistente: false (DELETE afetou 0 linhas → rollback), log 'transacao rollback operacao=excluir_produto|excluir_servico id=<id>'
  - Erro de banco (query false ou exceção): false, mesmo log, nada alterado

Consome de: CI Query Builder ($this->db)
  - Detecta falha: affected_rows() !== 1 no DELETE principal, trans_status() === false, ou \Throwable
```

**Exemplo de implementação** (o ponto não óbvio: conferir os dois sinais de falha e logar só no rollback):

```php
public function excluirComDependentes(int $idProduto): bool
{
    $this->db->trans_begin();

    try {
        $this->db->where('produtos_id', $idProduto)->delete('produtos_os');
        $this->db->where('produtos_id', $idProduto)->delete('itens_de_vendas');
        $this->db->where('idProdutos', $idProduto)->delete('produtos');
        $apagou = $this->db->affected_rows() === 1;

        if ($apagou && $this->db->trans_status() !== false) {
            $this->db->trans_commit();

            return true;
        }
    } catch (\Throwable $e) {
        // segue para o rollback
    }

    $this->db->trans_rollback();
    log_message('error', sprintf('transacao rollback operacao=excluir_produto id=%d', $idProduto));

    return false;
}
```
