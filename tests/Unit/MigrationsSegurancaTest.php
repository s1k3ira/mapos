<?php

use PHPUnit\Framework\Attributes\DataProvider;

require_once APPPATH . 'database/migrations/20261103000000_create_webhook_eventos.php';
require_once APPPATH . 'database/migrations/20261103000100_add_tipo_to_api_refresh_tokens.php';
require_once APPPATH . 'database/migrations/20261103000200_add_senha_trocar_sessao_versao_to_clientes.php';
require_once APPPATH . 'database/migrations/20261103000300_add_reserva_to_email_queue.php';
require_once APPPATH . 'database/migrations/20261103000400_add_index_charge_to_cobrancas.php';

/**
 * Migrations da melhoria de segurança: webhook_eventos, api_refresh_tokens.tipo,
 * clientes.senha_trocar/sessao_versao, reserva da email_queue e o índice de
 * cobrancas. Rodam no SQLite em memória; a conexão é embrulhada para traduzir
 * o SQL de índice do MySQL (SHOW INDEX, prefixo de coluna, DROP INDEX ... ON).
 */
final class MigrationsSegurancaTest extends MaposTestCase
{
    private const MIGRATIONS = [
        'Migration_create_webhook_eventos',
        'Migration_add_tipo_to_api_refresh_tokens',
        'Migration_add_senha_trocar_sessao_versao_to_clientes',
        'Migration_add_reserva_to_email_queue',
        'Migration_add_index_charge_to_cobrancas',
    ];

