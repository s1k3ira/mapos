---
checkpoint: null
complexity: M
created_at: "2026-11-03 15:55:19"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter WebhookEventos tests/Unit/MigrationsSegurancaTest.php
      text: Migration_create_webhook_eventos::up() cria a tabela com as colunas gateway, evento_id, cobranca_id, payload_hash, resultado, processado_em, created_at e o índice único (gateway, evento_id); down() remove a tabela
    - done: true
      test: grep -nE 'CREATE UNIQUE INDEX.*webhook_eventos_gateway_evento' application/database/migrations/20261103000000_create_webhook_eventos.php
      text: O índice único de webhook_eventos é criado por SQL explícito com o nome webhook_eventos_gateway_evento
    - done: true
      test: application/vendor/bin/phpunit --filter RefreshTokens tests/Unit/MigrationsSegurancaTest.php
      text: Migration_add_tipo_to_api_refresh_tokens::up() deixa os tokens legados com tipo = 'usuario'; down() apaga os tokens tipo = 'cliente' antes de remover a coluna
    - done: true
      test: grep -nE "tipo.*cliente" application/database/migrations/20261103000100_add_tipo_to_api_refresh_tokens.php
      text: O down() da coluna tipo remove os refresh tokens de cliente
    - done: true
      test: application/vendor/bin/phpunit --filter Clientes tests/Unit/MigrationsSegurancaTest.php
      text: Migration_add_senha_trocar_sessao_versao_to_clientes::up() cria as duas colunas com valor 0 para cliente existente; down() remove as duas
    - done: true
      test: application/vendor/bin/phpunit --filter EmailQueue tests/Unit/MigrationsSegurancaTest.php
      text: Migration_add_reserva_to_email_queue::up() cria tentativas (padrão 0), reservado_por e reservado_em e o índice email_queue_status_reserva; down() desfaz
    - done: true
      test: application/vendor/bin/phpunit --filter Cobrancas tests/Unit/MigrationsSegurancaTest.php
      text: Migration_add_index_charge_to_cobrancas::up() não cria cobrancas_charge_gateway quando já existe índice começando por charge_id, e cria quando não existe
    - done: true
      test: application/vendor/bin/phpunit --filter Falha tests/Unit/MigrationsSegurancaTest.php
      text: Toda migration lança RuntimeException quando a estrutura não existe depois da DDL (db_debug desligado)
    - done: true
      test: grep -lE 'throw new RuntimeException' application/database/migrations/20261103000000_create_webhook_eventos.php application/database/migrations/20261103000100_add_tipo_to_api_refresh_tokens.php application/database/migrations/20261103000200_add_senha_trocar_sessao_versao_to_clientes.php application/database/migrations/20261103000300_add_reserva_to_email_queue.php application/database/migrations/20261103000400_add_index_charge_to_cobrancas.php | wc -l | grep -qx 5
      text: Cada uma das 5 migrations confere o resultado e lança RuntimeException
    - done: true
      test: application/vendor/bin/phpunit --filter Idempotente tests/Unit/MigrationsSegurancaTest.php
      text: Rodar up() duas vezes não duplica coluna/índice nem lança
    - done: true
      test: git diff --quiet HEAD -- banco.sql
      text: banco.sql não é alterado pela task
deps: []
diff_base: 6b7146759a20684847c695f666c3b11274131805
diff_tip: 9ff22576e9a1ceba0abc367a8843ba69f445118e
files:
    - application/database/migrations/20261103000000_create_webhook_eventos.php
    - application/database/migrations/20261103000100_add_tipo_to_api_refresh_tokens.php
    - application/database/migrations/20261103000200_add_senha_trocar_sessao_versao_to_clientes.php
    - application/database/migrations/20261103000300_add_reserva_to_email_queue.php
    - application/database/migrations/20261103000400_add_index_charge_to_cobrancas.php
    - tests/Unit/MigrationsSegurancaTest.php
