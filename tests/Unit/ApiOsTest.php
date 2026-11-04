<?php

require_once APPPATH . 'models/Os_model.php';
require_once APPPATH . 'helpers/api_helper.php';
require_once APPPATH . 'controllers/api/v1/OsController.php';

// O audit_helper (log_info) não é carregado nos testes.
if (! function_exists('log_info')) {
    function log_info($task)
    {
    }
}

/** Os_model que registra o limite e o deslocamento recebidos por getOs(). */
class OsModelRegistrador extends Os_model
{
    public array $chamadasGetOs = [];

    public function getOs($table, $fields, $where = [], $perpage = 0, $start = 0, $one = false, $array = 'array')
    {
        $this->chamadasGetOs[] = ['limite' => $perpage, 'offset' => $start, 'where' => $where];

        return parent::getOs($table, $fields, $where, $perpage, $start, $one, $array);
    }
}

/**
 * API de OS (#2879): OS fechada não muda, escrita por allow-list, datas e
 * paginação validadas, id do item vem do insert_id e a exclusão é transacional.
 * Executa os métodos reais do controller sobre um SQLite; response() vira
 * exceção (na API ele encerra a requisição).
 */
final class ApiOsTest extends MaposTestCase
{
    private object $controller;

    private OsModelRegistrador $model;

    private mixed $chaveAnterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chaveAnterior = $_ENV['APP_ENCRYPTION_KEY'] ?? null;
        $_ENV['APP_ENCRYPTION_KEY'] ??= 'chave-de-teste';

        foreach ([
            'CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, senha TEXT, celular TEXT, telefone TEXT, contato TEXT, email TEXT, documento TEXT)',
            'CREATE TABLE usuarios (idUsuarios INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, telefone TEXT, email TEXT)',
            'CREATE TABLE garantias (idGarantias INTEGER PRIMARY KEY AUTOINCREMENT, refGarantia TEXT, textoGarantia TEXT)',
            'CREATE TABLE os (idOs INTEGER PRIMARY KEY AUTOINCREMENT, clientes_id INTEGER, usuarios_id INTEGER, garantias_id INTEGER, status TEXT, faturado INTEGER DEFAULT 0,'
                . ' valorTotal REAL, lancamento INTEGER, desconto REAL DEFAULT 0, valor_desconto REAL DEFAULT 0, tipo_desconto TEXT, dataInicial TEXT, dataFinal TEXT,'
                . ' garantia TEXT, descricaoProduto TEXT, defeito TEXT, observacoes TEXT, laudoTecnico TEXT)',
            'CREATE TABLE produtos (idProdutos INTEGER PRIMARY KEY AUTOINCREMENT, descricao TEXT, precoVenda REAL, estoque REAL)',
            'CREATE TABLE servicos (idServicos INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, preco REAL)',
            'CREATE TABLE produtos_os (idProdutos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, produtos_id INTEGER, quantidade REAL, preco REAL, subTotal REAL, desconto REAL DEFAULT 0, tipo_desconto TEXT)',
            'CREATE TABLE servicos_os (idServicos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, servicos_id INTEGER, quantidade REAL, preco REAL, subTotal REAL, desconto REAL DEFAULT 0, tipo_desconto TEXT)',
            'CREATE TABLE anotacoes_os (idAnotacoes INTEGER PRIMARY KEY AUTOINCREMENT, anotacao TEXT, data_hora TEXT, os_id INTEGER)',
            'CREATE TABLE anexos (idAnexos INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, anexo TEXT, thumb TEXT, path TEXT)',
            'CREATE TABLE cobrancas (idCobranca INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, vendas_id INTEGER, status TEXT)',
            'CREATE TABLE lancamentos (idLancamentos INTEGER PRIMARY KEY AUTOINCREMENT, descricao TEXT, valor REAL, baixado INTEGER DEFAULT 0)',
            "INSERT INTO clientes (nomeCliente, senha) VALUES ('Ana', 'x')",
            "INSERT INTO usuarios (nome) VALUES ('Técnico')",
            "INSERT INTO produtos (descricao, precoVenda, estoque) VALUES ('Tela', 100, 10)",
            "INSERT INTO servicos (nome, preco) VALUES ('Troca', 50)",
            // OS 1 e 2 abertas; 3 faturada; 4 cancelada.
            'INSERT INTO os (clientes_id, usuarios_id, status, faturado, valorTotal, lancamento, dataInicial, dataFinal, descricaoProduto) VALUES'
                . " (1, 1, 'Aberto', 0, 10, NULL, '2026-10-01', '2026-10-02', 'Celular'),"
                . " (1, 1, 'Aberto', 0, 10, NULL, '2026-10-01', '2026-10-02', 'Tablet'),"
                . " (1, 1, 'Faturado', 1, 10, NULL, '2026-10-01', '2026-10-02', 'TV'),"
                . " (1, 1, 'Cancelado', 0, 10, NULL, '2026-10-01', '2026-10-02', 'Rádio')",
            'INSERT INTO produtos_os (os_id, produtos_id, quantidade, preco, subTotal) VALUES (2, 1, 1, 100, 100)',
            'INSERT INTO servicos_os (os_id, servicos_id, quantidade, preco, subTotal) VALUES (2, 1, 1, 50, 50)',
            "INSERT INTO anotacoes_os (anotacao, data_hora, os_id) VALUES ('da OS 2', '2026-10-14', 2)",
        ] as $sql) {
            $this->db->query($sql);
        }

