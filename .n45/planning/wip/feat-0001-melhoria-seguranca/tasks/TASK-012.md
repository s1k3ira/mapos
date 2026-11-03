---
checkpoint: null
complexity: G
created_at: "2026-11-03 18:46:02"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testLockOcupadoNaoEnviaNada tests/Unit/EmailFilaTest.php
      text: 'Lock ocupado: processarFila() devolve ignorado = true, não envia nada e não altera nenhuma linha'
    - done: false
      test: application/vendor/bin/phpunit --filter testCicloNormalEnviaEContaTentativa tests/Unit/EmailFilaTest.php
      text: 'Ciclo normal com 3 pending: todos sent com tentativas = 1 e retorno reservados 3, enviados 3, falhas 0, definitivos 0'
    - done: false
      test: application/vendor/bin/phpunit --filter testReservaNoMaximoUmLoteDeCinquenta tests/Unit/EmailFilaTest.php
      text: Com 60 pending só os 50 de menor id são reservados; os outros 10 seguem pending sem reservado_por
    - done: false
      test: application/vendor/bin/phpunit --filter 'testDuasExecucoesNaoReservamOMesmoEmail|testPendingComCincoTentativasNaoEReservado' tests/Unit/EmailFilaTest.php
      text: Duas execuções seguidas não reservam o mesmo e-mail e pending com tentativas = 5 não é reservado
    - done: false
      test: application/vendor/bin/phpunit --filter testExcecaoNumEmailNaoImpedeOsDemaisDoLote tests/Unit/EmailFilaTest.php
      text: 'Exceção num e-mail não impede os demais do lote: 1 e 3 sent, 2 failed com tentativas = 1'
    - done: false
      test: application/vendor/bin/phpunit --filter testQuintaFalhaEDefinitiva tests/Unit/EmailFilaTest.php
      text: Falha na quinta tentativa fica failed e conta em definitivos
    - done: false
      test: application/vendor/bin/phpunit --filter testFalhaNaoLogaOCorpo tests/Unit/EmailFilaTest.php
      text: Falha de envio loga 'email_fila falha id=2 tentativas=1' e nenhum log contém o marcador do corpo (link de reset)
    - done: false
      test: grep -qE "print_debugger\(\['headers'\]\)" application/libraries/MY_Email.php && ! grep -nE 'print_debugger\(\)' application/libraries/MY_Email.php
      text: O log de falha usa print_debugger só com cabeçalhos
    - done: false
      test: application/vendor/bin/phpunit --filter testRetryReabreSoFalhasComTentativasRestantes tests/Unit/EmailFilaTest.php
      text: 'reabrirFila(): failed com tentativas < 5 volta a pending e com 5 continua failed; retorno com reabertos exato'
    - done: false
      test: application/vendor/bin/phpunit --filter testReservaExpirada tests/Unit/EmailFilaTest.php
      text: 'reabrirFila(): sending com reserva de 16 min volta a pending (reserva limpa), com 5 tentativas vira failed, com 10 min continua sending, e o legado sem reservado_em segue a data'
    - done: false
      test: application/vendor/bin/phpunit --filter 'testCodigoDeSaidaZeroNoCicloNormal|testCodigoDeSaidaZeroComLockOcupado' tests/Unit/EmailFilaTest.php
      text: executarCiclo('process') normal devolve 0 e loga 'email_fila ciclo=process reservados=3 enviados=3 falhas=0 definitivos=0'; lock ocupado devolve 0 e loga 'ignorado'
    - done: false
      test: application/vendor/bin/phpunit --filter testCodigoDeSaidaUmComErroDeBanco tests/Unit/EmailFilaTest.php
      text: executarCiclo com erro de banco devolve 1 e loga 'email_fila ciclo=process erro=RuntimeException'
    - done: false
      test: test "$(grep -cE 'executarCiclo\(.(process|retry).,' application/controllers/Email.php)" -ge 2
      text: process() e retry() delegam a executarCiclo com a fila
    - done: false
      test: '! grep -nE "where\(\"\(date <|status = ''failed''\"\)" application/libraries/MY_Email.php'
      text: O retry não monta WHERE por concatenação de data (usa bindings)
    - done: false
      test: composer analyse
      text: PHPStan passa sem acrescentar entradas na baseline
