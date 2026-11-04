<?php

require_once APPPATH . 'libraries/Refresh_token.php';
require_once APPPATH . 'models/Clientes_model.php';
require_once APPPATH . 'helpers/api_helper.php';
require_once APPPATH . 'helpers/validation_helper.php';
require_once APPPATH . 'controllers/api/v1/ClientesController.php';

/**
 * API de clientes: senha inicial gerada (devolvida uma vez), allow-list do
 * corpo, permissão 403, paginação com teto, troca de senha transacional e
 * exclusão atômica. Executa o controller real sobre SQLite; response() vira
 * exceção (na API ele encerra a requisição).
 */
final class ApiClientesTest extends MaposTestCase
{
    private object $controller;

    private bool $handlerAtivo = false;

    private mixed $chaveAnterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chaveAnterior = $_ENV['APP_ENCRYPTION_KEY'] ?? null;
        $_ENV['APP_ENCRYPTION_KEY'] ??= 'chave-de-teste';

        foreach ([
            'CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, contato TEXT, documento TEXT, telefone TEXT, celular TEXT, email TEXT,
                cep TEXT, rua TEXT, numero TEXT, complemento TEXT, bairro TEXT, cidade TEXT, estado TEXT, pessoa_fisica INTEGER, fornecedor INTEGER, senha TEXT, dataCadastro TEXT,
                asaas_id TEXT NULL, senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE email_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, to_email TEXT)',
            'CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL DEFAULT \'usuario\', usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)',
            'CREATE TABLE os (idOs INTEGER PRIMARY KEY, clientes_id INTEGER)',
            'CREATE TABLE servicos_os (id INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)',
            'CREATE TABLE produtos_os (id INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)',
            'CREATE TABLE vendas (idVendas INTEGER PRIMARY KEY, clientes_id INTEGER)',
            'CREATE TABLE itens_de_vendas (id INTEGER PRIMARY KEY AUTOINCREMENT, vendas_id INTEGER)',
        ] as $sql) {
            $this->db->query($sql);
        }

        $model = $this->makeInstance(Clientes_model::class);
        $this->setPrivateProperty($model, 'db', $this->db);

        $permission = new class() {
            public bool $pode = true;

            public function checkPermission($nivel, $acao): bool
            {
                return $this->pode;
            }
        };

        $this->controller = new class($model, $permission, $this->db) extends ClientesController {
            public $clientes_model;

            public $permission;

            public $db;

            public $load;

            public $form_validation;

            public $output;

            public array $entrada = [];

            public int $logs = 0;

            /** @var list<string> */
            public array $envios = [];

            public bool $enviou = true;

            public function __construct($model, $permission, $db)
            {
                $this->clientes_model = $model;
                $this->permission = $permission;
                $this->db = $db;
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
                $this->output = new class() {
                    public array $headers = [];

                    public function set_header($header)
                    {
                        [$nome, $valor] = explode(': ', $header, 2);
                        $this->headers[$nome] = $valor;

                        return $this;
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

            public function log_app($task, $nome_usuario = '')
            {
                $this->logs++;
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

            protected function enviarSenhaInicial(object $cliente, string $senha): bool
            {
                $this->envios[] = $senha;

                return $this->enviou;
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->handlerAtivo) {
            restore_error_handler();
            $this->handlerAtivo = false;
        }
        if ($this->chaveAnterior === null) {
            unset($_ENV['APP_ENCRYPTION_KEY']);
        }
        parent::tearDown();
    }

    /** @return array{code: int, data: array} */
    private function chamar(string $metodo, array $argumentos = [], array $entrada = []): array
    {
        $this->controller->entrada = $entrada;

        try {
            $this->controller->$metodo(...$argumentos);
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        $this->fail("{$metodo} deveria ter respondido.");
    }

    private function cliente(int $id, array $extra = []): void
    {
        $this->db->insert('clientes', ['idClientes' => $id, 'nomeCliente' => "C{$id}", 'senha' => 'antigo'] + $extra);
    }

    private function falhaAntesDe(string $evento, string $tabela): void
    {
        $this->db->db_debug = false;
        set_error_handler(static fn () => true, E_WARNING);
        $this->handlerAtivo = true;
        $this->db->query("CREATE TRIGGER falha BEFORE {$evento} ON {$tabela} BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");
    }

    private const NOVO = ['nomeCliente' => 'Ana', 'documento' => '123.456.789-09', 'email' => 'ana@x.com'];

    public function testPostSemSenhaDevolveSenhaInicialUmaVez(): void
    {
        $resposta = $this->chamar('index_post', [], self::NOVO);

        $this->assertSame(201, $resposta['code']);
        $senha = $resposta['data']['result']['senha_inicial'];
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789]{16}$/', $senha);
        $this->assertTrue($resposta['data']['result']['email_enviado']);
        $this->assertSame([$senha], $this->controller->envios);
        $this->assertArrayNotHasKey('senha', $resposta['data']['result']);
        $gravado = $this->db->get('clientes')->row();
        $this->assertSame(1, (int) $gravado->senha_trocar);
        $this->assertFalse(password_verify('12345678909', $gravado->senha));
        $this->assertTrue(password_verify($senha, $gravado->senha));
        $this->assertSame('no-store', $this->controller->output->headers['Cache-Control'] ?? null);
        $this->assertLogNaoContem($senha);
    }

    public function testPostComFalhaNoEnvioInformaEmailEnviadoFalse(): void
    {
        $this->controller->enviou = false;

        $resposta = $this->chamar('index_post', [], self::NOVO);

        $this->assertSame(201, $resposta['code']);
        $this->assertFalse($resposta['data']['result']['email_enviado']);
        $this->assertSame(16, strlen($resposta['data']['result']['senha_inicial']));
    }

    public function testPostComSenhaInformadaNaoDevolveSenha(): void
    {
        $resposta = $this->chamar('index_post', [], self::NOVO + ['senha' => 'Digitada123']);

        $this->assertSame(201, $resposta['code']);
        $this->assertArrayNotHasKey('senha_inicial', $resposta['data']['result']);
        $this->assertArrayNotHasKey('email_enviado', $resposta['data']['result']);
        $this->assertArrayNotHasKey('senha', $resposta['data']['result']);
        $gravado = $this->db->get('clientes')->row();
        $this->assertSame(1, (int) $gravado->senha_trocar);
        $this->assertTrue(password_verify('Digitada123', $gravado->senha));
        $this->assertSame([], $this->controller->envios);
    }

    public function testSenhaInicialNaoPassaPelaFila(): void
    {
        $this->assertSame(201, $this->chamar('index_post', [], self::NOVO)['code']);

        $this->assertSame(0, $this->db->count_all_results('email_queue'));
    }

    public function testPostIgnoraCamposForaDaAllowList(): void
    {
        $resposta = $this->chamar('index_post', [], self::NOVO + ['senha_trocar' => 0, 'token_versao' => 9, 'sessao_versao' => 9, 'asaas_id' => 'x']);

        $this->assertSame(201, $resposta['code']);
        $gravado = $this->db->get('clientes')->row();
        $this->assertSame([1, 0, 0, null], [(int) $gravado->senha_trocar, (int) $gravado->token_versao, (int) $gravado->sessao_versao, $gravado->asaas_id]);
    }

    public function testGetDoClienteNaoTrazSenhaInicial(): void
    {
        $criado = $this->chamar('index_post', [], self::NOVO);
        $id = $criado['data']['result']['idClientes'];

        $resposta = $this->chamar('index_get', [$id]);

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('Ana', $resposta['data']['result']['nomeCliente']);
        $this->assertArrayNotHasKey('senha_inicial', $resposta['data']['result']);
        $this->assertArrayNotHasKey('senha', $resposta['data']['result']);
    }

    public function testSemPermissaoResponde403(): void
    {
        $this->cliente(3);
        $this->controller->permission->pode = false;

        $get = $this->chamar('index_get');
        $this->assertSame(403, $get['code']);
        $this->assertSame('Você não está autorizado a Visualizar Clientes', $get['data']['message']);
        $this->assertSame(403, $this->chamar('index_delete', [3])['code']);
        $this->assertSame(1, $this->db->count_all_results('clientes'));
    }

    public function testPaginacaoDeClientes(): void
    {
        $this->db->trans_begin();
        for ($i = 1; $i <= 130; $i++) {
            $this->cliente($i);
        }
        $this->db->trans_commit();

        $this->assertCount(100, $this->chamar('index_get', [], ['perPage' => '1000000'])['data']['result']);

        $invalida = $this->chamar('index_get', [], ['perPage' => '0']);
        $this->assertSame(422, $invalida['code']);
        $this->assertSame('Parâmetros de paginação inválidos.', $invalida['data']['message']);

        $pagina = $this->chamar('index_get', [], ['page' => '1', 'perPage' => '5'])['data']['result'];
        $this->assertSame([125, 124, 123, 122, 121], array_map(static fn ($c) => (int) $c["idClientes"], $pagina));
    }

    public function testPutComSenhaDerrubaSessoesEExigeTroca(): void
    {
        $this->cliente(5, ['sessao_versao' => 2, 'token_versao' => 3]);
        $this->db->query("INSERT INTO api_refresh_tokens (tipo, usuario_id, familia, token_hash, criado_em, expira_em) VALUES ('cliente', 5, 'f', 'h', '2026-10-14', '2099-01-01'), ('usuario', 5, 'g', 'i', '2026-10-14', '2099-01-01')");

        $resposta = $this->chamar('index_put', [5], ['nomeCliente' => 'Nova', 'senha' => 'Outra12345']);

        $this->assertSame(200, $resposta['code']);
        $gravado = $this->db->get_where('clientes', ['idClientes' => 5])->row();
        $this->assertSame([1, 3, 4], [(int) $gravado->senha_trocar, (int) $gravado->sessao_versao, (int) $gravado->token_versao]);
        $this->assertTrue(password_verify('Outra12345', $gravado->senha));
        $this->assertSame('Nova', $gravado->nomeCliente);
        $revogados = [];
        foreach ($this->db->order_by('id')->get('api_refresh_tokens')->result() as $linha) {
            $revogados[$linha->tipo] = $linha->revogado_em !== null;
        }
        $this->assertSame(['cliente' => true, 'usuario' => false], $revogados);
    }

    public function testPutComFalhaNaTrocaResponde500(): void
    {
        $this->cliente(5);
        $this->falhaAntesDe('UPDATE', 'api_refresh_tokens');
        $this->db->query("INSERT INTO api_refresh_tokens (tipo, usuario_id, familia, token_hash, criado_em, expira_em) VALUES ('cliente', 5, 'f', 'h', '2026-10-14', '2099-01-01')");

        $resposta = $this->chamar('index_put', [5], ['nomeCliente' => 'Nova', 'senha' => 'Outra12345']);

        $this->assertSame(500, $resposta['code']);
        $this->assertSame('Não foi possível alterar a senha do cliente.', $resposta['data']['message']);
        $this->assertSame('antigo', $this->db->get_where('clientes', ['idClientes' => 5])->row()->senha);
    }

    public function testPutSemSenhaNaoMexeNasVersoes(): void
    {
        $this->cliente(5, ['sessao_versao' => 2, 'token_versao' => 3, 'senha_trocar' => 0]);

        $this->assertSame(200, $this->chamar('index_put', [5], ['nomeCliente' => 'Nova'])['code']);
        $this->assertSame(200, $this->chamar('index_put', [5], ['nomeCliente' => 'Nova', 'senha_trocar' => 1, 'token_versao' => 0, 'sessao_versao' => 0])['code']);

        $gravado = $this->db->get_where('clientes', ['idClientes' => 5])->row();
        $this->assertSame([0, 2, 3, 'antigo'], [(int) $gravado->senha_trocar, (int) $gravado->sessao_versao, (int) $gravado->token_versao, $gravado->senha]);
    }

    public function testClienteInexistenteResponde404(): void
    {
        foreach (['index_put', 'index_delete'] as $metodo) {
            $resposta = $this->chamar($metodo, [99], ['nomeCliente' => 'X']);
            $this->assertSame(404, $resposta['code'], $metodo);
            $this->assertSame('Cliente não encontrado.', $resposta['data']['message'], $metodo);
        }
    }

    public function testExclusaoComFalhaNaoApagaNada(): void
    {
        $this->cliente(3);
        $this->db->query('INSERT INTO os (idOs, clientes_id) VALUES (1, 3)');
        $this->db->query('INSERT INTO vendas (idVendas, clientes_id) VALUES (1, 3)');
        $this->falhaAntesDe('DELETE', 'clientes');

        $resposta = $this->chamar('index_delete', [3]);

        $this->assertSame(500, $resposta['code']);
        $this->assertSame('Não foi possível excluir. Nada foi alterado.', $resposta['data']['message']);
        $this->assertSame([1, 1, 1], [
            $this->db->count_all_results('os'),
            $this->db->count_all_results('vendas'),
            $this->db->count_all_results('clientes'),
        ]);
        $this->assertSame(0, $this->controller->logs);
    }

    public function testExclusaoDoClienteResponde200(): void
    {
        $this->cliente(3);
        $this->db->query('INSERT INTO os (idOs, clientes_id) VALUES (1, 3)');

        $resposta = $this->chamar('index_delete', [3]);

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('Cliente excluído com sucesso!', $resposta['data']['message']);
        $this->assertSame(1, $this->controller->logs);
        $this->assertSame([0, 0], [$this->db->count_all_results('clientes'), $this->db->count_all_results('os')]);
    }
}