        $this->model = $this->makeInstance(OsModelRegistrador::class);
        $this->setPrivateProperty($this->model, 'db', $this->db);
        $permission = new class() {
            /** @var list<string> permissões negadas */
            public array $negadas = [];

            public function checkPermission($nivel, $permissao): bool
            {
                return ! in_array($permissao, $this->negadas, true);
            }
        };
        $banco = $this->db;
        $produtosModel = new class($banco) {
            public function __construct(private $db)
            {
            }

            public function updateEstoque($id, $quantidade, $operacao): void
            {
                $sinal = $operacao === '+' ? '+' : '-';
                $this->db->query("UPDATE produtos SET estoque = estoque {$sinal} " . (float) $quantidade . ' WHERE idProdutos = ' . (int) $id);
            }

            public function getById($id)
            {
                return $this->db->get_where('produtos', ['idProdutos' => $id])->row();
            }
        };
        $mapos = new class() {
            public function getEmitente()
            {
                return (object) ['email' => 'emitente@exemplo.test'];
            }
        };
        $servicosModel = new class($banco) {
            public function __construct(private $db)
            {
            }

            public function getById($id)
            {
                return $this->db->get_where('servicos', ['idServicos' => $id])->row();
            }
        };
        $usuariosModel = new class($banco) {
            public function __construct(private $db)
            {
            }

            public function getById($id)
            {
                return $this->db->get_where('usuarios', ['idUsuarios' => $id])->row();
            }
        };

