<?php

require_once APPPATH . 'helpers/api_helper.php';
require_once APPPATH . 'models/Conecte_model.php';
require_once APPPATH . 'controllers/api/v1/client/ClientOsController.php';
require_once APPPATH . 'controllers/api/v1/client/ClientComprasController.php';
require_once APPPATH . 'controllers/api/v1/client/ClientCobrancasController.php';

/**
 * Paginação (page/perPage) das listagens da API do cliente e QR Code Pix da
 * compra pelo valor da venda. Executa os métodos reais dos controllers; o
 * Conecte_model registra o limite e o deslocamento recebidos.
 */
final class ApiClientePaginacaoTest extends MaposTestCase
{
    private mixed $chaveAnterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chaveAnterior = $_ENV['APP_ENCRYPTION_KEY'] ?? null;
        $_ENV['APP_ENCRYPTION_KEY'] ??= 'chave-de-teste';

        foreach ([
            'CREATE TABLE usuarios (idUsuarios INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, telefone TEXT, celular TEXT, email TEXT, senha TEXT)',
            'CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, documento TEXT, telefone TEXT, celular TEXT, email TEXT, rua TEXT, numero TEXT, complemento TEXT, bairro TEXT, cidade TEXT, estado TEXT, cep TEXT, senha TEXT)',
            'CREATE TABLE os (idOs INTEGER PRIMARY KEY AUTOINCREMENT, clientes_id INTEGER, usuarios_id INTEGER, status TEXT)',
            'CREATE TABLE vendas (idVendas INTEGER PRIMARY KEY AUTOINCREMENT, clientes_id INTEGER, usuarios_id INTEGER, valorTotal REAL)',
            'CREATE TABLE cobrancas (idCobranca INTEGER PRIMARY KEY AUTOINCREMENT, clientes_id INTEGER, expire_at TEXT)',
            'CREATE TABLE configuracoes (idConfig INTEGER PRIMARY KEY AUTOINCREMENT, config TEXT, valor TEXT)',
            "INSERT INTO configuracoes (config, valor) VALUES ('pix_key', 'chave-pix')",
            "INSERT INTO usuarios (nome, senha) VALUES ('Técnico', 'x')",
            "INSERT INTO clientes (idClientes, nomeCliente, senha) VALUES (7, 'Ana', 'x')",
        ] as $sql) {
            $this->db->query($sql);
        }

