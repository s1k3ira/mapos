<?php

require_once APPPATH . 'models/Produtos_model.php';
require_once APPPATH . 'models/Servicos_model.php';
require_once APPPATH . 'models/Usuarios_model.php';
require_once APPPATH . 'models/Permissoes_model.php';
require_once APPPATH . 'helpers/usuarios_helper.php';
require_once APPPATH . 'helpers/api_helper.php';
require_once APPPATH . 'controllers/api/v1/ProdutosController.php';
require_once APPPATH . 'controllers/api/v1/ServicosController.php';
require_once APPPATH . 'controllers/api/v1/UsuariosController.php';

/**
 * Parte comum dos controllers de teste: response() vira exceção (na API ele
 * encerra a requisição), as entradas vêm de um array e log_app() é contado.
 */
trait ApiCadastrosControllerDeTeste
{
    public $permission;

    public $db;

    public $load;

    public $form_validation;

    public array $entrada = [];

    public int $chamadasLogApp = 0;

    public int $idLogado = 2;

    public int $nivel = 1;

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
        return (object) ['level' => $this->nivel, 'usuario' => (object) ['idUsuarios' => $this->idLogado, 'nome' => 'Teste']];
    }

    public function log_app($task, $nome_usuario = '')
    {
        ++$this->chamadasLogApp;
    }

    public function get($key = null, $xss_clean = null)
    {
        return $this->entrada[$key] ?? null;
    }

    public function post($key = null, $xss_clean = null)
    {
        return $this->entrada[$key] ?? null;
    }

    public function put($key = null, $xss_clean = null)
    {
        return $this->entrada[$key] ?? null;
    }

    private function prepararDeTeste($db, $permission): void
    {
        $this->db = $db;
        $this->permission = $permission;
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
}

/**
 * API do operador para produtos, serviços e usuários: 403 sem permissão,
 * paginação com teto, exclusão transacional e resposta do POST pelo insert_id.
 * Executa os métodos reais dos controllers sobre um SQLite; response() vira exceção.
 */
final class ApiCadastrosTest extends MaposTestCase
{
    private object $permission;