        $this->controller = new class($this->model, $permission, $banco, $produtosModel, $mapos, $usuariosModel, $servicosModel) extends OsController {
            public $os_model;

            public $permission;

            public $db;

            public $load;

            public $form_validation;

            public $produtos_model;

            public $mapos_model;

            public $usuarios_model;

            public $servicos_model;

            public array $entrada = [];

            public array $configs = ['control_editos' => '0', 'control_estoque' => '0'];

            /** @var callable|null roda a cada log_app() (simula outra requisição concorrente) */
            public $aoLogar = null;

            public function __construct($model, $permission, $db, $produtosModel, $mapos, $usuariosModel, $servicosModel)
            {
                $this->os_model = $model;
                $this->permission = $permission;
                $this->db = $db;
                $this->produtos_model = $produtosModel;
                $this->mapos_model = $mapos;
                $this->usuarios_model = $usuariosModel;
                $this->servicos_model = $servicosModel;
                $this->load = new class() {
                    public function __call($nome, $args)
                    {
                        return null;
                    }
                };
                $this->form_validation = new class() {
                    public function run($grupo)
                    {
                        return true;
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

            public function logged_user($reGenToken = false)
            {
                return (object) ['level' => 1, 'usuario' => (object) ['nome' => 'Teste']];
            }

            public function getConfig($config)
            {
                return $this->configs[$config] ?? null;
            }

            public function log_app($task, $nome_usuario = '')
            {
                if ($this->aoLogar !== null) {
                    ($this->aoLogar)();
                }
            }

            public function put($key = null, $xss_clean = null)
            {
                return $key === null ? $this->entrada : ($this->entrada[$key] ?? null);
            }

            public function post($key = null, $xss_clean = null)
            {
                return $key === null ? $this->entrada : ($this->entrada[$key] ?? null);
            }

            public function get($key = null, $xss_clean = null)
            {
                return $key === null ? $this->entrada : ($this->entrada[$key] ?? null);
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->chaveAnterior === null) {
            unset($_ENV['APP_ENCRYPTION_KEY']);
        }
        parent::tearDown();
    }

    /** @return array{code: int, data: array} */
    private function chamar(string $metodo, array $argumentos, array $entrada = []): array
    {
        $this->controller->entrada = $entrada;

        try {
            $this->controller->$metodo(...$argumentos);
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        $this->fail("{$metodo} deveria ter respondido.");
    }

    private function corpoValido(array $extra = []): array
    {
        return $extra + [
            'dataInicial' => '01/11/2026',
            'dataFinal' => '05/11/2026',
            'status' => 'Em Andamento',
            'clientes_id' => 1,
            'usuarios_id' => 1,
            'garantia' => '90',
            'descricaoProduto' => 'Celular',
            'defeito' => 'Tela',
            'observacoes' => 'Obs',
            'laudoTecnico' => 'Laudo',
        ];
    }

    private function os(int $id): object
    {
        return $this->db->get_where('os', ['idOs' => $id])->row();
    }

    private function contar(string $tabela, array $where = []): int
    {
        return (int) $this->db->where($where)->count_all_results($tabela);
    }

    public function testPutEmOsFechadaResponde403(): void
    {
        foreach ([3, 4] as $id) {
            $antes = $this->os($id);
            $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [$id], $this->corpoValido()));

            $this->assertSame(403, $resposta['code']);
            $this->assertSame('OS Faturada/Cancelada não pode ser alterada.', $resposta['data']['message']);
            $this->assertEquals($antes, $this->os($id));
        }
    }

    public function testPutEmOsInexistenteResponde404(): void
    {
        $resposta = $this->chamar('index_put', [99], $this->corpoValido());

        $this->assertSame(404, $resposta['code']);
        $this->assertSame('OS não encontrada.', $resposta['data']['message']);
    }

    public function testPutNaoAceitaStatusFaturado(): void
    {
        $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [1], $this->corpoValido(['status' => 'Faturado'])));

        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Dados inválidos.', $resposta['data']['message']);
        $this->assertSame('Para faturar a OS, use o faturamento no painel.', $resposta['data']['erros']['status']);
        $this->assertSame('Aberto', $this->os(1)->status);
    }

