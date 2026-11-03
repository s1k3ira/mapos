---
checkpoint: null
complexity: M
created_at: "2026-11-03 18:46:56"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testApagaEventoDe91DiasEMantemODe89 tests/Unit/RetencaoTest.php
      text: limpar() apaga webhook_eventos de 91 dias e mantém o de 89, devolvendo ['webhook_eventos' => 1, 'api_refresh_tokens' => 0, 'email_queue' => 0]
    - done: true
      test: application/vendor/bin/phpunit --filter 'testApagaNoMaximoOLotePorTabela|testLoteInformadoLimitaAExclusao' tests/Unit/RetencaoTest.php
      text: Com 600 elegíveis apaga 500 (menores ids) por chamada e a segunda chamada apaga os 100 restantes; limpar(10) apaga 10
    - done: true
      test: application/vendor/bin/phpunit --filter testRefreshTokensExpiradosOuRevogadosHaTrintaDias tests/Unit/RetencaoTest.php
      text: 'Refresh tokens: apaga expirado há 31 dias e revogado há 31 dias; mantém expirado há 29 dias e revogado há 2 dias'
    - done: true
      test: application/vendor/bin/phpunit --filter testEmailQueueApagaSoSentEFailedAntigos tests/Unit/RetencaoTest.php
      text: 'email_queue: apaga sent de 8 dias e failed de 31 dias; mantém sent de 6, failed de 29, pending e sending antigos'
    - done: true
      test: application/vendor/bin/phpunit --filter testTabelaInexistenteContaZero tests/Unit/RetencaoTest.php
      text: Tabela inexistente conta 0 e não impede as outras
    - done: true
      test: application/vendor/bin/phpunit --filter testCicloRetryLogaARetencaoESaiZero tests/Unit/RetencaoTest.php
      text: Ciclo retry com retenção devolve 0 e loga 'retencao webhook_eventos=1 api_refresh_tokens=2 email_queue=3'
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNaRetencaoSaiUm tests/Unit/RetencaoTest.php
      text: Retenção lançando exceção faz o ciclo retry devolver 1 com log 'email_fila ciclo=retry erro='
    - done: true
      test: application/vendor/bin/phpunit --filter testCicloProcessNaoChamaARetencao tests/Unit/RetencaoTest.php
      text: O ciclo process não chama a retenção
    - done: true
      test: '! grep -nE ''NOW\(\)|INTERVAL'' application/libraries/Retencao.php'
      text: Retencao calcula as datas no PHP, sem NOW() nem INTERVAL no SQL
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/EmailFilaTest.php
      text: A suíte da fila de e-mail continua verde com a assinatura estendida
deps:
    - TASK-012
diff_base: 67a51a93c91f7554394fcdd90f18215e25fdf9da
diff_tip: cd93b696e101d83ec6a668280a154530531a4868
files:
    - application/libraries/Retencao.php
    - application/controllers/Email.php
    - tests/Unit/RetencaoTest.php
id: TASK-013
linter: composer analyse
n45_version: 1.1.0
outcome: |
    ## Outcome (backend)

    - `Retencao` (`application/libraries/Retencao.php`): `__construct($opcoes = null)` (`['db'=>..., 'relogio'=>callable]`), `limpar(int $lote = 500): array{webhook_eventos:int, api_refresh_tokens:int, email_queue:int}`; constante `Retencao::LOTE_PADRAO = 500`. Hard delete em lote por tabela (menores ids primeiro); `$lote < 1` vira 1; tabela inexistente conta 0; erro de banco sobe como exceção. Prazos: `webhook_eventos.created_at` > 90 dias; `api_refresh_tokens.expira_em` ou `revogado_em` > 30 dias; `email_queue` `sent` > 7 dias e `failed` > 30 dias (`pending`/`sending` nunca). Datas calculadas no PHP.
    - `Email::executarCiclo(string $ciclo, object $fila, ?object $retencao = null): int`: no ciclo `retry`, após a fila, chama `$retencao->limpar()` e loga `info` `retencao webhook_eventos=%d api_refresh_tokens=%d email_queue=%d`; exceção → log `error` `email_fila ciclo=retry erro=<classe>` e retorno 1. Ciclo `process` não chama a retenção; sem `$retencao` mantém o comportamento anterior. `Email::retry()` carrega a library `Retencao` e a injeta (cron `email/retry`, a cada 5 min).
persona: backend
phase: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/RetencaoTest.php tests/Unit/EmailFilaTest.php
title: Retenção de eventos, refresh tokens e fila no email/retry
updated_at: "2026-11-03 20:33:20"
---
## Contexto