id: TASK-002
linter: composer analyse
n45_version: 1.1.0
outcome: |
    Schema entregue por 5 migrations aditivas (timestamp, idempotentes, com conferência pós-DDL que lança `RuntimeException`; `banco.sql` intocado):

    - `webhook_eventos(id BIGINT UNSIGNED AI, gateway ENUM('efi','mercadopago','asaas'), evento_id VARCHAR(128), cobranca_id INT NULL, payload_hash CHAR(64), resultado VARCHAR(20) NULL, processado_em DATETIME NULL, created_at DATETIME)` · UNIQUE `webhook_eventos_gateway_evento (gateway, evento_id)` · índices `webhook_eventos_cobranca`, `webhook_eventos_created` · InnoDB, sem FK.
    - `api_refresh_tokens.tipo ENUM('usuario','cliente') NOT NULL DEFAULT 'usuario'` (após `id`) · índice `api_refresh_tokens_dono (tipo, usuario_id)`; índice simples `usuario_id` removido; `down()` apaga tokens `cliente` antes de remover a coluna.
    - `clientes.senha_trocar TINYINT(1) NOT NULL DEFAULT 0` · `clientes.sessao_versao INT UNSIGNED NOT NULL DEFAULT 0`.
    - `email_queue.tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0` · `reservado_por CHAR(32) NULL` · `reservado_em DATETIME NULL` · índice `email_queue_status_reserva (status, reservado_por)`.
    - `cobrancas` índice `cobrancas_charge_gateway (charge_id(64), payment_gateway(32))`, criado só se não houver índice começando por `charge_id`.
persona: dba
phase: 'Phase 1 — Base de segurança: schema, API, gateways e avisos de sessão'
roadmap: feat-0001-melhoria-seguranca
status: done
tests: application/vendor/bin/phpunit tests/Unit/MigrationsSegurancaTest.php
title: Migrations de webhooks, refresh por tipo, troca de senha do cliente e reserva da fila
updated_at: "2026-11-03 17:18:08"
---
## Contexto

A feature de segurança precisa de cinco mudanças de schema, todas **aditivas**, feitas por migration do CodeIgniter em modo timestamp (`application/database/migrations/YYYYMMDDHHMMSS_descricao.php`, classe `Migration_descricao extends CI_Migration`, `up()` e `down()` obrigatórios). Elas sustentam: idempotência dos webhooks de pagamento (`webhook_eventos`), refresh token da área do cliente na mesma tabela do operador (`api_refresh_tokens.tipo`), troca obrigatória de senha e revalidação da sessão do cliente (`clientes.senha_trocar`, `clientes.sessao_versao`), reserva atômica da fila de e-mail (`email_queue.tentativas`, `reservado_por`, `reservado_em`) e a busca da cobrança pelo id do gateway (`cobrancas_charge_gateway`).

Regras do projeto: schema **só** por migration, **nunca** editar `banco.sql`; tabelas novas em InnoDB; charset/collation vêm da conexão; nomes em português no padrão das tabelas vizinhas (snake_case nas novas). Em produção o `db_debug` fica desligado, então um `CREATE`/`ALTER` com erro só vai para o log e a versão avançaria mesmo assim: **toda migration confere o resultado (`table_exists`/`field_exists`/consulta do índice) e lança `RuntimeException`** quando a estrutura não existe. Modelo a seguir: `application/database/migrations/20261014000000_create_api_tokens.php` (tabela + índice único por `$this->db->query`) e `20261014060000_add_sessao_versao_to_usuarios.php` (coluna + conferência). `clientes.token_versao` **já existe** (`20261014050000_add_token_versao.php`) e não é recriada.

Estado atual relevante: `api_refresh_tokens` foi criada por `add_key('usuario_id')`, que no MySQL gera um índice simples de nome `usuario_id`. `cobrancas.charge_id` é `VARCHAR(255)` e `payment_gateway` é `VARCHAR(255)` (valores reais `'GerencianetSdk'`, `'MercadoPago'`, `'Asaas'`). `email_queue.status` é `ENUM('pending','sending','sent','failed')` e continua assim (nenhum valor novo).

## Comportamento Esperado

Schema alvo (MySQL 8.4 / MariaDB):

