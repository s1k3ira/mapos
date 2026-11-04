<?php

require_once APPPATH . 'helpers/conecte_helper.php';
require_once APPPATH . 'helpers/os_helper.php';
require_once APPPATH . 'models/Conecte_model.php';
require_once APPPATH . 'libraries/REST_Controller.php';
require_once APPPATH . 'controllers/api/v1/client/ClientOsController.php';

/**
 * Limite diário de abertura de OS pelo cliente (CLIENTE_OS_LIMITE_DIARIO): os
 * helpers, a contagem atômica do model e o POST /api/v1/client/os.
 */
final class LimiteDeOsDoClienteTest extends MaposTestCase
{
    private mixed $limiteAnterior = null;

    private bool $tinhaLimite = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tinhaLimite = array_key_exists('CLIENTE_OS_LIMITE_DIARIO', $_ENV);
        $this->limiteAnterior = $_ENV['CLIENTE_OS_LIMITE_DIARIO'] ?? null;
        unset($_ENV['CLIENTE_OS_LIMITE_DIARIO']);

        $this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT)');
        $this->db->query('CREATE TABLE usuarios (idUsuarios INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, situacao INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE os (idOs INTEGER PRIMARY KEY AUTOINCREMENT, clientes_id INTEGER, usuarios_id INTEGER, dataInicial TEXT, dataFinal TEXT, descricaoProduto TEXT, defeito TEXT, status TEXT, observacoes TEXT, faturado INTEGER)');
        $this->criarHistorico();
        $this->db->query("INSERT INTO clientes (idClientes, nomeCliente) VALUES (7, 'Ana'), (8, 'Beto')");
    }

    protected function tearDown(): void
    {
        if ($this->tinhaLimite) {
            $_ENV['CLIENTE_OS_LIMITE_DIARIO'] = $this->limiteAnterior;
        } else {
            unset($_ENV['CLIENTE_OS_LIMITE_DIARIO']);
        }

        parent::tearDown();
    }

    private function criarHistorico(): void
    {
        $this->db->query('CREATE TABLE os_historico (idHistorico INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, status_anterior TEXT, status_novo TEXT, usuarios_id INTEGER, data_hora TEXT)');
    }

    private function model(): Conecte_model
    {
        // SQLite não tem SELECT ... FOR UPDATE: só o lock é trocado.
        $model = new class() extends Conecte_model {
            public $db;

            public function __construct()
            {
            }

            protected function travarCliente(int $idCliente): bool
            {
                $consulta = $this->db->query('SELECT idClientes FROM clientes WHERE idClientes = ?', [$idCliente]);

                return $consulta !== false && $consulta->row() !== null;
            }
        };
        $model->db = $this->db;

        return $model;
    }

    /** @return array<string, mixed> */
    private function dadosDaOs(): array
    {
        return [
            'dataInicial' => date('Y-m-d'),
            'usuarios_id' => 3,
            'dataFinal' => date('Y-m-d'),
            'descricaoProduto' => 'Notebook',
            'defeito' => 'Não liga',
            'status' => 'Aberto',
            'observacoes' => '',
            'faturado' => 0,
        ];
    }

    /** Uma OS do cliente com a linha de histórico de abertura em $dataHora. */
    private function abertura(int $cliente, ?string $dataHora = null, ?int $operador = null): void
    {
        $this->db->query('INSERT INTO os (clientes_id, usuarios_id, status) VALUES (?, 3, \'Aberto\')', [$cliente]);
        $this->db->query(
            "INSERT INTO os_historico (os_id, status_anterior, status_novo, usuarios_id, data_hora) VALUES (?, NULL, 'Aberto', ?, ?)",
            [(int) $this->db->insert_id(), $operador, $dataHora ?? date('Y-m-d H:i:s')]
        );
    }

    private function aberturaHoje(int $cliente): void
    {
        $this->abertura($cliente, date('Y-m-d') . ' 00:00:00');
    }

    // --- Helpers -------------------------------------------------------------

    public function testLimiteDiarioLidoDoEnv(): void
    {
        $this->assertSame(10, clienteOsLimiteDiario());

        $_ENV['CLIENTE_OS_LIMITE_DIARIO'] = '3';
        $this->assertSame(3, clienteOsLimiteDiario());

        $_ENV['CLIENTE_OS_LIMITE_DIARIO'] = '0';
        $this->assertSame(0, clienteOsLimiteDiario());

        foreach (['', 'abc', '-2', '2.5'] as $invalido) {
            $_ENV['CLIENTE_OS_LIMITE_DIARIO'] = $invalido;
            $this->assertSame(10, clienteOsLimiteDiario(), "valor '{$invalido}'");
        }
    }

    public function testSegundosAteAmanha(): void
    {
        $this->assertSame(60, segundosAteAmanha(strtotime('2026-11-04 23:59:00')));
        $this->assertSame(86400, segundosAteAmanha(strtotime('2026-11-04 00:00:00')));
    }

    public function testErrosDaNovaOs(): void
    {
        $this->assertSame([], conecteErrosDaNovaOs(['descricaoProduto' => 'Impressora']));
        $this->assertSame(['descricaoProduto' => 'Informe o equipamento ou serviço.'], conecteErrosDaNovaOs(['descricaoProduto' => '   ']));
        $this->assertSame(['descricaoProduto' => 'Informe o equipamento ou serviço.'], conecteErrosDaNovaOs(['descricaoProduto' => ['x']]));
        $this->assertSame(['defeito' => 'Use no máximo 2000 caracteres.'], conecteErrosDaNovaOs(['descricaoProduto' => 'a', 'defeito' => str_repeat('x', 2001)]));
    }

    // --- Model ---------------------------------------------------------------

    public function testAberturasDeHojeContaSoAsDoClienteNoDia(): void
    {
        $this->aberturaHoje(7);
        $this->abertura(7, date('Y-m-d') . ' 15:00:00');
        $this->abertura(7, date('Y-m-d', strtotime('yesterday')) . ' 23:59:59');
        $this->abertura(7, date('Y-m-d') . ' 10:00:00', 3);
        $this->abertura(8, date('Y-m-d') . ' 10:00:00');

        $this->assertSame(2, $this->model()->aberturasDeOsHoje(7));
    }

    public function testAberturasSemHistoricoDevolveZero(): void
    {
        $this->db->query('DROP TABLE os_historico');

        $this->assertSame(0, $this->model()->aberturasDeOsHoje(7));
    }

    public function testAbrirOsAbaixoDoLimiteCriaOsEHistorico(): void
    {
        $this->aberturaHoje(7);

        $resultado = $this->model()->abrirOsDoCliente(7, $this->dadosDaOs(), 2);

        $this->assertTrue($resultado['ok']);
        $this->assertSame(2, $resultado['id']);
        $this->assertSame(7, (int) $this->db->query('SELECT clientes_id FROM os WHERE idOs = 2')->row()->clientes_id);
        $historico = $this->db->query('SELECT * FROM os_historico WHERE os_id = 2')->result();
        $this->assertCount(1, $historico);
        $this->assertNull($historico[0]->status_anterior);
        $this->assertSame('Aberto', $historico[0]->status_novo);
        $this->assertNull($historico[0]->usuarios_id);
    }

    public function testDecimaPrimeiraAberturaNaoCriaAOs(): void
    {
        $this->aberturaHoje(7);
        $this->aberturaHoje(7);
        $antes = $this->db->count_all('os');
        $historicoAntes = $this->db->count_all('os_historico');

        $resultado = $this->model()->abrirOsDoCliente(7, $this->dadosDaOs(), 2);

        $this->assertSame(['ok' => false, 'motivo' => 'limite'], $resultado);
        $this->assertSame($antes, $this->db->count_all('os'));
        $this->assertSame($historicoAntes, $this->db->count_all('os_historico'));
    }

    public function testLimiteZeroDesligaOLimite(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->aberturaHoje(7);
        }

        $resultado = $this->model()->abrirOsDoCliente(7, $this->dadosDaOs(), 0);

        $this->assertTrue($resultado['ok']);
        $this->assertSame(16, $resultado['id']);
    }

    public function testFalhaNoHistoricoDesfazAOs(): void
    {
        $this->db->query('DROP TABLE os_historico');
        $this->db->query('CREATE TABLE os_historico (idHistorico INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, status_anterior TEXT, usuarios_id INTEGER, data_hora TEXT)');

        // O driver do SQLite avisa (warning) sobre a coluna inexistente: é a falha provocada.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $resultado = $this->model()->abrirOsDoCliente(7, $this->dadosDaOs(), 5);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['ok' => false, 'motivo' => 'erro'], $resultado);
        $this->assertSame(0, $this->db->count_all('os'));
        $this->assertLogContem('error', 'transacao rollback operacao=abrir_os_cliente id=7');
    }

    public function testTecnicoComMenosOsRecebeANova(): void
    {
        $this->db->query("INSERT INTO os (usuarios_id) VALUES (3), (3), (4)");

        $this->assertSame(4, $this->model()->tecnicoParaNovaOs());
    }

    public function testSemOsEscolheOPrimeiroUsuarioAtivo(): void
    {
        $this->db->query("INSERT INTO usuarios (idUsuarios, nome, situacao) VALUES (5, 'Inativo', 0), (6, 'Ativo', 1)");

        $this->assertSame(6, $this->model()->tecnicoParaNovaOs());
    }

    // --- API -----------------------------------------------------------------

    /** @param array<string, mixed> $corpo */
    private function api(array $corpo, ?int $agora = null, bool $falhaNoModel = false): object
    {
        $model = $this->model();
        if ($falhaNoModel) {
            $model = new class() extends Conecte_model {
                public function __construct()
                {
                }

                public function tecnicoParaNovaOs(): ?int
                {
                    return 3;
                }

                public function abrirOsDoCliente(int $idCliente, array $dados, int $limite): array
                {
                    return ['ok' => false, 'motivo' => 'erro'];
                }
            };
        }

        return new class($model, $corpo, $agora ?? time()) extends ClientOsController {
            public $Conecte_model;

            public $Audit_model;

            public $load;

            public $input;

            public $output;

            /** @param array<string, mixed> $corpo */
            public function __construct($model, private array $corpo, private int $relogio)
            {
                $this->Conecte_model = $model;
                $this->Audit_model = new class() {
                    public function add($dados): void
                    {
                    }
                };
                $this->load = new class() {
                    public function __call($nome, $args)
                    {
                        return null;
                    }
                };
                $this->input = new class() {
                    public function ip_address(): string
                    {
                        return '10.0.0.1';
                    }
                };
                $this->output = new class() {
                    /** @var list<string> */
                    public array $cabecalhos = [];

                    public function set_header(string $cabecalho): void
                    {
                        $this->cabecalhos[] = $cabecalho;
                    }
                };
            }

            public function __destruct()
            {
                // O destruidor real grava o log da requisição no banco.
            }

            public function response($data = null, $http_code = null, $continue = false)
            {
                throw new RuntimeException(json_encode(['code' => $http_code, 'data' => $data]));
            }

            public function logged_client($permitirTroca = false)
            {
                return (object) ['usuario' => (object) ['idClientes' => 7, 'nomeCliente' => 'Ana']];
            }

            protected function corpoJson(): array
            {
                return $this->corpo;
            }

            protected function agora(): int
            {
                return $this->relogio;
            }
        };
    }

    /** @return array{code: int, data: array<string, mixed>} */
    private function chamar(object $api, string $metodo): array
    {
        try {
            $api->{$metodo}();
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        $this->fail('A API deveria ter respondido.');
    }

    public function testApiAcimaDoLimiteResponde429ComRetryAfter(): void
    {
        $_ENV['CLIENTE_OS_LIMITE_DIARIO'] = '1';
        $this->aberturaHoje(7);
        $api = $this->api(['descricaoProduto' => 'Impressora'], strtotime(date('Y-m-d') . ' 23:59:00'));
        $antes = $this->db->count_all('os');

        $resposta = $this->chamar($api, 'os_post');

        $this->assertSame(429, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência.'], $resposta['data']);
        $this->assertSame(['Retry-After: 60'], $api->output->cabecalhos);
        $this->assertSame($antes, $this->db->count_all('os'));
    }

    public function testApiAbaixoDoLimiteResponde201EGravaHistorico(): void
    {
        $this->db->query("INSERT INTO usuarios (idUsuarios, nome, situacao) VALUES (3, 'Tec', 1)");
        $api = $this->api(['descricaoProduto' => 'Impressora', 'defeito' => 'Não puxa papel']);

        $resposta = $this->chamar($api, 'os_post');

        $this->assertSame(201, $resposta['code']);
        $this->assertSame(['status' => true, 'message' => 'Ordem de serviço criada com sucesso.', 'os_id' => 1], $resposta['data']);
        $this->assertSame(1, $this->db->count_all('os_historico'));
        $this->assertSame('Aberto', $this->db->query('SELECT status_novo FROM os_historico WHERE os_id = 1')->row()->status_novo);
    }

    public function testApiComDadosInvalidosResponde422(): void
    {
        $resposta = $this->chamar($this->api(['descricaoProduto' => '   ']), 'os_post');

        $this->assertSame(422, $resposta['code']);
        $this->assertSame(
            ['status' => false, 'message' => 'Dados inválidos.', 'erros' => ['descricaoProduto' => 'Informe o equipamento ou serviço.']],
            $resposta['data']
        );

        $resposta = $this->chamar($this->api(['descricaoProduto' => 'Impressora', 'defeito' => str_repeat('x', 2001)]), 'os_post');

        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Use no máximo 2000 caracteres.', $resposta['data']['erros']['defeito']);
        $this->assertSame(0, $this->db->count_all('os'));
    }

    public function testApiSemTecnicoDisponivelResponde500(): void
    {
        $resposta = $this->chamar($this->api(['descricaoProduto' => 'Impressora']), 'os_post');

        $this->assertSame(500, $resposta['code']);
        $this->assertSame('Ocorreu um erro ao atribuir a ordem de serviço. Por favor, contate o administrador do sistema.', $resposta['data']['message']);
    }

    public function testApiComFalhaAoGravarResponde500(): void
    {
        $resposta = $this->chamar($this->api(['descricaoProduto' => 'Impressora'], null, true), 'os_post');

        $this->assertSame(500, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Ocorreu um erro ao criar a ordem de serviço.'], $resposta['data']);
    }
}