Três tabelas crescem sem limite: `webhook_eventos` (um registro por notificação de pagamento), `api_refresh_tokens` (um por login/renovação da API) e `email_queue` (todo e-mail enviado fica para sempre). Esta task cria a biblioteca `Retencao` e a executa no ciclo do cron `email/retry` (a cada 5 min) — sem obrigar o administrador a cadastrar outro cron. Apagar é **hard delete**, em lotes de no máximo 500 linhas **por tabela e por chamada**, para manter o ciclo curto.

Prazos (decididos na Spec):

| Tabela | Apaga quando |
| --- | --- |
| `webhook_eventos` | `created_at` há mais de **90 dias** (acima da janela de reenvio dos gateways; apagar reabre a idempotência daquele id, o que é aceito) |
| `api_refresh_tokens` | `expira_em` há mais de **30 dias** **ou** `revogado_em` há mais de **30 dias** |
| `email_queue` | `status = 'sent'` com `date` há mais de **7 dias**; `status = 'failed'` com `date` há mais de **30 dias** (`pending`/`sending` nunca) |

Estado atual (entregue pela task "Fila de e-mail com lock, reserva atômica e tentativas", consumir sem mudar a assinatura):

```php
// application/controllers/Email.php (CI_Controller, só CLI)
public function retry();  // checa CLI e faz $this->sair($this->executarCiclo('retry', $this->email));
protected function executarCiclo(string $ciclo, object $fila): int; // 'retry' → $fila->reabrirFila() → log 'email_fila ciclo=retry reabertos=%d definitivos=%d'; 0 ok, 1 em \Throwable (log 'email_fila ciclo=retry erro=<classe>')
protected function sair(int $codigo): void;
// MY_Email grava email_queue.date = agora quando o e-mail vira 'sent' ou 'failed'.
```

Schema relevante: `webhook_eventos(id, ..., created_at DATETIME NOT NULL)`; `api_refresh_tokens(id, tipo, usuario_id, familia, token_hash, criado_em, expira_em DATETIME NOT NULL, usado_em, revogado_em DATETIME NULL, ...)`; `email_queue(id, ..., status, date DATETIME, ...)`. Padrão de biblioteca com dependências injetáveis para teste: `application/libraries/Limite_requisicoes.php` (construtor com `['db' => ..., 'relogio' => callable]`, `get_instance()->db` no framework; tabela ausente = desligado).

## Comportamento Esperado

Relógio fixo nos exemplos: `2026-11-03 12:00:00`.

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `webhook_eventos` com `created_at` de 91 dias e de 89 dias atrás; `limpar()` | apaga só o de 91 dias; retorno `['webhook_eventos' => 1, 'api_refresh_tokens' => 0, 'email_queue' => 0]` |
| 600 eventos de 120 dias; `limpar()` | apaga **500** (os de menor id); sobram 100; segunda chamada apaga os 100 |
| `limpar(10)` com 25 elegíveis em `email_queue` | apaga 10 |
| Refresh com `expira_em` há 31 dias / há 29 dias / no futuro mas `revogado_em` há 31 dias / `revogado_em` há 2 dias | apaga o 1º e o 3º; mantém o 2º e o 4º |
| `email_queue`: `sent` de 8 dias, `sent` de 6 dias, `failed` de 31 dias, `failed` de 29 dias, `pending` de 60 dias, `sending` de 60 dias | apaga só `sent` 8 d e `failed` 31 d |
| Tabela `webhook_eventos` inexistente | conta 0 para ela e segue com as outras, sem erro |
| `executarCiclo('retry', $fila, $retencao)` com sucesso | depois do log da fila, log `info` `retencao webhook_eventos=1 api_refresh_tokens=2 email_queue=3` (os números do retorno); devolve 0 |
| `$retencao->limpar()` lança exceção | log `error` `email_fila ciclo=retry erro=<classe>`; devolve 1 (os e-mails já reabertos continuam reabertos) |
| `executarCiclo('retry', $fila)` sem retenção (assinatura antiga) | comportamento anterior, devolve 0 |
| `executarCiclo('process', ...)` | não chama a retenção |

## TDD

Arquivo novo `tests/Unit/RetencaoTest.php` (`final class RetencaoTest extends MaposTestCase`) com `require_once APPPATH . 'libraries/Retencao.php'` e, para o ciclo, `BASEPATH . 'libraries/Email.php'`, `APPPATH . 'libraries/MY_Email.php'`, `APPPATH . 'controllers/Email.php'`. Tabelas mínimas no SQLite com as colunas usadas (`id`, datas, `status`).

