---
checkpoint: null
complexity: P
created_at: "2026-11-04 01:15:20"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testMarcaSoQuemTemOProprioDocumentoComoSenha tests/Unit/MarcarSenhasLegadasTest.php
      text: marcarSenhasLegadas() marca só os clientes com senha_trocar 0 cuja senha é o documento sem pontuação (inclusive CNPJ alfanumérico) e devolve 2 na tabela da task, sem tocar sem documento, sem senha ou já marcados
    - done: false
      test: application/vendor/bin/phpunit --filter testSegundaExecucaoNaoMarcaDeNovo tests/Unit/MarcarSenhasLegadasTest.php
      text: Segunda execução seguida devolve 0
    - done: false
      test: application/vendor/bin/phpunit --filter testFalhaDeBancoDevolveFalseELoga tests/Unit/MarcarSenhasLegadasTest.php
      text: Falha de banco faz o model devolver false com log error 'cliente marcar_senhas_legadas falha' e o comando imprimir 'Não foi possível marcar as senhas. Veja o log.'
    - done: false
      test: application/vendor/bin/phpunit --filter testComandoImprimeSoAQuantidade tests/Unit/MarcarSenhasLegadasTest.php
      text: php index.php tools marcarSenhasLegadas imprime exatamente '1 cliente(s) marcado(s)' e nenhum documento ou hash
    - done: false
      test: application/vendor/bin/phpunit --filter testAjudaListaOComando tests/Unit/MarcarSenhasLegadasTest.php
      text: Tools::help() lista o comando marcarSenhasLegadas
    - done: false
      test: application/vendor/bin/phpunit --filter testMarcarNaoMexeNaSenhaNemNasVersoes tests/Unit/MarcarSenhasLegadasTest.php
      text: O model não altera senha, sessao_versao nem token_versao ao marcar
    - done: false
      test: grep -qE 'password_verify\(\$documento' application/models/Clientes_model.php
      text: Comparação usa password_verify sobre o documento sem pontuação
    - done: false
      test: application/vendor/bin/phpunit tests/Unit/MarcarSenhasLegadasTest.php tests/Unit/SenhaClienteTest.php tests/Unit/ClientesSenhaInicialTest.php
      text: Suítes de cliente passam
    - done: false
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/models/Clientes_model.php application/controllers/Tools.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-001
    - TASK-002
files:
    - application/models/Clientes_model.php
    - application/controllers/Tools.php
    - tests/Unit/MarcarSenhasLegadasTest.php
id: TASK-026
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
tests: application/vendor/bin/phpunit tests/Unit/MarcarSenhasLegadasTest.php
title: Comando marcarSenhasLegadas para clientes com senha igual ao documento
updated_at: "2026-11-04 01:15:20"
---
## Contexto

Até esta feature, o cliente cadastrado pelo operador sem senha recebia como senha o próprio CPF/CNPJ sem pontuação (painel: `password_hash(preg_replace('/[^A-Za-z0-9]/', '', documento))` em `clienteDadosDoFormulario()`; API: o documento sem símbolos). O cadastro novo já gera senha aleatória (tasks anteriores), mas os clientes antigos continuam com o documento como senha — dado que qualquer um que conheça o cliente sabe. A migration `20261103000200` criou `clientes.senha_trocar TINYINT(1) NOT NULL DEFAULT 0`, e a área do cliente (`Mine::clienteLogado()`) e a API do cliente (`REST_Controller::logged_client()`) já forçam a troca enquanto `senha_trocar = 1`. Esta task entrega o comando opcional de CLI `php index.php tools marcarSenhasLegadas` (RF-26), que marca `senha_trocar = 1` só nos clientes cuja senha ainda é o documento, e imprime apenas a quantidade marcada. Forçar troca em massa de todos os clientes está fora de escopo: o comando só mexe em quem tem a senha igual ao documento.

Estado atual: `application/controllers/Tools.php` (`class Tools extends CI_Controller`) só roda em CLI (o construtor sai fora da linha de comando e carrega `dbforge`/`Seeder`); tem `help()` listando os comandos. `application/models/Clientes_model.php` já tem `registrarTrocaDeSenha()` e `excluirComDependentes()`.

## Comportamento Esperado

Clientes no banco (hash com `password_hash(..., PASSWORD_BCRYPT, ['cost' => 4])` no teste):

| Cliente | documento | senha (texto que gerou o hash) | senha_trocar antes | Marcado? |
| --- | --- | --- | --- | --- |
| 1 Ana | `123.456.789-09` | `12345678909` | 0 | **sim** → `senha_trocar = 1` |
| 2 Bia | `987.654.321-00` | `OutraSenha2026` | 0 | não |
| 3 Caio | `11.222.333/0001-81` | `11222333000181` | 1 | não (já marcado; não conta) |
| 4 Duda | `''` (vazio) | `qualquer` | 0 | não |
| 5 Eva | `12.ABC.345/01DE-35` (CNPJ alfanumérico) | `12ABC34501DE35` | 0 | **sim** |
| 6 Fábio | `555.666.777-88` | senha `NULL` | 0 | não (sem senha, sem erro) |

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `Clientes_model::marcarSenhasLegadas()` com a tabela acima | devolve `2`; clientes 1 e 5 com `senha_trocar = 1`; 2, 4 e 6 continuam `0`; 3 continua `1` |
| rodar de novo em seguida | devolve `0` (os marcados saem do filtro `senha_trocar = 0`) |
| `Tools::marcarSenhasLegadas()` com só o cliente 1 elegível | imprime exatamente `"1 cliente(s) marcado(s)" . PHP_EOL`; a saída não contém o documento (`123.456.789-09` nem `12345678909`) nem o hash |
| consulta ao banco falha (`get()` devolve `false`, simulado com a tabela `clientes` removida) | model devolve `false` e loga `error` `cliente marcar_senhas_legadas falha`; Tools imprime `"Não foi possível marcar as senhas. Veja o log." . PHP_EOL` |
| `Tools::help()` | lista a linha `php index.php tools marcarSenhasLegadas` |