deps:
    - TASK-001
    - TASK-002
files:
    - application/libraries/MY_Email.php
    - application/controllers/Email.php
    - tests/Unit/EmailFilaTest.php
    - phpstan-stubs/ci3.php
    - phpstan-baseline.neon
id: TASK-012
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
tests: application/vendor/bin/phpunit tests/Unit/EmailFilaTest.php
title: Fila de e-mail com lock, reserva atômica e tentativas
updated_at: "2026-11-03 18:46:02"
---
## Contexto

A fila de e-mail (`email_queue`) é processada pelo cron do sistema: `php index.php email/process` a cada 2 min e `php index.php email/retry` a cada 5 min (`application/controllers/Email.php`, só CLI). Hoje ela tem quatro defeitos:

- **Corrida**: `MY_Email::send_queue()` faz `SELECT` dos `pending` e depois `UPDATE ... SET status='sending' WHERE status='pending'` de **toda** a tabela; duas execuções sobrepostas enviam o mesmo e-mail duas vezes, e e-mails que entram entre o SELECT e o UPDATE ficam presos em `sending`.
- **Retry infinito**: `retry_queue()` volta **todo** `failed` para `pending`, para sempre (e monta o `WHERE` por concatenação de string).
- **Vazamento no log**: a falha loga `print_debugger()` completo, que inclui o **corpo** — com o link de redefinição de senha.
- **Sem sinal para o cron**: nada é logado no início/fim e o processo sempre sai 0.

Schema já aplicado (migration): `email_queue(id, to, cc, bcc, message, status ENUM('pending','sending','sent','failed'), date DATETIME, headers TEXT, tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0, reservado_por CHAR(32) NULL, reservado_em DATETIME NULL)`, índice `email_queue_status_reserva (status, reservado_por)`. Nenhum valor novo de `status`.

Classes envolvidas: `application/libraries/MY_Email.php` (`class MY_Email extends CI_Email`; propriedade `protected $CI` com a instância; `send($skip_job = false)` enfileira quando `false` e envia de verdade com `true` via `parent::send()`; `get()`, `set_status()`, `start_process()`, `send_queue()`, `retry_queue()`) e `application/controllers/Email.php`. O enfileiramento (`send()` sem argumento) **não muda**. A tela de e-mails do painel (`Mapos::emails`) lê `id, to, status, date, headers` e não muda.

A retenção (apagar `sent` antigos etc.) é da task seguinte, que vai acrescentar uma chamada no ciclo `retry` — deixe o ciclo extensível como descrito abaixo.

## Comportamento Esperado

