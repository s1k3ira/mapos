<?php

require_once APPPATH . 'libraries/Retencao.php';
require_once BASEPATH . 'libraries/Email.php';
require_once APPPATH . 'libraries/MY_Email.php';
require_once APPPATH . 'controllers/Email.php';

/**
 * Retenção de webhook_eventos, api_refresh_tokens e email_queue, contra um
 * SQLite em memória. Relógio fixo: 2026-11-03 12:00:00.
 */
final class RetencaoTest extends MaposTestCase
{
    private function criarTabelas(bool $webhook = true): void
    {
        if ($webhook) {
            $this->db->query('CREATE TABLE webhook_eventos (id INTEGER PRIMARY KEY, created_at TEXT NOT NULL)');
        }
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY, expira_em TEXT NOT NULL, revogado_em TEXT NULL)');
        $this->db->query('CREATE TABLE email_queue (id INTEGER PRIMARY KEY, status TEXT, date TEXT)');
    }

    private function retencao(): Retencao
    {
        return new Retencao(['db' => $this->db, 'relogio' => fn () => strtotime('2026-11-03 12:00:00')]);
    }

    private function dias(int $n): string
    {
        return date('Y-m-d H:i:s', strtotime('2026-11-03 12:00:00') - $n * 86400);
    }

    private function ids(string $tabela): array
    {
        return array_map('intval', array_column($this->db->order_by('id')->get($tabela)->result_array(), 'id'));
    }

    public function testApagaEventoDe91DiasEMantemODe89(): void
    {
        $this->criarTabelas();
        $this->db->insert('webhook_eventos', ['id' => 1, 'created_at' => '2026-08-04 11:00:00']);
        $this->db->insert('webhook_eventos', ['id' => 2, 'created_at' => '2026-08-06 12:00:00']);

        $resultado = $this->retencao()->limpar();

        $this->assertSame(['webhook_eventos' => 1, 'api_refresh_tokens' => 0, 'email_queue' => 0], $resultado);
        $this->assertSame([2], $this->ids('webhook_eventos'));
    }

    public function testApagaNoMaximoOLotePorTabela(): void
    {
        $this->criarTabelas();
        for ($i = 1; $i <= 600; $i++) {
            $this->db->insert('webhook_eventos', ['id' => $i, 'created_at' => $this->dias(120)]);
        }

        $primeira = $this->retencao()->limpar();
        $this->assertSame(500, $primeira['webhook_eventos']);
        $restantes = $this->ids('webhook_eventos');
        $this->assertSame(100, count($restantes));
        $this->assertSame(501, $restantes[0]);

        $segunda = $this->retencao()->limpar();
        $this->assertSame(100, $segunda['webhook_eventos']);
        $this->assertSame([], $this->ids('webhook_eventos'));
    }

    public function testLoteInformadoLimitaAExclusao(): void
    {
        $this->criarTabelas();
        for ($i = 1; $i <= 25; $i++) {
            $this->db->insert('email_queue', ['id' => $i, 'status' => 'sent', 'date' => $this->dias(10)]);
        }

        $resultado = $this->retencao()->limpar(10);

        $this->assertSame(10, $resultado['email_queue']);
        $this->assertSame(15, count($this->ids('email_queue')));
    }

    public function testRefreshTokensExpiradosOuRevogadosHaTrintaDias(): void
    {
        $this->criarTabelas();
        $futuro = date('Y-m-d H:i:s', strtotime('2026-11-03 12:00:00') + 86400);
        $this->db->insert('api_refresh_tokens', ['id' => 1, 'expira_em' => $this->dias(31), 'revogado_em' => null]);
        $this->db->insert('api_refresh_tokens', ['id' => 2, 'expira_em' => $this->dias(29), 'revogado_em' => null]);
        $this->db->insert('api_refresh_tokens', ['id' => 3, 'expira_em' => $futuro, 'revogado_em' => $this->dias(31)]);
        $this->db->insert('api_refresh_tokens', ['id' => 4, 'expira_em' => $futuro, 'revogado_em' => $this->dias(2)]);

        $resultado = $this->retencao()->limpar();

        $this->assertSame(2, $resultado['api_refresh_tokens']);
        $this->assertSame([2, 4], $this->ids('api_refresh_tokens'));
    }

    public function testEmailQueueApagaSoSentEFailedAntigos(): void
    {
        $this->criarTabelas();
        $linhas = [
            [1, 'sent', 8], [2, 'sent', 6], [3, 'failed', 31], [4, 'failed', 29], [5, 'pending', 60], [6, 'sending', 60],
        ];
        foreach ($linhas as [$id, $status, $dias]) {
            $this->db->insert('email_queue', ['id' => $id, 'status' => $status, 'date' => $this->dias($dias)]);
        }

        $resultado = $this->retencao()->limpar();

        $this->assertSame(2, $resultado['email_queue']);
        $this->assertSame([2, 4, 5, 6], $this->ids('email_queue'));
    }

    public function testTabelaInexistenteContaZero(): void
    {
        $this->criarTabelas(false);
        $this->db->insert('email_queue', ['id' => 1, 'status' => 'sent', 'date' => $this->dias(10)]);

        $resultado = $this->retencao()->limpar();

        $this->assertSame(['webhook_eventos' => 0, 'api_refresh_tokens' => 0, 'email_queue' => 1], $resultado);
    }

    private function fila(): object
    {
        return new class() {
            public function reabrirFila(): array
            {
                return ['reabertos' => 0, 'definitivos' => 0];
            }
        };
    }

    public function testCicloRetryLogaARetencaoESaiZero(): void
    {
        $retencao = new class() {
            public function limpar(int $lote = 500): array
            {
                return ['webhook_eventos' => 1, 'api_refresh_tokens' => 2, 'email_queue' => 3];
            }
        };

        $codigo = $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['retry', $this->fila(), $retencao]);

        $this->assertSame(0, $codigo);
        $this->assertLogContem('info', 'retencao webhook_eventos=1 api_refresh_tokens=2 email_queue=3');
    }

    public function testFalhaNaRetencaoSaiUm(): void
    {
        $retencao = new class() {
            public function limpar(int $lote = 500): array
            {
                throw new RuntimeException('banco caiu');
            }
        };

        $codigo = $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['retry', $this->fila(), $retencao]);

        $this->assertSame(1, $codigo);
        $this->assertLogContem('error', 'email_fila ciclo=retry erro=RuntimeException');
    }

    public function testCicloProcessNaoChamaARetencao(): void
    {
        $fila = new class() {
            public function processarFila(): array
            {
                return ['ignorado' => false, 'reservados' => 0, 'enviados' => 0, 'falhas' => 0, 'definitivos' => 0];
            }
        };
        $retencao = new class() {
            public int $chamadas = 0;

            public function limpar(int $lote = 500): array
            {
                $this->chamadas++;

                return ['webhook_eventos' => 0, 'api_refresh_tokens' => 0, 'email_queue' => 0];
            }
        };

        $codigo = $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['process', $fila, $retencao]);

        $this->assertSame(0, $codigo);
        $this->assertSame(0, $retencao->chamadas);
    }
}
