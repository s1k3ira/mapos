<?php

require_once BASEPATH . 'libraries/Email.php';
require_once APPPATH . 'libraries/MY_Email.php';
require_once APPPATH . 'controllers/Email.php';

/**
 * Fila de e-mail: lock, reserva atômica por lote, tentativas, log sem corpo e
 * código de saída do cron. O SQLite não tem GET_LOCK, então o lock e o envio
 * são pontos substituíveis da fila de teste.
 */
final class EmailFilaTest extends MaposTestCase
{
    private const AGORA = '2026-11-03 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('CREATE TABLE email_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, "to" TEXT NOT NULL, cc TEXT, bcc TEXT, message TEXT NOT NULL, status TEXT, date DATETIME, headers TEXT, tentativas INTEGER NOT NULL DEFAULT 0, reservado_por CHAR(32) NULL, reservado_em DATETIME NULL)');
    }

    public function testLockOcupadoNaoEnviaNada(): void
    {
        $this->enfileirar(3);
        $antes = $this->linhas();
        $envios = [];
        $fila = $this->fila(envio: static function (object $email) use (&$envios): bool {
            $envios[] = $email->id;

            return true;
        }, lockLivre: false);

        $resultado = $fila->processarFila();

        $this->assertSame(['ignorado' => true, 'reservados' => 0, 'enviados' => 0, 'falhas' => 0, 'definitivos' => 0], $resultado);
        $this->assertSame([], $envios);
        $this->assertSame($antes, $this->linhas());
    }

    public function testCicloNormalEnviaEContaTentativa(): void
    {
        $this->enfileirar(3);
        $fila = $this->fila();

        $resultado = $fila->processarFila();

        $this->assertSame(['ignorado' => false, 'reservados' => 3, 'enviados' => 3, 'falhas' => 0, 'definitivos' => 0], $resultado);
        $this->assertSame(['1' => 'sent', '2' => 'sent', '3' => 'sent'], $this->colunaPorId('status'));
        $this->assertSame(['1' => '1', '2' => '1', '3' => '1'], $this->colunaPorId('tentativas'));
        $this->assertSame(['1' => self::AGORA, '2' => self::AGORA, '3' => self::AGORA], $this->colunaPorId('date'));
    }

    public function testReservaNoMaximoUmLoteDeCinquenta(): void
    {
        $this->enfileirar(60);
        $enviados = [];
        $fila = $this->fila(envio: static function (object $email) use (&$enviados): bool {
            $enviados[] = (int) $email->id;

            return true;
        });

        $resultado = $fila->processarFila();

        $this->assertSame(50, $resultado['reservados']);
        $this->assertSame(range(1, 50), $enviados);
        $restantes = $this->db->where('status', 'pending')->where('reservado_por IS NULL', null, false)->count_all_results('email_queue');
        $this->assertSame(10, $restantes);
        $this->assertSame(range(51, 60), array_map('intval', array_keys(array_filter($this->colunaPorId('status'), static fn (string $s): bool => $s === 'pending'))));
    }

    public function testDuasExecucoesNaoReservamOMesmoEmail(): void
    {
        $this->enfileirar(1);
        $this->db->where('id', 1)->update('email_queue', ['status' => 'sending', 'reservado_por' => str_repeat('a', 32), 'reservado_em' => self::AGORA, 'tentativas' => 1]);
        $envios = [];
        $fila = $this->fila(envio: static function (object $email) use (&$envios): bool {
            $envios[] = $email->id;

            return true;
        });

        $resultado = $fila->processarFila();

        $this->assertSame(0, $resultado['reservados']);
        $this->assertSame([], $envios);
        $this->assertSame(['1' => 'sending'], $this->colunaPorId('status'));
        $this->assertSame(['1' => str_repeat('a', 32)], $this->colunaPorId('reservado_por'));
    }

    public function testPendingComCincoTentativasNaoEReservado(): void
    {
        $this->enfileirar(1);
        $this->db->where('id', 1)->update('email_queue', ['tentativas' => 5]);
        $fila = $this->fila();

        $resultado = $fila->processarFila();

        $this->assertSame(['ignorado' => false, 'reservados' => 0, 'enviados' => 0, 'falhas' => 0, 'definitivos' => 0], $resultado);
        $this->assertSame(['1' => 'pending'], $this->colunaPorId('status'));
        $this->assertSame(['1' => '5'], $this->colunaPorId('tentativas'));
    }

    public function testExcecaoNumEmailNaoImpedeOsDemaisDoLote(): void
    {
        $this->enfileirar(3);
        $fila = $this->fila(envio: fn (object $email): bool => (int) $email->id === 2 ? throw new RuntimeException('smtp caiu') : true);

        $resultado = $fila->processarFila();

        $this->assertSame(['ignorado' => false, 'reservados' => 3, 'enviados' => 2, 'falhas' => 1, 'definitivos' => 0], $resultado);
        $this->assertSame(['1' => 'sent', '2' => 'failed', '3' => 'sent'], $this->colunaPorId('status'));
        $this->assertSame(['1' => '1', '2' => '1', '3' => '1'], $this->colunaPorId('tentativas'));
    }

    public function testQuintaFalhaEDefinitiva(): void
    {
        $this->enfileirar(1);
        $this->db->where('id', 1)->update('email_queue', ['tentativas' => 4]);
        $fila = $this->fila(envio: static fn (object $email): bool => false);

        $resultado = $fila->processarFila();

        $this->assertSame(['ignorado' => false, 'reservados' => 1, 'enviados' => 0, 'falhas' => 1, 'definitivos' => 1], $resultado);
        $this->assertSame(['1' => 'failed'], $this->colunaPorId('status'));
        $this->assertSame(['1' => '5'], $this->colunaPorId('tentativas'));
    }

    public function testFalhaNaoLogaOCorpo(): void
    {
        $this->enfileirar(3);
        $this->db->where('id', 2)->update('email_queue', ['message' => 'Redefina: https://mapos.test/mine/senha/TOKEN-SECRETO']);
        $fila = $this->fila(envio: static fn (object $email): bool => (int) $email->id !== 2);

        $fila->processarFila();

        $this->assertLogContem('error', 'email_fila falha id=2 tentativas=1');
        $this->assertLogNaoContem('TOKEN-SECRETO');
    }

    public function testRetryReabreSoFalhasComTentativasRestantes(): void
    {
        $this->enfileirar(2);
        $this->db->where('id', 1)->update('email_queue', ['status' => 'failed', 'tentativas' => 2]);
        $this->db->where('id', 2)->update('email_queue', ['status' => 'failed', 'tentativas' => 5]);
        $fila = $this->fila();

        $resultado = $fila->reabrirFila();

        $this->assertSame(['reabertos' => 1, 'definitivos' => 0], $resultado);
        $this->assertSame(['1' => 'pending', '2' => 'failed'], $this->colunaPorId('status'));
    }

    public function testReservaExpirada(): void
    {
        $this->enfileirar(5);
        $reserva = str_repeat('b', 32);
        $expirada = date('Y-m-d H:i:s', strtotime(self::AGORA) - 16 * 60);
        $recente = date('Y-m-d H:i:s', strtotime(self::AGORA) - 10 * 60);
        $this->db->where('id', 1)->update('email_queue', ['status' => 'sending', 'tentativas' => 2, 'reservado_por' => $reserva, 'reservado_em' => $expirada]);
        $this->db->where('id', 2)->update('email_queue', ['status' => 'sending', 'tentativas' => 5, 'reservado_por' => $reserva, 'reservado_em' => $expirada]);
        $this->db->where('id', 3)->update('email_queue', ['status' => 'sending', 'tentativas' => 2, 'reservado_por' => $reserva, 'reservado_em' => $recente]);
        $this->db->where('id', 4)->update('email_queue', ['status' => 'sending', 'tentativas' => 1, 'date' => $expirada]);
        $this->db->where('id', 5)->update('email_queue', ['status' => 'sending', 'tentativas' => 5, 'date' => $expirada]);
        $fila = $this->fila();

        $resultado = $fila->reabrirFila();

        $this->assertSame(['reabertos' => 2, 'definitivos' => 2], $resultado);
        $this->assertSame(['1' => 'pending', '2' => 'failed', '3' => 'sending', '4' => 'pending', '5' => 'failed'], $this->colunaPorId('status'));
        $this->assertSame(['1' => null, '2' => $reserva, '3' => $reserva, '4' => null, '5' => null], $this->colunaPorId('reservado_por'));
        $this->assertSame(['1' => null, '2' => $expirada, '3' => $recente, '4' => null, '5' => null], $this->colunaPorId('reservado_em'));
    }

    public function testCodigoDeSaidaZeroNoCicloNormal(): void
    {
        $this->enfileirar(3);
        $fila = $this->fila();

        $codigo = $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['process', $fila]);

        $this->assertSame(0, $codigo);
        $this->assertLogContem('info', 'email_fila ciclo=process inicio');
        $this->assertLogContem('info', 'email_fila ciclo=process reservados=3 enviados=3 falhas=0 definitivos=0');
    }

    public function testCodigoDeSaidaZeroComLockOcupado(): void
    {
        $this->enfileirar(3);
        $fila = $this->fila(lockLivre: false);

        $codigo = $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['process', $fila]);

        $this->assertSame(0, $codigo);
        $this->assertLogContem('info', 'email_fila ciclo=process ignorado');
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
        $this->assertLogNaoContem('banco fora');
    }

    public function testCicloRetryLogaReabertosEDefinitivos(): void
    {
        $this->enfileirar(1);
        $this->db->where('id', 1)->update('email_queue', ['status' => 'failed', 'tentativas' => 1]);
        $fila = $this->fila();

        $codigo = $this->invokeMethod($this->makeInstance(Email::class), 'executarCiclo', ['retry', $fila]);

        $this->assertSame(0, $codigo);
        $this->assertLogContem('info', 'email_fila ciclo=retry reabertos=1 definitivos=0');
    }

    private function enfileirar(int $quantidade): void
    {
        for ($i = 1; $i <= $quantidade; $i++) {
            $this->db->insert('email_queue', [
                'to' => "destino{$i}@mapos.test",
                'cc' => '',
                'bcc' => '',
                'message' => "Corpo {$i}",
                'headers' => '{}',
                'status' => 'pending',
                'date' => '2026-11-03 11:00:00',
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linhas(): array
    {
        return $this->db->order_by('id')->get('email_queue')->result_array();
    }

    /**
     * @return array<string, mixed>
     */
    private function colunaPorId(string $coluna): array
    {
        $mapa = [];
        foreach ($this->linhas() as $linha) {
            $valor = $linha[$coluna];
            $mapa[(string) $linha['id']] = $coluna === 'tentativas' ? (string) $valor : $valor;
        }

        return $mapa;
    }

    private function fila(?callable $envio = null, bool $lockLivre = true): MY_Email
    {
        $fila = new class() extends MY_Email {
            public $envio;

            public bool $lockLivre = true;

            public function __construct()
            {
            }

            protected function obterLock(): bool
            {
                return $this->lockLivre;
            }

            protected function liberarLock(): void
            {
            }

            protected function enviarDaFila(object $email): bool
            {
                return ($this->envio)($email);
            }

            protected function agora(): int
            {
                return strtotime('2026-11-03 12:00:00');
            }
        };
        $this->setPrivateProperty($fila, 'CI', (object) ['db' => $this->db]);
        $fila->envio = $envio ?? static fn (object $email): bool => true;
        $fila->lockLivre = $lockLivre;

        return $fila;
    }
}