Constantes: `MAX_TENTATIVAS = 5`, `LOTE = 50`, `RESERVA_EXPIRA_MINUTOS = 15`, `NOME_DO_LOCK = 'mapos_email_fila'`.

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `processarFila()` com o lock ocupado (outra execução) | `['ignorado' => true, 'reservados' => 0, 'enviados' => 0, 'falhas' => 0, 'definitivos' => 0]`; nenhuma linha muda; nenhum envio |
| 3 `pending` (ids 1, 2, 3, `tentativas = 0`), envio ok para todos | os 3 viram `sent`, `tentativas = 1`, `date` = agora; retorno `reservados 3, enviados 3, falhas 0, definitivos 0, ignorado false` |
| 60 `pending` | só os 50 de menor id reservados nesta execução; os outros 10 continuam `pending` com `reservado_por NULL` |
| `pending` com `tentativas = 5` | não é reservado |
| Duas execuções seguidas (a primeira deixa um e-mail em `sending` reservado por ela) | a segunda **não** reserva nem envia esse e-mail |
| E-mail 2 lança exceção no envio; 1 e 3 enviam | 1 e 3 `sent`; 2 `failed` com `tentativas = 1`; `falhas 1` |
| E-mail que falha com `tentativas` chegando a 5 | `failed` e conta em `definitivos` |
| Falha de envio com corpo contendo `https://mapos.test/mine/senha/TOKEN-SECRETO` | log `error` contendo `email_fila falha id=2 tentativas=1`; **nenhum** log contém `TOKEN-SECRETO` |
| `reabrirFila()` com `failed` `tentativas = 2` e `failed` `tentativas = 5` | o primeiro volta a `pending`; o segundo continua `failed`; retorno `reabertos 1` |
| `sending` com `reservado_em` de 16 min atrás e `tentativas = 2` | volta a `pending`, `reservado_por` e `reservado_em` `NULL` |
| `sending` com `reservado_em` de 16 min atrás e `tentativas = 5` | vira `failed`; conta em `definitivos` |
| `sending` com `reservado_em` de 10 min atrás | continua `sending` |
| `sending` legado (`reservado_em NULL`) com `date` de 16 min atrás | tratado como reserva expirada (mesmas duas regras acima) |
| Controller, ciclo `process` normal | log `info` `email_fila ciclo=process inicio`, depois `email_fila ciclo=process reservados=3 enviados=3 falhas=0 definitivos=0`; código de saída `0` |
| Controller, lock ocupado | log `info` `email_fila ciclo=process ignorado`; código `0` |
| Controller, erro de banco no ciclo (exceção) | log `error` `email_fila ciclo=process erro=<classe>`; código `1` |
| Controller, ciclo `retry` | log `email_fila ciclo=retry reabertos=<n> definitivos=<n>`; código `0`; exceção → `1` |
| `php index.php email/process` fora da CLI | `show_404()` como hoje |

## TDD

Arquivo novo `tests/Unit/EmailFilaTest.php` (`final class EmailFilaTest extends MaposTestCase`). Carregar `require_once BASEPATH . 'libraries/Email.php'`, `APPPATH . 'libraries/MY_Email.php'` e `APPPATH . 'controllers/Email.php'`. A fila é uma **subclasse anônima** de `MY_Email` sem construtor, com `CI` = `(object) ['db' => $this->db]` (via `setPrivateProperty`) e os pontos substituíveis sobrescritos: `obterLock()`/`liberarLock()` (o SQLite não tem `GET_LOCK`), `enviarDaFila()` (decide sucesso/falha/exceção por id) e `agora()`. Tabela:

```php
$this->db->query('CREATE TABLE email_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, "to" TEXT NOT NULL, cc TEXT, bcc TEXT, message TEXT NOT NULL, status TEXT, date DATETIME, headers TEXT, tentativas INTEGER NOT NULL DEFAULT 0, reservado_por CHAR(32) NULL, reservado_em DATETIME NULL)');
```

```php
public function testExcecaoNumEmailNaoImpedeOsDemaisDoLote(): void
{
    $this->enfileirar(3);
    $fila = $this->fila(envio: fn (object $email): bool => $email->id === '2' ? throw new RuntimeException('smtp caiu') : true);

    $resultado = $fila->processarFila();

    $this->assertSame(['ignorado' => false, 'reservados' => 3, 'enviados' => 2, 'falhas' => 1, 'definitivos' => 0], $resultado);
    $this->assertSame(['1' => 'sent', '2' => 'failed', '3' => 'sent'], array_column($this->db->select('id, status')->order_by('id')->get('email_queue')->result_array(), 'status', 'id'));
}

public function testCodigoDeSaidaUmComErroDeBanco(): void
{
    $fila = new class() {
        public function processarFila(): array
        {
            throw new RuntimeException('Erro: banco fora');
        }
    };

    $this->assertSame(1, $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['process', $fila]));
    $this->assertLogContem('error', 'email_fila ciclo=process erro=RuntimeException');
}
```