Invariantes: só lê `idClientes`, `documento`, `senha` de quem tem `senha_trocar = 0`; nunca loga nem imprime documento, senha ou hash; não altera `senha`, `sessao_versao` nem `token_versao` (a troca obrigatória já é conferida a cada requisição pela área do cliente e pela API).

## TDD

Arquivo novo `tests/Unit/MarcarSenhasLegadasTest.php` (`final class MarcarSenhasLegadasTest extends MaposTestCase`), SQLite em memória (`$this->db`) com `CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, documento TEXT, senha TEXT NULL, senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)`. Model real: `$model = new Clientes_model(); $model->db = $this->db;` (como em `ApiClienteTokensTest::model()`). Tools: `$this->makeInstance(Tools::class)` (sem construtor) com `load` dublê (classe anônima com `__call` devolvendo null) e `clientes_model` injetado; capturar o `echo` com `ob_start()`/`ob_get_clean()`. Dublês sempre como classe anônima (nome de classe repetido entre arquivos de teste derruba a suíte com `Cannot redeclare class`).

```php
public function testMarcaSoQuemTemOProprioDocumentoComoSenha(): void
{
    $this->cliente(1, '123.456.789-09', '12345678909');
    $this->cliente(2, '987.654.321-00', 'OutraSenha2026');
    $this->cliente(3, '11.222.333/0001-81', '11222333000181', 1);
    $this->cliente(4, '', 'qualquer');
    $this->cliente(5, '12.ABC.345/01DE-35', '12ABC34501DE35');
    $this->cliente(6, '555.666.777-88', null);

    $this->assertSame(2, $this->model()->marcarSenhasLegadas());

    $marcados = array_map('intval', array_column($this->db->where('senha_trocar', 1)->order_by('idClientes')->get('clientes')->result_array(), 'idClientes'));
    $this->assertSame([1, 3, 5], $marcados);
}

public function testComandoImprimeSoAQuantidade(): void
{
    $this->cliente(1, '123.456.789-09', '12345678909');

    $saida = $this->rodarComando();

    $this->assertSame('1 cliente(s) marcado(s)' . PHP_EOL, $saida);
    $this->assertStringNotContainsString('12345678909', $saida);
}
```

Cobrir os demais casos da tabela (segunda execução devolve 0; falha de banco devolve `false` com log e mensagem do comando; `help()` lista o comando) com os nomes dos critérios.

**Refatoração:** Nenhuma.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/models/Clientes_model.php` | Modificar | `marcarSenhasLegadas(): int\|false` |
| `application/controllers/Tools.php` | Modificar | Comando `marcarSenhasLegadas()` e linha no `help()` |
| `tests/Unit/MarcarSenhasLegadasTest.php` | Criar | Model e comando |

### Detalhamento Técnico

1. **`Clientes_model::marcarSenhasLegadas()`** — assinatura `public function marcarSenhasLegadas(int $lote = 500): int|false`:
   - Percorrer por faixa de id (`where('idClientes >', $ultimoId)`, `where('senha_trocar', 0)`, `order_by('idClientes')`, `limit($lote)`, `select('idClientes, documento, senha')`) até a página vir vazia, para não carregar a tabela inteira na memória.
   - Para cada linha: `$documento = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $linha->documento);` — o mesmo filtro que o cadastro antigo usava. Documento vazio ou `senha` nula/vazia → pula. `password_verify($documento, (string) $linha->senha)` verdadeiro → guarda o id.
   - Ao fim de cada página com ids: `$this->db->where_in('idClientes', $ids)->where('senha_trocar', 0)->update('clientes', ['senha_trocar' => 1])`; somar `affected_rows()`.
   - Qualquer `get()` ou `update()` que devolva `false` → `log_message('error', 'cliente marcar_senhas_legadas falha')` (sem id, documento ou senha) e `return false`.
   - Devolve o total marcado.
   - Custo: um bcrypt por cliente elegível (dezenas de ms cada); é um comando de CLI avulso, documentado como demorado em bases grandes. Não há forma de evitar: o hash tem sal por cliente.
2. **`Tools::marcarSenhasLegadas()`**:

   ```php
   /**
    * Marca a troca obrigatória de senha nos clientes cuja senha ainda é o
    * próprio CPF/CNPJ sem pontuação (senha padrão até a v5). Imprime só a
    * quantidade: nunca o documento.
    */
   public function marcarSenhasLegadas()
   {
       $this->load->model('clientes_model');
       $marcados = $this->clientes_model->marcarSenhasLegadas();

       if ($marcados === false) {
           echo 'Não foi possível marcar as senhas. Veja o log.' . PHP_EOL;

           return;
       }

       echo $marcados . ' cliente(s) marcado(s)' . PHP_EOL;
   }
   ```

3. **`Tools::help()`**: acrescentar `php index.php tools marcarSenhasLegadas          Força a troca de senha dos clientes cuja senha é o CPF/CNPJ.` no mesmo alinhamento das linhas atuais.
