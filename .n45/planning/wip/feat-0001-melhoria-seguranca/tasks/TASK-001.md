---
checkpoint: null
complexity: P
created_at: "2026-11-03 15:54:25"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testLogDeErroFicaRegistradoComONivelEmMinusculas tests/Unit/SuporteDeTesteTest.php
      text: log_message('ERROR', ...) é capturado e $this->logs('error') devolve exatamente as mensagens daquele nível, em minúsculas
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/SuporteDeTesteTest.php && grep -qE '__mapos_logs.*=.*\[\]' tests/MaposTestCase.php
      text: 'Os logs são zerados entre testes: um teste novo começa com $this->logs() === []'
    - done: true
      test: grep -cE 'AssertionFailedError' tests/Unit/SuporteDeTesteTest.php | grep -qvE '^[0-2]$'
      text: assertLogContem falha quando nenhum log do nível contém o trecho e assertLogNaoContem falha quando algum log contém o trecho (testes com expectException(AssertionFailedError))
    - done: true
      test: application/vendor/bin/phpunit --filter Deprecated tests/Unit/SuporteDeTesteTest.php
      text: semDeprecated() reprova Deprecated disparado dentro da raiz informada, ignora o de fora dela e devolve o retorno da ação
    - done: true
      test: grep -nE 'finally' tests/MaposTestCase.php && grep -nE 'restore_error_handler' tests/MaposTestCase.php
      text: semDeprecated() restaura o error handler anterior mesmo quando a ação lança
    - done: true
      test: '! grep -nE ''assertTrue\(true\)|assertSame\(1, 1\)'' tests/Unit/SuporteDeTesteTest.php'
      text: Os testes dos helpers não usam asserção sempre-verde
    - done: true
      test: composer test
      text: A suíte inteira continua passando com o novo stub de log
deps: []
diff_base: 4beb058dd429d4e0abd72609b78beae45d7e1b41
diff_tip: 6b7146759a20684847c695f666c3b11274131805
files:
    - tests/bootstrap.php
    - tests/MaposTestCase.php
    - tests/Unit/SuporteDeTesteTest.php
    - tests/Support/dispara_deprecated.php
id: TASK-001
linter: composer analyse
n45_version: 1.1.0
outcome: |
    Contrato de suporte de teste entregue em `tests/MaposTestCase.php` (nenhum código de produção alterado):

    - `protected function logs(?string $nivel = null): array` — lista de mensagens capturadas por `log_message()`, nível normalizado em minúsculas; zerada no `setUp()`.
    - `protected function assertLogContem(string $nivel, string $trecho): void` — falha se nenhuma mensagem do nível contém o trecho.
    - `protected function assertLogNaoContem(string $trecho): void` — falha se qualquer mensagem, de qualquer nível, contém o trecho.
    - `protected function semDeprecated(callable $acao, ?string $raiz = null): mixed` — falha com `arquivo:linha mensagem` se houver Deprecated sob `$raiz` (padrão `APPPATH`, exceto `application/vendor/`); devolve o retorno da ação; handler sempre restaurado (`finally`).
    - Stub de `log_message()` em `tests/bootstrap.php` acumula em `$GLOBALS['__mapos_logs']` (`['nivel','mensagem']`).
persona: backend
phase: 'Phase 1 — Base de segurança: schema, API, gateways e avisos de sessão'
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/SuporteDeTesteTest.php
title: 'Suporte de teste: captura de log e falha em Deprecated'
updated_at: "2026-11-03 17:14:37"
---
## Contexto

A suíte PHPUnit do Map-OS (PHPUnit 12, `tests/bootstrap.php` + `tests/MaposTestCase.php`) não tem como provar duas coisas que as próximas tasks de segurança precisam assertar: **o que foi escrito no log** e **que um fluxo não gerou `Deprecated` do PHP 8.5**. Hoje o `tests/bootstrap.php` define `log_message()` como stub vazio (antes de carregar o `Common.php` do CodeIgniter, que só declara a função se ela não existir), então todo `log_message('error', ...)` some. Várias tasks seguintes vão exigir "linha `api excecao` no log", "linha `gateway op=` sem CPF", "falha de envio não escreve o corpo no log" etc., e outras exigem "executar o fluxo não gera `Deprecated` de `null` em função de string".