```sql
-- 20261103000000_create_webhook_eventos.php  (Migration_create_webhook_eventos)
CREATE TABLE webhook_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gateway ENUM('efi','mercadopago','asaas') NOT NULL,
  evento_id VARCHAR(128) NOT NULL,
  cobranca_id INT NULL,                 -- cobrancas.idCobranca; sem FK (evento guardado mesmo sem cobrança)
  payload_hash CHAR(64) NOT NULL,        -- sha256 do corpo bruto; o corpo NUNCA é persistido
  resultado VARCHAR(20) NULL,            -- 'aplicado' | 'ignorado' | 'divergente' | 'sem_efeito'
  processado_em DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY webhook_eventos_gateway_evento (gateway, evento_id),
  KEY webhook_eventos_cobranca (cobranca_id),
  KEY webhook_eventos_created (created_at)
) ENGINE=InnoDB;
-- down(): DROP TABLE webhook_eventos

-- 20261103000100_add_tipo_to_api_refresh_tokens.php  (Migration_add_tipo_to_api_refresh_tokens)
ALTER TABLE api_refresh_tokens ADD COLUMN tipo ENUM('usuario','cliente') NOT NULL DEFAULT 'usuario' AFTER id;  -- linhas legadas = operador
CREATE INDEX api_refresh_tokens_dono ON api_refresh_tokens (tipo, usuario_id);
DROP INDEX usuario_id ON api_refresh_tokens;   -- só se existir (conferir antes)
-- down(): DELETE FROM api_refresh_tokens WHERE tipo = 'cliente'; recria o índice simples usuario_id (usuario_id);
--         DROP INDEX api_refresh_tokens_dono; DROP COLUMN tipo

-- 20261103000200_add_senha_trocar_sessao_versao_to_clientes.php  (Migration_add_senha_trocar_sessao_versao_to_clientes)
ALTER TABLE clientes
  ADD COLUMN senha_trocar TINYINT(1) NOT NULL DEFAULT 0,      -- clientes existentes = 0
  ADD COLUMN sessao_versao INT UNSIGNED NOT NULL DEFAULT 0;
-- down(): DROP COLUMN senha_trocar, sessao_versao

-- 20261103000300_add_reserva_to_email_queue.php  (Migration_add_reserva_to_email_queue)
ALTER TABLE email_queue
  ADD COLUMN tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN reservado_por CHAR(32) NULL,
  ADD COLUMN reservado_em DATETIME NULL;
CREATE INDEX email_queue_status_reserva ON email_queue (status, reservado_por);
-- down(): DROP INDEX email_queue_status_reserva; DROP COLUMN tentativas, reservado_por, reservado_em

-- 20261103000400_add_index_charge_to_cobrancas.php  (Migration_add_index_charge_to_cobrancas)
CREATE INDEX cobrancas_charge_gateway ON cobrancas (charge_id(64), payment_gateway(32));
-- criar só se NÃO houver índice cuja primeira coluna seja charge_id
-- down(): DROP INDEX cobrancas_charge_gateway (só se existir com esse nome)
```

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `Migration_create_webhook_eventos::up()` com banco sem a tabela | cria `webhook_eventos` com as 8 colunas e o índice único `webhook_eventos_gateway_evento` |
| `up()` de qualquer das 5 com a DDL falhando em silêncio (`table_exists`/`field_exists`/índice ausente depois) | lança `RuntimeException` com a mensagem `Não foi possível criar <estrutura>; veja o log da aplicação.` |
| `Migration_add_tipo_to_api_refresh_tokens::up()` com 2 tokens legados | coluna `tipo` = `'usuario'` nas 2 linhas; índice `api_refresh_tokens_dono` existe; índice `usuario_id` removido |
| `Migration_add_tipo_to_api_refresh_tokens::down()` com 1 token `usuario` e 1 `cliente` | sobra só o token `usuario`; coluna `tipo` e índice `api_refresh_tokens_dono` removidos; índice `usuario_id` recriado |
| `Migration_add_senha_trocar_sessao_versao_to_clientes::up()` com cliente existente | cliente com `senha_trocar = 0` e `sessao_versao = 0` |
| `Migration_add_index_charge_to_cobrancas::up()` quando já existe índice começando por `charge_id` | não cria `cobrancas_charge_gateway` e não lança |
| `up()` rodado duas vezes (coluna/índice já presentes) | não duplica nem lança (idempotente, como `add_token_versao`) |
| `down()` de cada migration depois do `up()` | estrutura volta ao estado anterior (tabela/colunas/índices ausentes) |

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/database/migrations/20261103000000_create_webhook_eventos.php` | Criar | Tabela de idempotência dos webhooks |
| `application/database/migrations/20261103000100_add_tipo_to_api_refresh_tokens.php` | Criar | Coluna `tipo`, índice `(tipo, usuario_id)`, remove índice simples |
| `application/database/migrations/20261103000200_add_senha_trocar_sessao_versao_to_clientes.php` | Criar | Colunas `senha_trocar` e `sessao_versao` em `clientes` |
| `application/database/migrations/20261103000300_add_reserva_to_email_queue.php` | Criar | Colunas de reserva e índice `(status, reservado_por)` |
| `application/database/migrations/20261103000400_add_index_charge_to_cobrancas.php` | Criar | Índice `(charge_id(64), payment_gateway(32))` |
| `tests/Unit/MigrationsSegurancaTest.php` | Criar | `up()`/`down()` das 5 migrations com dbforge e db de teste |

### Detalhamento Técnico

1. Tabelas e colunas por `$this->dbforge` (como as vizinhas). Tipos ENUM pelo formato já usado no projeto: `'type' => "ENUM('efi','mercadopago','asaas')"`. `BIGINT UNSIGNED auto_increment` em `id`. `create_table('webhook_eventos', true, ['ENGINE' => 'InnoDB'])`.
2. Índices (único, composto, com prefixo) por `$this->db->query(...)` com `$this->db->protect_identifiers()` no nome da tabela, como em `create_api_tokens`. O `AFTER id` da coluna `tipo` vai pela chave `'after' => 'id'` do `add_column`.
3. Existência de índice: método privado `indiceExiste(string $tabela, string $indice): bool` e, na de cobranças, `existeIndiceComecandoPor(string $tabela, string $coluna): bool`, ambos por `SHOW INDEX FROM <tabela>` (MySQL e MariaDB) lendo `Key_name`, `Column_name` e `Seq_in_index`. Isolar essas consultas em métodos para o teste poder substituí-las (o SQLite de teste não tem `SHOW INDEX`).
4. Conferência pós-DDL em todo `up()`: `table_exists`/`field_exists`/`indiceExiste` → `RuntimeException`. Idempotência: pular coluna/índice que já existe (como `20261014050000_add_token_versao.php`).
5. `down()` da `add_tipo_to_api_refresh_tokens` apaga primeiro as linhas `tipo = 'cliente'` (refresh tokens de cliente não podem virar tokens de operador ao perder a coluna) e só então recria `usuario_id`, remove `api_refresh_tokens_dono` e a coluna.
6. Teste (`tests/Unit/MigrationsSegurancaTest.php`, `final class MigrationsSegurancaTest extends MaposTestCase`): `require_once` de cada arquivo de migration; instanciar com `new Migration_x()` (o stub `CI_Migration` do bootstrap expõe `$db`); injetar `$migration->db` e `$migration->dbforge`. Seguir o padrão de `tests/Unit/TemaCorTest.php` (dbforge falso em classe anônima que traduz para SQL do SQLite) para tabelas/colunas, e substituir `indiceExiste`/`existeIndiceComecandoPor` por subclasse anônima que lê um estado controlado pelo teste. Para o caso "DDL falhou em silêncio", usar um dbforge falso que não faz nada e assertar a `RuntimeException`.
7. Validação real no MySQL (quando o ambiente Docker do projeto estiver disponível: `cd docker && docker-compose up -d`): `php index.php tools migrate` aplica as 5 e o painel deixa de pedir atualização. Registrar no retorno se foi possível rodar.

**Contrato com as tasks seguintes** (não renomear — webhooks, refresh do cliente, troca de senha e fila consomem estes nomes):

```
webhook_eventos(id, gateway, evento_id, cobranca_id, payload_hash, resultado, processado_em, created_at)  UNIQUE(gateway, evento_id)
api_refresh_tokens.tipo ENUM('usuario','cliente') DEFAULT 'usuario'
clientes.senha_trocar TINYINT(1) DEFAULT 0 ; clientes.sessao_versao INT UNSIGNED DEFAULT 0
email_queue.tentativas TINYINT UNSIGNED DEFAULT 0 ; email_queue.reservado_por CHAR(32) NULL ; email_queue.reservado_em DATETIME NULL
cobrancas index cobrancas_charge_gateway (charge_id(64), payment_gateway(32))
```