    /** Cria as tabelas que existem antes das migrations. */
    private function bancoAntes(): void
    {
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, familia TEXT)');
        $this->db->query('CREATE INDEX usuario_id ON api_refresh_tokens (usuario_id)');
        $this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT)');
        $this->db->query('CREATE TABLE email_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT \'pending\')');
        $this->db->query('CREATE TABLE cobrancas (idCobranca INTEGER PRIMARY KEY AUTOINCREMENT, charge_id TEXT, payment_gateway TEXT)');
    }

    private function conexao(bool $engolirIndices = false): object
    {
        return new class($this->db, $engolirIndices) {
            public function __construct(private $real, private bool $engolirIndices)
            {
            }

            public function query($sql)
            {
                if (preg_match('/^SHOW INDEX FROM (\S+)$/', $sql, $m)) {
                    return new class($this->linhasDeIndice(trim($m[1], '"`'))) {
                        public function __construct(private array $linhas)
                        {
                        }

                        public function result_array()
                        {
                            return $this->linhas;
                        }
                    };
                }
                if ($this->engolirIndices && preg_match('/^(CREATE|DROP) /', $sql)) {
                    return true;
                }
                $sql = preg_replace('/^DROP INDEX (\w+) ON .+$/', 'DROP INDEX $1', $sql);
                if (str_starts_with($sql, 'CREATE')) {
                    $sql = preg_replace('/(\w)\(\d+\)/', '$1', $sql);
                }

                return $this->real->query($sql);
            }

            private function linhasDeIndice(string $tabela): array
            {
                $linhas = [];
                foreach ($this->real->query("PRAGMA index_list({$tabela})")->result_array() as $indice) {
                    foreach ($this->real->query("PRAGMA index_info({$indice['name']})")->result_array() as $coluna) {
                        $linhas[] = [
                            'Key_name' => $indice['name'],
                            'Column_name' => $coluna['name'],
                            'Seq_in_index' => $coluna['seqno'] + 1,
                            'Non_unique' => $indice['unique'] ? 0 : 1,
                        ];
                    }
                }

                return $linhas;
            }

            public function __call($metodo, $argumentos)
            {
                return $this->real->{$metodo}(...$argumentos);
            }
        };
    }

    private function dbforge(): object
    {
        return new class($this->db) {
            private array $campos = [];

            public function __construct(private $db)
            {
            }

            private function coluna(string $nome, array $def): string
            {
                if (! empty($def['auto_increment'])) {
                    return "{$nome} INTEGER PRIMARY KEY AUTOINCREMENT";
                }
                $tipo = preg_match('/INT/i', $def['type']) ? 'INTEGER' : 'TEXT';
                $sql = "{$nome} {$tipo} " . (($def['null'] ?? false) ? 'NULL' : 'NOT NULL');

                return array_key_exists('default', $def) ? $sql . " DEFAULT '{$def['default']}'" : $sql;
            }

            public function add_field(array $campos)
            {
                $this->campos = $campos;
            }

            public function add_key($chave, $primaria = false)
            {
            }

            public function create_table($tabela, $seNaoExiste = false, array $atributos = [])
            {
                $colunas = [];
                foreach ($this->campos as $nome => $def) {
                    $colunas[] = $this->coluna($nome, $def);
                }
                $this->db->query("CREATE TABLE IF NOT EXISTS {$tabela} (" . implode(', ', $colunas) . ')');
                unset($this->db->data_cache['table_names']);
            }

            public function drop_table($tabela, $seExiste = false)
            {
                $this->db->query("DROP TABLE IF EXISTS {$tabela}");
                unset($this->db->data_cache['table_names']);
            }

            public function add_column($tabela, array $campos)
            {
                foreach ($campos as $nome => $def) {
                    $this->db->query("ALTER TABLE {$tabela} ADD COLUMN " . $this->coluna($nome, $def));
                }
            }

            public function drop_column($tabela, $nome)
            {
                $this->db->query("ALTER TABLE {$tabela} DROP COLUMN {$nome}");
            }
        };
    }

    /** dbforge que não faz nada: simula a DDL que falha em silêncio com db_debug desligado. */
    private function dbforgeQueFalhaEmSilencio(): object
    {
        return new class() {
            public function __call($metodo, $argumentos)
            {
                return null;
            }
        };
    }

    private function migracao(string $classe, bool $falhaEmSilencio = false): object
    {
        $migration = new $classe();
        $migration->db = $this->conexao($falhaEmSilencio);
        $migration->dbforge = $falhaEmSilencio ? $this->dbforgeQueFalhaEmSilencio() : $this->dbforge();

        return $migration;
    }

    private function indices(string $tabela): array
    {
        return array_values(array_unique(array_column($this->conexao()->query("SHOW INDEX FROM {$tabela}")->result_array(), 'Key_name')));
    }

    // --- webhook_eventos ------------------------------------------------------

    public function testWebhookEventosUpCriaATabelaEOIndiceUnico(): void
    {
        $this->migracao('Migration_create_webhook_eventos')->up();

        $this->assertTrue($this->db->table_exists('webhook_eventos'));
        $this->assertSame(
            ['id', 'gateway', 'evento_id', 'cobranca_id', 'payload_hash', 'resultado', 'processado_em', 'created_at'],
            $this->db->list_fields('webhook_eventos')
        );
        $unico = array_filter($this->conexao()->query('SHOW INDEX FROM webhook_eventos')->result_array(), static fn ($l) => $l['Key_name'] === 'webhook_eventos_gateway_evento');
        $this->assertSame(['gateway', 'evento_id'], array_column(array_values($unico), 'Column_name'));
        $this->assertSame([0, 0], array_column(array_values($unico), 'Non_unique'));
        $this->assertContains('webhook_eventos_cobranca', $this->indices('webhook_eventos'));
        $this->assertContains('webhook_eventos_created', $this->indices('webhook_eventos'));
    }

    public function testWebhookEventosIndiceUnicoBarraEventoRepetido(): void
    {
        $this->migracao('Migration_create_webhook_eventos')->up();
        $inserir = "INSERT INTO webhook_eventos (gateway, evento_id, payload_hash, created_at) VALUES ('efi', 'e1', 'h', '2026-11-03 10:00:00')";

        $this->assertTrue((bool) $this->db->query($inserir));
        $this->db->db_debug = false;
        $this->assertFalse(@$this->db->query($inserir), 'O mesmo (gateway, evento_id) não entra duas vezes.');
        $this->db->db_debug = true;
        $this->assertTrue((bool) $this->db->query(str_replace("'efi'", "'asaas'", $inserir)), 'Outro gateway com o mesmo evento_id entra.');
    }

    public function testWebhookEventosDownRemoveATabela(): void
    {
        $migration = $this->migracao('Migration_create_webhook_eventos');
        $migration->up();
        $migration->down();

        $this->assertFalse($this->db->table_exists('webhook_eventos'));
    }

    public function testWebhookEventosCriaOIndiceUnicoPorSqlExplicito(): void
    {
        $fonte = (string) file_get_contents(APPPATH . 'database/migrations/20261103000000_create_webhook_eventos.php');

        $this->assertMatchesRegularExpression('/CREATE UNIQUE INDEX webhook_eventos_gateway_evento ON/', $fonte);
    }

    // --- api_refresh_tokens -----------------------------------------------------

    public function testRefreshTokensUpMantemLegadoComoUsuarioETrocaOIndice(): void
    {
        $this->bancoAntes();
        $this->db->query("INSERT INTO api_refresh_tokens (usuario_id, familia) VALUES (1, 'a'), (2, 'b')");

        $this->migracao('Migration_add_tipo_to_api_refresh_tokens')->up();

        $tipos = array_column($this->db->query('SELECT tipo FROM api_refresh_tokens')->result_array(), 'tipo');
        $this->assertSame(['usuario', 'usuario'], $tipos);
        $this->assertContains('api_refresh_tokens_dono', $this->indices('api_refresh_tokens'));
        $this->assertNotContains('usuario_id', $this->indices('api_refresh_tokens'));
    }

    public function testRefreshTokensDownApagaTokensDeClienteEDesfaz(): void
    {
        $this->bancoAntes();
        $migration = $this->migracao('Migration_add_tipo_to_api_refresh_tokens');
        $migration->up();
        $this->db->query("INSERT INTO api_refresh_tokens (tipo, usuario_id, familia) VALUES ('usuario', 1, 'a'), ('cliente', 1, 'b')");

        $migration->down();

        $this->assertSame([['familia' => 'a']], $this->db->query('SELECT familia FROM api_refresh_tokens')->result_array());
        $this->assertFalse($this->db->field_exists('tipo', 'api_refresh_tokens'));
        $this->assertContains('usuario_id', $this->indices('api_refresh_tokens'));
        $this->assertNotContains('api_refresh_tokens_dono', $this->indices('api_refresh_tokens'));
    }

    public function testRefreshTokensDownCriteriosRemovemTokenDeCliente(): void
    {
        $fonte = (string) file_get_contents(APPPATH . 'database/migrations/20261103000100_add_tipo_to_api_refresh_tokens.php');

        $this->assertMatchesRegularExpression("/DELETE FROM .*tipo = 'cliente'/", $fonte);
    }

    // --- clientes -------------------------------------------------------------

    public function testClientesUpCriaAsDuasColunasComZero(): void
    {
        $this->bancoAntes();
        $this->db->query("INSERT INTO clientes (nomeCliente) VALUES ('Ana')");

        $this->migracao('Migration_add_senha_trocar_sessao_versao_to_clientes')->up();

        $linha = $this->db->query('SELECT senha_trocar, sessao_versao FROM clientes')->row_array();
        $this->assertSame(0, (int) $linha['senha_trocar']);
        $this->assertSame(0, (int) $linha['sessao_versao']);
    }

    public function testClientesDownRemoveAsDuasColunas(): void
    {
        $this->bancoAntes();
        $migration = $this->migracao('Migration_add_senha_trocar_sessao_versao_to_clientes');
        $migration->up();
        $migration->down();

        $this->assertFalse($this->db->field_exists('senha_trocar', 'clientes'));
        $this->assertFalse($this->db->field_exists('sessao_versao', 'clientes'));
    }

    // --- email_queue ----------------------------------------------------------

    public function testEmailQueueUpCriaColunasPadraoZeroEIndice(): void
    {
        $this->bancoAntes();
        $this->db->query("INSERT INTO email_queue (status) VALUES ('pending')");

        $this->migracao('Migration_add_reserva_to_email_queue')->up();

        $linha = $this->db->query('SELECT tentativas, reservado_por, reservado_em FROM email_queue')->row_array();
        $this->assertSame(0, (int) $linha['tentativas']);
        $this->assertNull($linha['reservado_por']);
        $this->assertNull($linha['reservado_em']);
        $this->assertContains('email_queue_status_reserva', $this->indices('email_queue'));
    }

    public function testEmailQueueDownDesfaz(): void
    {
        $this->bancoAntes();
        $migration = $this->migracao('Migration_add_reserva_to_email_queue');
        $migration->up();
        $migration->down();

        foreach (['tentativas', 'reservado_por', 'reservado_em'] as $coluna) {
            $this->assertFalse($this->db->field_exists($coluna, 'email_queue'));
        }
        $this->assertNotContains('email_queue_status_reserva', $this->indices('email_queue'));
    }

    // --- cobrancas ------------------------------------------------------------

    public function testCobrancasUpCriaOIndiceQuandoNaoHaNenhumPorChargeId(): void
    {
        $this->bancoAntes();

        $this->migracao('Migration_add_index_charge_to_cobrancas')->up();

        $this->assertContains('cobrancas_charge_gateway', $this->indices('cobrancas'));
    }

    public function testCobrancasUpNaoCriaQuandoJaHaIndiceComecandoPorChargeId(): void
    {
        $this->bancoAntes();
        $this->db->query('CREATE INDEX idx_charge ON cobrancas (charge_id)');

        $this->migracao('Migration_add_index_charge_to_cobrancas')->up();

        $this->assertSame(['idx_charge'], $this->indices('cobrancas'));
    }

    public function testCobrancasIndiceComChargeIdSoNaSegundaColunaNaoContaEUpCria(): void
    {
        $this->bancoAntes();
        $this->db->query('CREATE INDEX idx_gateway_charge ON cobrancas (payment_gateway, charge_id)');

        $this->migracao('Migration_add_index_charge_to_cobrancas')->up();

        $this->assertContains('cobrancas_charge_gateway', $this->indices('cobrancas'));
    }

    public function testCobrancasDownRemoveSoOIndiceDaMigration(): void
    {
        $this->bancoAntes();
        $this->db->query('CREATE INDEX idx_charge ON cobrancas (charge_id)');
        $migration = $this->migracao('Migration_add_index_charge_to_cobrancas');
        $migration->up();
        $migration->down();

        $this->assertSame(['idx_charge'], $this->indices('cobrancas'), 'Sem o índice da migration, down() não toca nos outros.');
    }

    // --- Falha silenciosa -------------------------------------------------------

    public static function todasAsMigrations(): array
    {
        return array_map(static fn ($classe) => [$classe], self::MIGRATIONS);
    }

    #[DataProvider('todasAsMigrations')]
    public function testFalhaDaDdlEmSilencioLancaRuntimeException(string $classe): void
    {
        $this->bancoAntes();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('veja o log da aplicação');

        $this->migracao($classe, true)->up();
    }

    public function testFalhaNoIndiceDepoisDeCriarAColunaLancaRuntimeException(): void
    {
        $this->bancoAntes();
        $migration = $this->migracao('Migration_add_reserva_to_email_queue');
        $migration->db = $this->conexao(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('email_queue_status_reserva');

        $migration->up();
    }

    // --- Rodar duas vezes -------------------------------------------------------

    #[DataProvider('todasAsMigrations')]
    public function testIdempotenteUpDuasVezesNaoLancaENaoDuplica(string $classe): void
    {
        $this->bancoAntes();
        $migration = $this->migracao($classe);

        $migration->up();
        $camposDepoisDoPrimeiro = [];
        foreach (['webhook_eventos', 'api_refresh_tokens', 'clientes', 'email_queue', 'cobrancas'] as $tabela) {
            if ($this->db->table_exists($tabela)) {
                $camposDepoisDoPrimeiro[$tabela] = [$this->db->list_fields($tabela), $this->indices($tabela)];
            }
        }
        $migration->up();

        foreach ($camposDepoisDoPrimeiro as $tabela => $estado) {
            $this->assertSame($estado, [$this->db->list_fields($tabela), $this->indices($tabela)], "Segundo up() mudou {$tabela}.");
        }
    }

    // --- Contrato -----------------------------------------------------------------

    public function testCadaMigrationConferePeloResultadoELancaRuntimeException(): void
    {
        foreach (glob(APPPATH . 'database/migrations/202611030*.php') as $arquivo) {
            $this->assertStringContainsString('throw new RuntimeException', (string) file_get_contents($arquivo), basename($arquivo));
        }
    }

    public function testBancoSqlNaoEhAlteradoPelasMigrations(): void
    {
        $this->assertStringNotContainsString('webhook_eventos', (string) file_get_contents(MAPOS_ROOT . '/banco.sql'));
    }
}