Esta task é a **fundação compartilhada de teste**: muda só o suporte da suíte (nenhum código de produção) e entrega dois recursos que as demais consomem sem recriar. Nenhuma outra task deve mexer em `tests/bootstrap.php` ou `tests/MaposTestCase.php` para isso.

Padrões do projeto que valem aqui: testes em `tests/Unit/<Assunto>Test.php`, classe `final` estendendo `MaposTestCase`, métodos `testComportamento` em português, executando o comportamento (nunca lendo o código-fonte). `phpunit.xml` tem `failOnWarning`, `failOnRisky` e `beStrictAboutOutputDuringTests` ligados.

## Comportamento Esperado

1. **Captura de log.** O stub de `log_message($level, $message, $php_error = false)` do bootstrap passa a acumular cada chamada numa lista global (`$GLOBALS['__mapos_logs']`, itens `['nivel' => string, 'mensagem' => string]`), na ordem. O nível é gravado em minúsculas (o CI aceita `'ERROR'` e `'error'`). A lista é zerada no `setUp()` de `MaposTestCase`, então cada teste vê só o próprio log.
2. **Helpers em `MaposTestCase`:**
   - `protected function logs(?string $nivel = null): array` — lista de mensagens (strings), filtrada pelo nível quando informado.
   - `protected function assertLogContem(string $nivel, string $trecho): void` — falha se nenhuma mensagem daquele nível contém o trecho (mensagem de falha mostra os logs capturados).
   - `protected function assertLogNaoContem(string $trecho): void` — falha se **qualquer** mensagem, de qualquer nível, contém o trecho.
   - `protected function semDeprecated(callable $acao): mixed` — executa `$acao` com um error handler temporário para `E_DEPRECATED | E_USER_DEPRECATED`; se algum `Deprecated` for disparado por arquivo dentro de `application/` **fora de** `application/vendor/`, o teste falha com `arquivo:linha mensagem`; deprecations vindas de `application/vendor/` (o `DB_driver` do CI cria propriedades dinâmicas, já coberto pelo `phpunit-baseline.xml`) são ignoradas. Devolve o retorno de `$acao`. O handler anterior é **sempre** restaurado (`finally`), inclusive quando `$acao` lança.

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `log_message('error', 'gateway op=criar cobranca=7')` e depois `$this->logs('error')` | `['gateway op=criar cobranca=7']` |
| `log_message('ERROR', 'x')` e `log_message('info', 'y')`; `$this->logs('error')` | `['x']` (nível normalizado para minúsculas, `info` filtrado) |
| `assertLogContem('error', 'op=criar')` sem nenhum log no teste | falha (`AssertionFailedError`) |
| `assertLogNaoContem('529.982.247-25')` com log `'cpf=529.982.247-25'` | falha |
| Teste A grava log; teste B chama `$this->logs()` | B recebe `[]` (zerado no `setUp`) |
| `semDeprecated(fn () => 42)` | devolve `42` |
| `semDeprecated()` com ação que faz `trigger_error('x', E_USER_DEPRECATED)` num arquivo de `application/` | falha (`AssertionFailedError`) citando o arquivo |
| `semDeprecated()` com ação que lança `RuntimeException` | a exceção se propaga e o error handler anterior volta a ser o ativo |

## TDD

Escrever primeiro `tests/Unit/SuporteDeTesteTest.php` (classe `final SuporteDeTesteTest extends MaposTestCase`). Estilo de asserção (valor exato):