Comparar os ids com o tipo que o SQLite devolve (string) ou converter com `(int)` — de forma consistente. Cobrir os demais casos da tabela com os nomes fixados nos critérios.

**Refatoração:** remover `send_queue()`, `retry_queue()` e `start_process()` (o único chamador é o controller, que passa a usar os métodos novos) e a propriedade `$expiration`.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/libraries/MY_Email.php` | Modificar | `processarFila()`, `reabrirFila()`, lock, reserva atômica, tentativas, log só de cabeçalhos |
| `application/controllers/Email.php` | Modificar | `executarCiclo()` com logs e código de saída; `exit()` isolado |
| `tests/Unit/EmailFilaTest.php` | Criar | testes da tabela |
| `phpstan-stubs/ci3.php` | Modificar | declarar no stub de `CI_Email` os métodos que o código novo chama (`to`, `cc`, `bcc`, `message`, `clear`, `print_debugger`) — ver passo 7 |
| `phpstan-baseline.neon` | Modificar | só **remover** as entradas de `MY_Email.php` que deixarem de existir; nunca acrescentar |

### Detalhamento Técnico

1. **Assinaturas fixadas (a task de retenção estende o ciclo `retry`):**

```php
// application/libraries/MY_Email.php
public const MAX_TENTATIVAS = 5;
public const LOTE = 50;
public const RESERVA_EXPIRA_MINUTOS = 15;
public const NOME_DO_LOCK = 'mapos_email_fila';

/** @return array{ignorado: bool, reservados: int, enviados: int, falhas: int, definitivos: int} @throws RuntimeException erro de banco */
public function processarFila(): array;
/** @return array{reabertos: int, definitivos: int} @throws RuntimeException erro de banco */
public function reabrirFila(): array;
protected function obterLock(): bool;            // SELECT GET_LOCK(?, 0) AS ok  → (int) ok === 1
protected function liberarLock(): void;          // SELECT RELEASE_LOCK(?)
protected function enviarDaFila(object $email): bool; // monta destinatários/cabeçalhos/corpo e chama $this->send(true)
protected function agora(): int;                 // time()