```php
public function testApagaEventoDe91DiasEMantemODe89(): void
{
    $this->db->insert('webhook_eventos', ['id' => 1, 'created_at' => '2026-08-04 11:00:00']); // 91 dias
    $this->db->insert('webhook_eventos', ['id' => 2, 'created_at' => '2026-08-06 12:00:00']); // 89 dias

    $resultado = $this->retencao()->limpar();

    $this->assertSame(['webhook_eventos' => 1, 'api_refresh_tokens' => 0, 'email_queue' => 0], $resultado);
    $this->assertSame(['2'], array_map('strval', array_column($this->db->get('webhook_eventos')->result_array(), 'id')));
}

public function testCicloRetryLogaARetencaoESaiZero(): void
{
    $fila = new class() {
        public function reabrirFila(): array
        {
            return ['reabertos' => 0, 'definitivos' => 0];
        }
    };
    $retencao = new class() {
        public function limpar(int $lote = 500): array
        {
            return ['webhook_eventos' => 1, 'api_refresh_tokens' => 2, 'email_queue' => 3];
        }
    };

    $codigo = $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['retry', $fila, $retencao]);

    $this->assertSame(0, $codigo);
    $this->assertLogContem('info', 'retencao webhook_eventos=1 api_refresh_tokens=2 email_queue=3');
}
```

`$this->retencao()` = `new Retencao(['db' => $this->db, 'relogio' => fn () => strtotime('2026-11-03 12:00:00')])`. Cobrir os demais casos da tabela com os nomes fixados nos critérios.

**Refatoração:** Nenhuma.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/libraries/Retencao.php` | Criar | `limpar(int $lote = 500): array` |
| `application/controllers/Email.php` | Modificar | `executarCiclo()` ganha `?object $retencao = null`; `retry()` passa a `Retencao` |
| `tests/Unit/RetencaoTest.php` | Criar | testes da tabela |

### Detalhamento Técnico

1. **Assinatura fixada:**

```php
class Retencao
{
    public const LOTE_PADRAO = 500;

    /** @param array{db?: mixed, relogio?: callable}|null $opcoes Para os testes; no framework vem vazio. */
    public function __construct($opcoes = null);

    /** @return array{webhook_eventos: int, api_refresh_tokens: int, email_queue: int} */
    public function limpar(int $lote = self::LOTE_PADRAO): array;
}
```

   Construtor no mesmo molde de `Limite_requisicoes` (`$this->db = $opcoes['db'] ?? get_instance()->db; $this->relogio = $opcoes['relogio'] ?? 'time';`). `$lote` < 1 vira 1.

2. **Apagar em lote de forma portável** (o `DELETE ... ORDER BY ... LIMIT` não existe no SQLite): selecionar os ids e apagar por eles.

```php
private function apagarEmLote(string $tabela, callable $filtro, int $lote): int
{
    if (! $this->db->table_exists($tabela)) {
        return 0;
    }

    $filtro($this->db);
    $ids = array_column($this->db->select('id')->order_by('id')->limit($lote)->get($tabela)->result_array(), 'id');
    if ($ids === []) {
        return 0;
    }

    $this->db->where_in('id', $ids)->delete($tabela);

    return (int) $this->db->affected_rows();
}
```

   Filtros com as datas calculadas no PHP (`date('Y-m-d H:i:s', $agora - N * 86400)`), nunca `NOW()`/`INTERVAL` (o teste roda em SQLite):
   - `webhook_eventos`: `where('created_at <', $limite90)`.
   - `api_refresh_tokens`: `group_start()->where('expira_em <', $limite30)->or_where('revogado_em <', $limite30)->group_end()` (`revogado_em NULL` não casa com `<`).
   - `email_queue`: `group_start()->group_start()->where('status', 'sent')->where('date <', $limite7)->group_end()->or_group_start()->where('status', 'failed')->where('date <', $limite30)->group_end()->group_end()`.
   Erro de banco não é engolido: sobe para o controller (que sai 1).

3. **Controller** — `protected function executarCiclo(string $ciclo, object $fila, ?object $retencao = null): int`. No ramo `'retry'`, depois de logar a fila: se `$retencao !== null`, `$r = $retencao->limpar();` e `log_message('info', sprintf('retencao webhook_eventos=%d api_refresh_tokens=%d email_queue=%d', ...))`, dentro do mesmo `try` (exceção → log `email_fila ciclo=retry erro=<classe>` e 1). `retry()`: `$this->load->library('Retencao'); $this->sair($this->executarCiclo('retry', $this->email, $this->retencao));`. O ramo `'process'` não muda.

**Contrato com camadas adjacentes:**

```
Produz para: Email::retry (cron a cada 5 min)
  - Sucesso: array com a quantidade apagada por tabela (0 a $lote cada)
  - Erro de banco: exceção do CI (db_debug on) / falha da query → controller loga e sai 1
Consome de: webhook_eventos.created_at, api_refresh_tokens.expira_em/revogado_em, email_queue.status/date
```