        for ($i = 1; $i <= 12; $i++) {
            $this->db->query("INSERT INTO os (clientes_id, usuarios_id, status) VALUES (7, 1, 'Aberto')");
            $this->db->query('INSERT INTO vendas (clientes_id, usuarios_id, valorTotal) VALUES (7, 1, 10)');
            $this->db->query("INSERT INTO cobrancas (clientes_id, expire_at) VALUES (7, '2026-10-01')");
        }
        // Venda 13 de outro cliente.
        $this->db->query('INSERT INTO vendas (clientes_id, usuarios_id, valorTotal) VALUES (8, 1, 10)');
    }

    protected function tearDown(): void
    {
        if ($this->chaveAnterior === null) {
            unset($_ENV['APP_ENCRYPTION_KEY']);
        } else {
            $_ENV['APP_ENCRYPTION_KEY'] = $this->chaveAnterior;
        }

        parent::tearDown();
    }

    private function conecte(): Conecte_model
    {
        $model = new class() extends Conecte_model {
            /** @var list<array{limite: mixed, offset: mixed}> */
            public array $chamadas = [];

            public function __construct()
            {
            }

            public function getOs($table, $fields, $where, $perpage, $start, $one, $array, $cliente)
            {
                $this->chamadas[] = ['limite' => $perpage, 'offset' => $start];

                return parent::getOs($table, $fields, $where, $perpage, $start, $one, $array, $cliente);
            }

            public function getCompras($table, $fields, $where, $perpage, $start, $one, $array, $cliente)
            {
                $this->chamadas[] = ['limite' => $perpage, 'offset' => $start];

                return parent::getCompras($table, $fields, $where, $perpage, $start, $one, $array, $cliente);
            }

            public function getCobrancas($table, $fields, $where, $perpage, $start, $one, $array, $cliente)
            {
                $this->chamadas[] = ['limite' => $perpage, 'offset' => $start];

                return parent::getCobrancas($table, $fields, $where, $perpage, $start, $one, $array, $cliente);
            }
        };
        $model->db = $this->db;

        return $model;
    }

    private function vendasModel(): object
    {
        $banco = $this->db;

        return new class($banco) {
            public array $chamadasQr = [];

            public function __construct(private $db)
            {
            }

            public function getById($id)
            {
                return $this->db->get_where('vendas', ['idVendas' => $id])->row();
            }

            public function getProdutos($id): array
            {
                return [];
            }

            public function getQrCode($id, $pixKey, $emitente): string
            {
                $this->chamadasQr[] = [$id, $pixKey];

                return 'qr-da-venda-' . $id;
            }
        };
    }

    private function osModel(): object
    {
        return new class() {
            public int $chamadasQr = 0;

            public function getQrCode($id, $pixKey, $emitente): string
            {
                $this->chamadasQr++;

                return 'qr-da-os-' . $id;
            }
        };
    }

    private function dependencias(array $query): array
    {
        $banco = $this->db;

        return [
            'db' => $banco,
            'Conecte_model' => $this->conecte(),
            'vendas_model' => $this->vendasModel(),
            'os_model' => $this->osModel(),
            'mapos_model' => new class() {
                public function getEmitente()
                {
                    return (object) ['email' => 'emitente@exemplo.test'];
                }
            },
            'format' => new class() {
                public function formatarChave($chave): string
                {
                    return 'formatada-' . $chave;
                }
            },
            'load' => new class() {
                public function __call($nome, $args)
                {
                    return null;
                }
            },
            'query' => $query,
        ];
    }

    private function os(array $query = []): ClientOsController
    {
        return new class($this->dependencias($query)) extends ClientOsController {
            public $db;

            public $Conecte_model;

            public $vendas_model;

            public $os_model;

            public $mapos_model;

            public $format;

            public $load;

            public $CI;

            private array $query;

            public function __construct(array $dependencias)
            {
                foreach ($dependencias as $nome => $valor) {
                    $this->{$nome} = $valor;
                }
                $this->CI = $this;
            }

            public function __destruct()
            {
            }

            public function logged_client($permitirTroca = false)
            {
                return (object) ['usuario' => (object) ['idClientes' => 7]];
            }

            public function get($key = null, $xss_clean = null)
            {
                return $this->query[$key] ?? null;
            }

            public function response($data = null, $http_code = null, $continue = false)
            {
                throw new RuntimeException(json_encode(['code' => $http_code, 'data' => $data]));
            }
        };
    }

    private function compras(array $query = [], int $cliente = 7): ClientComprasController
    {
        return new class($this->dependencias($query), $cliente) extends ClientComprasController {
            public $db;

            public $Conecte_model;

            public $vendas_model;

            public $os_model;

            public $mapos_model;

            public $format;

            public $load;

            public $CI;

            private array $query;

            public function __construct(array $dependencias, private int $cliente)
            {
                foreach ($dependencias as $nome => $valor) {
                    $this->{$nome} = $valor;
                }
                $this->CI = $this;
            }

            public function __destruct()
            {
            }

            public function logged_client($permitirTroca = false)
            {
                return (object) ['usuario' => (object) ['idClientes' => $this->cliente]];
            }

            public function get($key = null, $xss_clean = null)
            {
                return $this->query[$key] ?? null;
            }

            public function response($data = null, $http_code = null, $continue = false)
            {
                throw new RuntimeException(json_encode(['code' => $http_code, 'data' => $data]));
            }
        };
    }

    private function cobrancas(array $query = []): ClientCobrancasController
    {
        return new class($this->dependencias($query)) extends ClientCobrancasController {
            public $db;

            public $Conecte_model;

            public $load;

            public $CI;

            private array $query;

            public function __construct(array $dependencias)
            {
                foreach ($dependencias as $nome => $valor) {
                    $this->{$nome} = $valor;
                }
                $this->CI = $this;
            }

            public function __destruct()
            {
            }

            public function logged_client($permitirTroca = false)
            {
                return (object) ['usuario' => (object) ['idClientes' => 7]];
            }

            public function get($key = null, $xss_clean = null)
            {
                return $this->query[$key] ?? null;
            }

            public function response($data = null, $http_code = null, $continue = false)
            {
                throw new RuntimeException(json_encode(['code' => $http_code, 'data' => $data]));
            }
        };
    }

    /** @return array{code: int, data: array} */
    private function chamar(object $api, string $metodo, array $args = []): array
    {
        try {
            $api->{$metodo}(...$args);
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        $this->fail('A API deveria ter respondido.');
    }

    public function testListagemDeOsDoClienteUsaPageEPerPage(): void
    {
        $api = $this->os(['page' => '1', 'perPage' => '5']);

        $resposta = $this->chamar($api, 'os_get');

        $this->assertSame(200, $resposta['code']);
        $this->assertSame([7, 6, 5, 4, 3], array_map(fn ($os) => (int) $os['idOs'], $resposta['data']['result']['Os']));
    }

    public function testListagemDeOsSemParametrosUsaOPadrao(): void
    {
        $api = $this->os();

        $resposta = $this->chamar($api, 'os_get');

        $this->assertSame(200, $resposta['code']);
        $this->assertSame(12, count($resposta['data']['result']['Os']));
        $this->assertSame([['limite' => 20, 'offset' => 0]], $api->Conecte_model->chamadas);
    }

    public function testPerPageAcimaDoTetoViraCem(): void
    {
        $api = $this->os(['perPage' => '1000000']);

        $resposta = $this->chamar($api, 'os_get');

        $this->assertSame(200, $resposta['code']);
        $this->assertSame([['limite' => 100, 'offset' => 0]], $api->Conecte_model->chamadas);
    }

    public function testPerPageNaoInteiroResponde422(): void
    {
        $resposta = $this->chamar($this->os(['perPage' => 'abc']), 'os_get');

        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Informe um número inteiro de 1 a 100.', $resposta['data']['erros']['perPage']);
    }

    public function testListagemDeComprasUsaPageEPerPage(): void
    {
        $resposta = $this->chamar($this->compras(['page' => '1', 'perPage' => '5']), 'index_get');

        $this->assertSame(200, $resposta['code']);
        $this->assertSame([7, 6, 5, 4, 3], array_map(fn ($c) => (int) $c['idVendas'], $resposta['data']['result']['Compras']));
    }

    public function testPageNegativoNasComprasResponde422(): void
    {
        $resposta = $this->chamar($this->compras(['page' => '-1']), 'index_get');

        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Informe um número inteiro maior ou igual a 0.', $resposta['data']['erros']['page']);
    }

    public function testListagemDeCobrancasUsaPageEPerPage(): void
    {
        $resposta = $this->chamar($this->cobrancas(['page' => '2', 'perPage' => '5']), 'index_get');

        $this->assertSame(200, $resposta['code']);
        $this->assertSame([2, 1], array_map(fn ($c) => (int) $c['idCobranca'], $resposta['data']['result']));
    }

    public function testPerPageZeroNasCobrancasResponde422(): void
    {
        $resposta = $this->chamar($this->cobrancas(['perPage' => '0']), 'index_get');

        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Informe um número inteiro de 1 a 100.', $resposta['data']['erros']['perPage']);
    }

    public function testQrDaCompraVemDoValorDaVenda(): void
    {
        $api = $this->compras();

        $resposta = $this->chamar($api, 'index_get', [9]);

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('qr-da-venda-9', $resposta['data']['result']['QrCode']);
        $this->assertSame(0, $api->os_model->chamadasQr);
    }

    public function testCompraDeOutroClienteResponde404(): void
    {
        $resposta = $this->chamar($this->compras([], 8), 'index_get', [9]);

        $this->assertSame(404, $resposta['code']);
        $this->assertSame('Compra Não encontrada', $resposta['data']['message']);
    }
}