// application/controllers/Email.php
protected function executarCiclo(string $ciclo, object $fila): int; // 0 ok/ignorado, 1 falha técnica
protected function sair(int $codigo): void;                         // exit($codigo) — único ponto que encerra
```

   `process()`/`retry()` mantêm a checagem de CLI e fazem `$this->sair($this->executarCiclo('process', $this->email));` (idem `'retry'`).

2. **Lock** — `processarFila()` chama `obterLock()`; falso → retorno `ignorado`. Liberar em `finally`. O `GET_LOCK` é liberado sozinho no fim da conexão (processo morto não prende a fila).

3. **Reserva atômica por lote** — o SQL portável (MySQL/MariaDB e SQLite; o `UPDATE ... ORDER BY ... LIMIT` não existe no SQLite e o MySQL não aceita `LIMIT` direto em subconsulta `IN`, por isso a tabela derivada dupla):

```php
$reserva = bin2hex(random_bytes(16)); // 32 caracteres = reservado_por
$agora = date('Y-m-d H:i:s', $this->agora());
$ok = $this->CI->db->query(
    'UPDATE email_queue SET status = ?, reservado_por = ?, reservado_em = ?, tentativas = tentativas + 1'
    . ' WHERE status = ? AND tentativas < ? AND id IN (SELECT id FROM (SELECT id FROM email_queue WHERE status = ? AND tentativas < ? ORDER BY id LIMIT ' . self::LOTE . ') AS lote)',
    ['sending', $reserva, $agora, 'pending', self::MAX_TENTATIVAS, 'pending', self::MAX_TENTATIVAS]
);
if ($ok === false) {
    throw new RuntimeException('email_fila: falha ao reservar');
}
$emails = $this->CI->db->where('reservado_por', $reserva)->where('status', 'sending')->order_by('id')->get('email_queue')->result();
```

   Com `db_debug` desligado (produção) a query devolve `false`; com ligado o CI lança — os dois casos sobem como exceção para o controller.

4. **Envio de cada e-mail** — `try { $ok = $this->enviarDaFila($email); } catch (\Throwable $e) { $ok = false; }`. Sucesso → `status='sent'`; falha → `status='failed'` (com `(int) $email->tentativas >= MAX_TENTATIVAS` conta em `definitivos`). Atualizar com `->where('id', $email->id)->where('reservado_por', $reserva)` e `date` = agora. Falha loga **só cabeçalhos**:

```php
log_message('error', sprintf('email_fila falha id=%d tentativas=%d %s', (int) $email->id, (int) $email->tentativas, trim($this->print_debugger(['headers']))));
```

   `enviarDaFila()` limpa o estado entre e-mails (`$this->clear(true)`) antes de `to/cc/bcc/message` e de repor `$this->_headers = json_decode_legacy($email->headers)`, como o `send_queue()` atual fazia, e devolve `$this->send(true)`.

5. **`reabrirFila()`** — três UPDATEs com bindings (nada de `WHERE` por concatenação), `$limite = date('Y-m-d H:i:s', $this->agora() - self::RESERVA_EXPIRA_MINUTOS * 60)`:
   - `failed` com `tentativas < 5` → `pending` (`reabertos`).
   - `sending` expirado (`reservado_em < $limite` **ou** `reservado_em IS NULL AND date < $limite`) com `tentativas < 5` → `pending`, `reservado_por = NULL`, `reservado_em = NULL` (`reabertos`).
   - `sending` expirado com `tentativas >= 5` → `failed` (`definitivos`).
   Contagens por `affected_rows()`; query `false` → `RuntimeException`.

6. **Controller** — `executarCiclo('process', $fila)`: log `info` `email_fila ciclo=process inicio`; `try` → `ignorado` loga `email_fila ciclo=process ignorado` e devolve 0; senão loga `email_fila ciclo=process reservados=%d enviados=%d falhas=%d definitivos=%d` e devolve 0; `catch (\Throwable $e)` → `log_message('error', 'email_fila ciclo=process erro=' . $e::class)` e 1. `'retry'` igual com `reabrirFila()` e `email_fila ciclo=retry reabertos=%d definitivos=%d`. Mensagem da exceção não vai ao log (pode conter SQL com destinatário).

7. **PHPStan sem subir a baseline** — o stub `phpstan-stubs/ci3.php` só declara `send()` e algumas propriedades de `CI_Email`; por isso `to()`, `cc()`, `bcc()`, `message()`, `print_debugger()` hoje estão **ignorados na baseline** (`phpstan-baseline.neon`, entradas `path: application/libraries/MY_Email.php`). Chamar `clear()` ou mudar contagens geraria erro novo. Solução: declarar no stub, com assinatura do CI 3.1 (`to($to)`, `cc($cc)`, `bcc($bcc, $limit = '')`, `message($body)`, `clear($clear_attachments = false)` devolvendo `static`; `print_debugger($include = ['headers', 'subject', 'body'])` devolvendo `string`), e apagar da baseline as entradas que deixarem de casar (`reportUnmatchedIgnoredErrors` está desligado, então sobra não quebra, mas não deixar lixo). `enviarDaFila()` devolve `(bool) $this->send(true)`.

**Contrato com camadas adjacentes:**

```
Produz para: cron (docker/etc/php/cron-jobs) — exit 0 em sucesso ou lock ocupado; exit 1 em falha técnica
             task de retenção — executarCiclo('retry', $fila) ganhará a chamada a Retencao::limpar()
Consome de: email_queue (pending gravado por MY_Email::send() sem argumento — inalterado)
  - Erro de banco: RuntimeException (db_debug off) ou exceção do CI (db_debug on) → controller loga e sai 1
```