```php
public function testLogDeErroFicaRegistradoComONivelEmMinusculas(): void
{
    log_message('ERROR', 'gateway op=criar cobranca=7');
    log_message('info', 'outra coisa');

    $this->assertSame(['gateway op=criar cobranca=7'], $this->logs('error'));
}

public function testDeprecatedDaAplicacaoReprovaOTeste(): void
{
    $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

    $this->semDeprecated(static function (): void {
        // trigger_error dentro de um arquivo de application/ (ver Detalhamento, passo 4)
        mapos_teste_dispara_deprecated();
    });
}
```

Cobrir os demais casos da tabela. Controle negativo: escrever a asserção do primeiro teste com o valor errado (`['outra coisa']`), ver ficar vermelho, corrigir.

**Refatoração:** nenhuma prevista; se os helpers de asserção repetirem a montagem da mensagem de falha, extrair um método privado.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `tests/bootstrap.php` | Modificar | Stub de `log_message()` passa a acumular em `$GLOBALS['__mapos_logs']` |
| `tests/MaposTestCase.php` | Modificar | Zera os logs no `setUp()`; helpers `logs`, `assertLogContem`, `assertLogNaoContem`, `semDeprecated` |
| `tests/Unit/SuporteDeTesteTest.php` | Criar | Testes dos helpers (tabela acima) |
| `tests/Support/dispara_deprecated.php` | Criar | Função de apoio do teste que dispara `E_USER_DEPRECATED` (ver passo 4) |

### Detalhamento Técnico

1. `tests/bootstrap.php`: manter o `if (! function_exists('log_message'))` e trocar o corpo vazio por `$GLOBALS['__mapos_logs'][] = ['nivel' => strtolower((string) $level), 'mensagem' => (string) $message];`. Inicializar `$GLOBALS['__mapos_logs'] = [];` logo antes. Não mexer no restante do bootstrap.
2. `tests/MaposTestCase.php`: no `setUp()`, antes de abrir o SQLite, `$GLOBALS['__mapos_logs'] = [];`. Os helpers leem `$GLOBALS['__mapos_logs'] ?? []`.
3. `semDeprecated()`: `set_error_handler($handler, E_DEPRECATED | E_USER_DEPRECATED)`; o handler guarda `[$errfile, $errline, $errstr]` quando `$errfile` começa por `APPPATH` e **não** começa por `APPPATH . 'vendor' . DIRECTORY_SEPARATOR`, e devolve `true` (não deixa o PHPUnit transformar em aviso); para os demais devolve `false` (comportamento padrão). Depois de `$acao()`, se houve ocorrência, `$this->fail('Deprecated em <arquivo>:<linha>: <mensagem>')`. `restore_error_handler()` no `finally`.
4. O caso "deprecated vindo de `application/`" precisa de um arquivo que o handler reconheça como da aplicação. Não criar arquivo em `application/` só para teste: em vez disso, `semDeprecated()` aceita um segundo parâmetro opcional `?string $raiz = null` (padrão `APPPATH`) usado na comparação, e `tests/Support/dispara_deprecated.php` declara `function mapos_teste_dispara_deprecated(): void { trigger_error('teste', E_USER_DEPRECATED); }`. O teste chama `$this->semDeprecated(fn () => mapos_teste_dispara_deprecated(), MAPOS_ROOT . '/tests/Support/')` para o caso que reprova, e um segundo teste com a raiz padrão prova que o mesmo `Deprecated` vindo de fora de `application/` **não** reprova.
5. Nenhum outro teste existente deve mudar de comportamento: rodar a suíte inteira no fim.

**Contrato com as tasks seguintes (consumido por todas as tasks de backend):**

```php
// tests/MaposTestCase.php — assinaturas fixas, outras tasks chamam exatamente assim
protected function logs(?string $nivel = null): array;              // list<string>
protected function assertLogContem(string $nivel, string $trecho): void;
protected function assertLogNaoContem(string $trecho): void;
protected function semDeprecated(callable $acao, ?string $raiz = null): mixed;
```