    private bool $handlerAtivo = false;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'CREATE TABLE produtos (idProdutos INTEGER PRIMARY KEY AUTOINCREMENT, codDeBarra TEXT, descricao TEXT, unidade TEXT, precoCompra REAL, precoVenda REAL, estoque REAL, estoqueMinimo REAL, saida INTEGER, entrada INTEGER)',
            'CREATE TABLE servicos (idServicos INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, descricao TEXT, preco REAL)',
            'CREATE TABLE produtos_os (idProdutos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, produtos_id INTEGER)',
            'CREATE TABLE itens_de_vendas (idItens INTEGER PRIMARY KEY AUTOINCREMENT, vendas_id INTEGER, produtos_id INTEGER)',
            'CREATE TABLE servicos_os (idServicos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, servicos_id INTEGER)',
            'CREATE TABLE usuarios (idUsuarios INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, rg TEXT, cpf TEXT, cep TEXT, rua TEXT, numero TEXT,
                bairro TEXT, cidade TEXT, estado TEXT, email TEXT, telefone TEXT, celular TEXT, dataExpiracao TEXT, situacao INTEGER, permissoes_id INTEGER,
                senha TEXT, dataCadastro TEXT, url_image_user TEXT, token_versao INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE permissoes (idPermissao INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, permissoes TEXT, situacao INTEGER, data TEXT)',
            'CREATE TABLE os (idOs INTEGER PRIMARY KEY AUTOINCREMENT, usuarios_id INTEGER)',
            'CREATE TABLE vendas (idVendas INTEGER PRIMARY KEY AUTOINCREMENT, usuarios_id INTEGER)',
            'CREATE TABLE lancamentos (idLancamentos INTEGER PRIMARY KEY AUTOINCREMENT, usuarios_id INTEGER)',
            'CREATE TABLE garantias (idGarantias INTEGER PRIMARY KEY AUTOINCREMENT, usuarios_id INTEGER)',
            "INSERT INTO permissoes (nome, permissoes, situacao) VALUES ('Administrador', '{}', 1), ('Gerente', '{\"cUsuario\":\"1\"}', 1), ('Gerente com OS', '{\"cUsuario\":\"1\",\"aOs\":\"1\"}', 1)",
            "INSERT INTO usuarios (nome, email, senha, situacao, permissoes_id) VALUES ('Admin', 'a@x.com', 'h', 1, 1), ('Gerente', 'g@x.com', 'h', 1, 2), ('Tecnico', 't@x.com', 'h', 1, 2), ('Quatro', 'q@x.com', 'h', 1, 2), ('Cinco', 'c@x.com', 'h', 1, 2)",
        ] as $sql) {
            $this->db->query($sql);
        }

        $this->permission = new class() {
            public bool $pode = true;

            public function checkPermission($nivel, $acao): bool
            {
                return $this->pode;
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->handlerAtivo) {
            restore_error_handler();
            $this->handlerAtivo = false;
        }
        parent::tearDown();
    }

    private function falhaAntesDe(string $evento, string $tabela): void
    {
        $this->db->db_debug = false;
        if (! $this->handlerAtivo) {
            set_error_handler(static fn () => true, E_WARNING);
            $this->handlerAtivo = true;
        }
        $this->db->query("CREATE TRIGGER falha BEFORE {$evento} ON {$tabela} BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");
    }

    private function model(string $classe): object
    {
        $model = $this->makeInstance($classe);
        $this->setPrivateProperty($model, 'db', $this->db);

        return $model;
    }

    private function produtos(bool $semear = true): object
    {
        if ($semear) {
            $this->db->query("INSERT INTO produtos (idProdutos, descricao, precoVenda) VALUES (7, 'Tela', 10)");
            $this->db->query('INSERT INTO produtos_os (os_id, produtos_id) VALUES (1, 7), (2, 7)');
        }

        return new class($this->model(Produtos_model::class), $this->permission, $this->db) extends ProdutosController {
            use ApiCadastrosControllerDeTeste;

            public $produtos_model;

            public function __construct($model, $permission, $db)
            {
                $this->produtos_model = $model;
                $this->prepararDeTeste($db, $permission);
            }
        };
    }

    private function servicos(bool $semear = true): object
    {
        if ($semear) {
            $this->db->query("INSERT INTO servicos (idServicos, nome, descricao, preco) VALUES (4, 'Troca', 'x', 50)");
            $this->db->query('INSERT INTO servicos_os (os_id, servicos_id) VALUES (1, 4)');
        }

        return new class($this->model(Servicos_model::class), $this->permission, $this->db) extends ServicosController {
            use ApiCadastrosControllerDeTeste;

            public $servicos_model;

            public function __construct($model, $permission, $db)
            {
                $this->servicos_model = $model;
                $this->prepararDeTeste($db, $permission);
            }
        };
    }

    private function usuarios(bool $addFalha = false, bool $deleteFalha = false): object
    {
        $usuarios = new class() extends Usuarios_model {
            public bool $addFalha = false;

            public bool $deleteFalha = false;

            /** @var list<array{0: int, 1: int}> */
            public array $chamadasGet = [];

            public function add($table, $data)
            {
                return $this->addFalha ? false : parent::add($table, $data);
            }

            public function delete($table, $fieldID, $ID)
            {
                return $this->deleteFalha ? false : parent::delete($table, $fieldID, $ID);
            }

            public function get($perpage = 0, $start = 0, $one = false)
            {
                $this->chamadasGet[] = [$perpage, $start];

                return parent::get($perpage, $start, $one);
            }
        };
        $this->setPrivateProperty($usuarios, 'db', $this->db);
        $usuarios->addFalha = $addFalha;
        $usuarios->deleteFalha = $deleteFalha;

        return new class($usuarios, $this->model(Permissoes_model::class), $this->permission, $this->db) extends UsuariosController {
            use ApiCadastrosControllerDeTeste;

            public $usuarios_model;

            public $permissoes_model;

            public function __construct($usuarios, $permissoes, $permission, $db)
            {
                $this->usuarios_model = $usuarios;
                $this->permissoes_model = $permissoes;
                $this->prepararDeTeste($db, $permission);
            }
        };
    }

    /** @return array{code: int, data: array} */
    private function chamar(object $controller, string $metodo, array $argumentos = [], array $entrada = []): array
    {
        $controller->entrada = $entrada;

        try {
            $controller->$metodo(...$argumentos);
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        $this->fail("{$metodo} deveria ter respondido.");
    }

    private function contar(string $tabela, array $where = []): int
    {
        return (int) $this->db->where($where)->count_all_results($tabela);
    }

    public function testProdutosEServicosSemPermissaoRespondem403(): void
    {
        $this->permission->pode = false;
        $produtos = $this->produtos();
        $servicos = $this->servicos();

        $resposta = $this->chamar($produtos, 'index_get');
        $this->assertSame(403, $resposta['code']);
        $this->assertSame('Você não está autorizado a Visualizar Produtos', $resposta['data']['message']);

        $resposta = $this->chamar($servicos, 'index_delete', [4]);
        $this->assertSame(403, $resposta['code']);
        $this->assertSame('Você não está autorizado a Apagar Serviços!', $resposta['data']['message']);
        $this->assertSame(1, $this->contar('servicos', ['idServicos' => 4]));
        $this->assertSame(1, $this->contar('servicos_os', ['servicos_id' => 4]));

        $this->assertSame(403, $this->chamar($produtos, 'index_post')['code']);
        $this->assertSame(403, $this->chamar($produtos, 'index_put', [7])['code']);
        $this->assertSame(403, $this->chamar($produtos, 'index_delete', [7])['code']);
        $this->assertSame(403, $this->chamar($servicos, 'index_get')['code']);
        $this->assertSame(403, $this->chamar($servicos, 'index_post')['code']);
        $this->assertSame(403, $this->chamar($servicos, 'index_put', [4])['code']);
        $this->assertSame(1, $this->contar('produtos', ['idProdutos' => 7]));
    }

    public function testUsuariosSemPermissaoResponde403(): void
    {
        $this->permission->pode = false;
        $controller = $this->usuarios();

        $resposta = $this->chamar($controller, 'index_get');
        $this->assertSame(403, $resposta['code']);
        $this->assertSame('Você não está autorizado a Visualizar Usuários', $resposta['data']['message']);

        $this->assertSame(403, $this->chamar($controller, 'index_post')['code']);
        $this->assertSame(403, $this->chamar($controller, 'index_delete', [5])['code']);
        $this->assertSame(1, $this->contar('usuarios', ['idUsuarios' => 5]));
    }

    public function testPutDeUsuarioComGrupoForaDoAlcanceResponde403(): void
    {
        // Operador 2 (grupo 2, só cUsuario) tenta levar o usuário 3 ao grupo 3 (cUsuario + aOs).
        $controller = $this->usuarios();
        $controller->nivel = 2;

        $resposta = $this->chamar($controller, 'index_put', [3], [
            'nome' => 'Tecnico', 'rg' => '1', 'cpf' => '2', 'cep' => '3', 'rua' => 'r', 'numero' => '1', 'bairro' => 'b', 'cidade' => 'c',
            'estado' => 'SP', 'email' => 't@x.com', 'telefone' => '1', 'situacao' => '1', 'permissoes_id' => '3',
        ]);

        $this->assertSame(403, $resposta['code']);
        $this->assertSame('Você só pode escolher grupos com permissões que você também tem.', $resposta['data']['message']);
        $this->assertSame(2, (int) $this->db->get_where('usuarios', ['idUsuarios' => 3])->row()->permissoes_id);
    }

    public function testUsuarioNaoExcluiASiMesmo(): void
    {
        $controller = $this->usuarios();
        $controller->idLogado = 5;

        $resposta = $this->chamar($controller, 'index_delete', [5]);

        $this->assertSame(403, $resposta['code']);
        $this->assertSame('Você não pode excluir o próprio usuário.', $resposta['data']['message']);
        $this->assertSame(1, $this->contar('usuarios', ['idUsuarios' => 5]));
    }

    public function testExclusaoDeUsuarioComFalhaResponde500(): void
    {
        $controller = $this->usuarios(false, true);

        $resposta = $this->chamar($controller, 'index_delete', [5]);

        $this->assertSame(500, $resposta['code']);
        $this->assertFalse($resposta['data']['status']);
        $this->assertSame('Não foi possível excluir. Nada foi alterado.', $resposta['data']['message']);
        $this->assertSame(0, $controller->chamadasLogApp);
        $this->assertLogContem('error', 'usuario exclusao falha id=5');
        $this->assertSame(1, $this->contar('usuarios', ['idUsuarios' => 5]));
    }

    public function testExclusaoDeUsuarioFuncionaERegistraLog(): void
    {
        $controller = $this->usuarios();

        $resposta = $this->chamar($controller, 'index_delete', [5]);

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('Usuário excluído com sucesso!', $resposta['data']['message']);
        $this->assertSame(1, $controller->chamadasLogApp);
        $this->assertSame(0, $this->contar('usuarios', ['idUsuarios' => 5]));
    }

    public function testPaginacaoDeProdutosEServicos(): void
    {
        for ($i = 0; $i < 130; ++$i) {
            $this->db->query('INSERT INTO produtos (descricao) VALUES (?)', ['P' . $i]);
            $this->db->query('INSERT INTO servicos (nome, descricao) VALUES (?, ?)', ['S' . $i, '']);
        }
        $produtos = $this->produtos(false);
        $servicos = $this->servicos(false);

        $resposta = $this->chamar($produtos, 'index_get', [], ['perPage' => '1000000']);
        $this->assertSame(200, $resposta['code']);
        $this->assertSame(100, count($resposta['data']['result']));

        $resposta = $this->chamar($servicos, 'index_get', [], ['perPage' => 'abc']);
        $this->assertSame(422, $resposta['code']);
        $this->assertSame('Parâmetros de paginação inválidos.', $resposta['data']['message']);
        $this->assertSame('Informe um número inteiro de 1 a 100.', $resposta['data']['erros']['perPage']);
    }

    public function testPaginacaoDeUsuarios(): void
    {
        $controller = $this->usuarios();

        $resposta = $this->chamar($controller, 'index_get', [], ['page' => '2', 'perPage' => '10']);

        $this->assertSame([[10, 20]], $controller->usuarios_model->chamadasGet);
        $this->assertSame(200, $resposta['code']);
    }

    public function testExclusaoDeProdutoComFalhaResponde500ENaoApagaNada(): void
    {
        $controller = $this->produtos();
        $this->falhaAntesDe('DELETE', 'produtos');

        $resposta = $this->chamar($controller, 'index_delete', [7]);

        $this->assertSame(500, $resposta['code']);
        $this->assertSame('Não foi possível excluir. Nada foi alterado.', $resposta['data']['message']);
        $this->assertSame(2, $this->db->where('produtos_id', 7)->count_all_results('produtos_os'));
        $this->assertSame(1, $this->contar('produtos', ['idProdutos' => 7]));
        $this->assertSame(0, $controller->chamadasLogApp);
    }

    public function testExclusaoDeProdutoEServicoApagaTudo(): void
    {
        $produtos = $this->produtos();
        $servicos = $this->servicos();

        $resposta = $this->chamar($produtos, 'index_delete', [7]);
        $this->assertSame(200, $resposta['code']);
        $this->assertSame('Produto excluído com sucesso!', $resposta['data']['message']);
        $this->assertSame(1, $produtos->chamadasLogApp);
        $this->assertSame(0, $this->contar('produtos'));
        $this->assertSame(0, $this->contar('produtos_os'));

        $resposta = $this->chamar($servicos, 'index_delete', [4]);
        $this->assertSame(200, $resposta['code']);
        $this->assertSame('Serviço excluído com sucesso!', $resposta['data']['message']);
        $this->assertSame(1, $servicos->chamadasLogApp);
        $this->assertSame(0, $this->contar('servicos'));
        $this->assertSame(0, $this->contar('servicos_os'));
    }

    public function testExclusaoDeInexistenteResponde404(): void
    {
        $produtos = $this->produtos();
        $servicos = $this->servicos();

        $resposta = $this->chamar($produtos, 'index_delete', [99]);
        $this->assertSame(404, $resposta['code']);
        $this->assertSame('Produto não encontrado.', $resposta['data']['message']);

        $resposta = $this->chamar($servicos, 'index_delete', [99]);
        $this->assertSame(404, $resposta['code']);
        $this->assertSame('Serviço não encontrado.', $resposta['data']['message']);
        $this->assertSame(1, $this->contar('produtos'));
        $this->assertSame(1, $this->contar('servicos'));
    }

    public function testPostDeProdutoDevolveORegistroInserido(): void
    {
        $this->db->query("INSERT INTO produtos (idProdutos, descricao) VALUES (20, 'Tela notebook')");
        $controller = $this->produtos();

        $resposta = $this->chamar($controller, 'index_post', [], [
            'codDeBarra' => '789', 'descricao' => 'Tela', 'unidade' => 'UNID', 'precoCompra' => '10.00', 'precoVenda' => '15.00',
            'estoque' => '3', 'estoqueMinimo' => '1', 'idProdutos' => '500', 'entrada' => '1',
        ]);

        $this->assertSame(201, $resposta['code']);
        $novo = $this->db->order_by('idProdutos', 'desc')->get('produtos')->row();
        $this->assertSame('Tela', $novo->descricao);
        $this->assertSame((int) $novo->idProdutos, (int) $resposta['data']['result']['idProdutos']);
        $this->assertSame('Tela', $resposta['data']['result']['descricao']);
        $this->assertNotSame(500, (int) $novo->idProdutos);
        $this->assertSame(0, $this->contar('produtos', ['idProdutos' => 500]));
    }

    public function testPostDeServicoDevolveORegistroInserido(): void
    {
        $this->db->query("INSERT INTO servicos (idServicos, nome, descricao, preco) VALUES (30, 'Outro', 'mesma', 1)");
        $controller = $this->servicos();

        $resposta = $this->chamar($controller, 'index_post', [], ['nome' => 'Novo', 'descricao' => 'mesma', 'preco' => '12.50']);

        $this->assertSame(201, $resposta['code']);
        $this->assertSame('Novo', $resposta['data']['result']['nome']);
        $this->assertSame((int) $this->db->insert_id(), (int) $resposta['data']['result']['idServicos']);
    }

    public function testPostDeUsuarioComFalhaResponde500(): void
    {
        $controller = $this->usuarios(true);

        $resposta = $this->chamar($controller, 'index_post', [], [
            'nome' => 'Novo', 'email' => 'n@x.com', 'senha' => 'segredo123', 'situacao' => '1', 'permissoes_id' => '2',
        ]);

        $this->assertSame(500, $resposta['code']);
        $this->assertFalse($resposta['data']['status']);
        $this->assertSame('Não foi possível adicionar o Usuário.', $resposta['data']['message']);
        $this->assertSame(5, $this->contar('usuarios'));
    }
}