    public function testPutRecusaStatusDesconhecido(): void
    {
        $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [1], $this->corpoValido(['status' => 'Qualquer'])));

        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Status inválido.', $resposta['data']['erros']['status']);
        $this->assertSame('Aberto', $this->os(1)->status);
    }

    public function testPutIgnoraCamposForaDaAllowList(): void
    {
        $antes = $this->os(1);
        $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [1], $this->corpoValido([
            'faturado' => 1, 'valorTotal' => 999, 'lancamento' => 5, 'idOs' => 77, 'desconto' => 50, 'valor_desconto' => 40,
        ])));

        $this->assertSame(200, $resposta['code']);
        $depois = $this->os(1);
        $this->assertSame('Em Andamento', $depois->status);
        $this->assertSame('2026-11-01', $depois->dataInicial);
        $this->assertSame(1, (int) $depois->idOs);
        $this->assertSame((int) $antes->faturado, (int) $depois->faturado);
        $this->assertEquals($antes->valorTotal, $depois->valorTotal);
        $this->assertSame($antes->lancamento, $depois->lancamento);
        $this->assertEquals($antes->desconto, $depois->desconto);
        $this->assertEquals($antes->valor_desconto, $depois->valor_desconto);
        $this->assertSame(0, $this->contar('os', ['idOs' => 77]));
    }

    public function testPostSempreCriaOsNaoFaturada(): void
    {
        $resposta = $this->semDeprecated(fn () => $this->chamar('index_post', [], $this->corpoValido(['faturado' => 1, 'valorTotal' => 999, 'status' => 'Aberto'])));

        $this->assertSame(201, $resposta['code']);
        $nova = $this->db->order_by('idOs', 'DESC')->get('os', 1)->row();
        $this->assertSame(5, (int) $nova->idOs);
        $this->assertSame(0, (int) $nova->faturado);
        $this->assertNull($nova->valorTotal);
        $this->assertSame('2026-11-01', $nova->dataInicial);
        $this->assertSame('2026-11-05', $nova->dataFinal);
    }

    public function testDatasInvalidasDoCorpoRespondem422(): void
    {
        $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [1], $this->corpoValido(['dataInicial' => '31/02/2026'])));
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Informe a data no formato dd/mm/aaaa.', $resposta['data']['erros']['dataInicial']);
        $this->assertSame('2026-10-01', $this->os(1)->dataInicial);

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_post', [], $this->corpoValido(['dataInicial' => '2026-11-01'])));
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Informe a data no formato dd/mm/aaaa.', $resposta['data']['erros']['dataInicial']);
        $this->assertSame(4, $this->contar('os'));

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [1], $this->corpoValido(['dataFinal' => '2026-11-05'])));
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Informe a data no formato dd/mm/aaaa.', $resposta['data']['erros']['dataFinal']);
    }

    public function testPutSemDataFinalUsaHoje(): void
    {
        $corpo = $this->corpoValido(['dataInicial' => '01/11/2026']);
        unset($corpo['dataFinal']);

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_put', [1], $corpo));

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('2026-11-01', $this->os(1)->dataInicial);
        $this->assertSame(date('Y-m-d'), $this->os(1)->dataFinal);
    }

    public function testFiltroDeDatasValidado(): void
    {
        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['from' => '31/02/2026']));
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Datas inválidas.', $resposta['data']['message']);
        $this->assertSame('Informe a data no formato dd/mm/aaaa.', $resposta['data']['erros']['from']);

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['from' => '2026-01-01']));
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Informe a data no formato dd/mm/aaaa.', $resposta['data']['erros']['from']);

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['to' => '99/99/2026']));
        $this->assertSame('Informe a data no formato dd/mm/aaaa.', $resposta['data']['erros']['to']);

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['from' => '01/01/2025', 'to' => '05/02/2026']));
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('O período pode ter no máximo 366 dias.', $resposta['data']['erros']['to']);

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['from' => '01/10/2026', 'to' => '31/10/2026']));
        $this->assertSame(200, $resposta['code']);
        $this->assertSame(['de' => '2026-10-01', 'ate' => '2026-10-31'], $this->model->chamadasGetOs[0]['where']);
    }

    public function testPaginacaoDaListagem(): void
    {
        $this->db->query('INSERT INTO os (clientes_id, usuarios_id, status, dataInicial, dataFinal) SELECT 1, 1, \'Aberto\', \'2026-10-01\', \'2026-10-02\' FROM (WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 116) SELECT i FROM n)');
        $this->assertSame(120, $this->contar('os'));

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['perPage' => '1000000']));
        $this->assertSame(200, $resposta['code']);
        $this->assertCount(100, $resposta['data']['result']);
        $this->assertSame(100, $this->model->chamadasGetOs[0]['limite']);

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['perPage' => 'abc']));
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Parâmetros de paginação inválidos.', $resposta['data']['message']);
        $this->assertCount(1, $this->model->chamadasGetOs, 'Parâmetro inválido não chega ao model.');

        $resposta = $this->semDeprecated(fn () => $this->chamar('index_get', [''], ['page' => '2', 'perPage' => '10']));
        $this->assertSame(200, $resposta['code']);
        $this->assertSame(10, $this->model->chamadasGetOs[1]['limite']);
        $this->assertSame(20, $this->model->chamadasGetOs[1]['offset']);
    }

    public function testSemPermissaoResponde403(): void
    {
        $this->controller->permission->negadas = ['vOs', 'aOs', 'eOs', 'dOs'];

        $this->assertSame(403, $this->chamar('index_get', [''])['code']);
        $this->assertSame(403, $this->chamar('index_get', [1])['code']);
        $this->assertSame(403, $this->chamar('index_post', [], $this->corpoValido())['code']);
        $this->assertSame(403, $this->chamar('index_put', [1], $this->corpoValido())['code']);
        $this->assertSame(403, $this->chamar('index_delete', [1])['code']);
        $this->assertSame(403, $this->chamar('produtos_post', [1], ['idProduto' => 1, 'preco' => 1, 'quantidade' => 1])['code']);
        $this->assertSame(403, $this->chamar('anexos_delete', [1, 1])['code']);
        $this->assertSame(1, $this->contar('os', ['idOs' => 1]));
    }

    public function testInsercaoDeItemDevolveOIdInserido(): void
    {
        $resposta = $this->chamar('produtos_post', [1], ['idProduto' => 1, 'preco' => 10, 'quantidade' => 2]);
        $this->assertSame(201, $resposta['code']);
        $this->assertSame(2, (int) $resposta['data']['result']['idProdutos_os']);
        $this->assertSame(1, (int) $this->db->get_where('produtos_os', ['idProdutos_os' => 2])->row()->os_id);

        $resposta = $this->chamar('servicos_post', [1], ['idServico' => 1, 'preco' => 10, 'quantidade' => 1]);
        $this->assertSame(201, $resposta['code']);
        $this->assertSame(2, (int) $resposta['data']['result']['idServicos_os']);

        // Outra requisição insere uma anotação logo depois da desta (antes da resposta).
        $this->controller->aoLogar = fn () => $this->db->query("INSERT INTO anotacoes_os (anotacao, data_hora, os_id) VALUES ('concorrente', '2026-11-03', 2)");
        $resposta = $this->chamar('anotacoes_post', [1], ['anotacao' => 'minha']);
        $this->assertSame(201, $resposta['code']);
        $this->assertSame(2, (int) $resposta['data']['result']['idAnotacoes']);
        $this->assertSame(1, (int) $this->db->get_where('anotacoes_os', ['idAnotacoes' => 2])->row()->os_id);
        $this->assertSame(3, $this->contar('anotacoes_os'));
    }

    public function testExclusaoComFalhaNaoApagaNada(): void
    {
        $this->controller->configs['control_estoque'] = '1';
        $this->db->db_debug = false;
        set_error_handler(static fn () => true, E_WARNING);
        $this->db->query("CREATE TRIGGER falha BEFORE DELETE ON os BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

        try {
            $resposta = $this->chamar('index_delete', [2]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(500, $resposta['code']);
        $this->assertSame('Não foi possível excluir. Nada foi alterado.', $resposta['data']['message']);
        $this->assertSame(1, $this->contar('os', ['idOs' => 2]));
        $this->assertSame(1, $this->contar('produtos_os', ['os_id' => 2]));
        $this->assertSame(1, $this->contar('servicos_os', ['os_id' => 2]));
        $this->assertSame(1, $this->contar('anotacoes_os', ['os_id' => 2]));
        $this->assertEquals(10, $this->db->get_where('produtos', ['idProdutos' => 1])->row()->estoque, 'A devolução do estoque entra no rollback.');
    }

    public function testExclusaoDeOsAbertaApagaTudo(): void
    {
        $this->controller->configs['control_estoque'] = '1';

        $resposta = $this->chamar('index_delete', [2]);

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('OS excluída com sucesso!', $resposta['data']['message']);
        $this->assertSame(0, $this->contar('os', ['idOs' => 2]));
        $this->assertSame(0, $this->contar('produtos_os', ['os_id' => 2]));
        $this->assertSame(0, $this->contar('servicos_os', ['os_id' => 2]));
        $this->assertSame(0, $this->contar('anotacoes_os', ['os_id' => 2]));
        $this->assertSame(1, $this->contar('os', ['idOs' => 1]));
        $this->assertEquals(11, $this->db->get_where('produtos', ['idProdutos' => 1])->row()->estoque);
    }

    public function testExclusaoComCobrancaAtivaResponde400(): void
    {
        $this->db->query("INSERT INTO cobrancas (os_id, status) VALUES (2, 'pending')");

        $resposta = $this->chamar('index_delete', [2]);

        $this->assertSame(400, $resposta['code']);
        $this->assertStringContainsString('Existe uma cobrança associada', $resposta['data']['message']);
        $this->assertSame(1, $this->contar('os', ['idOs' => 2]));
        $this->assertSame(1, $this->contar('cobrancas', ['os_id' => 2]));
        $this->assertSame(1, $this->contar('produtos_os', ['os_id' => 2]));
    }

    public function testExclusaoComFaturaBaixadaResponde400(): void
    {
        $this->db->query("UPDATE os SET faturado = 1, status = 'Faturado' WHERE idOs = 2");
        $this->db->query("INSERT INTO lancamentos (descricao, valor, baixado) VALUES ('Fatura de OS - #2', 150, 1)");

        $resposta = $this->chamar('index_delete', [2]);

        $this->assertSame(400, $resposta['code']);
        $this->assertSame('A fatura desta OS já tem pagamento baixado: estorne o recebimento antes de excluir a OS.', $resposta['data']['message']);
        $this->assertSame(1, $this->contar('os', ['idOs' => 2]));
    }

    public function testOsDadosDaApiAllowList(): void
    {
        $corpo = $this->corpoValido(['faturado' => 1, 'valorTotal' => 5, 'lancamento' => 3, 'idOs' => 9, 'desconto' => 1, 'valor_desconto' => 2, 'garantias_id' => '7', 'termoGarantia' => '1']);

        $edicao = $this->semDeprecated(fn () => osDadosDaApi($corpo, false));
        $this->assertTrue($edicao['ok']);
        $this->assertSame([], $edicao['erros']);
        $this->assertSame([
            'dataInicial' => '2026-11-01',
            'clientes_id' => 1,
            'usuarios_id' => 1,
            'dataFinal' => '2026-11-05',
            'garantia' => '90',
            'garantias_id' => 7,
            'descricaoProduto' => 'Celular',
            'defeito' => 'Tela',
            'status' => 'Em Andamento',
            'observacoes' => 'Obs',
            'laudoTecnico' => 'Laudo',
        ], $edicao['dados']);

        $novo = $this->semDeprecated(fn () => osDadosDaApi($corpo, true));
        $this->assertSame(0, $novo['dados']['faturado']);
        $this->assertSame(array_merge($edicao['dados'], ['faturado' => 0]), $novo['dados']);

        // Sem o campo legado termoGarantia, ou com ele vazio, a garantia fica sem termo; nulls não geram Deprecated.
        $semTermo = $this->semDeprecated(fn () => osDadosDaApi(['dataInicial' => '01/11/2026', 'status' => null, 'garantias_id' => '7', 'termoGarantia' => '', 'defeito' => null], true));
        $this->assertNull($semTermo['dados']['garantias_id']);
        $this->assertSame('', $semTermo['dados']['defeito']);
        $this->assertSame('', $semTermo['dados']['status']);
        $this->assertSame(date('Y-m-d'), $semTermo['dados']['dataFinal']);
    }
}
