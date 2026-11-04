<?php

require_once APPPATH . 'libraries/Refresh_token.php';
require_once APPPATH . 'models/Clientes_model.php';
require_once APPPATH . 'controllers/Clientes.php';

if (! function_exists('log_info')) {
    function log_info($task)
    {
    }
}

if (! function_exists('base_url')) {
    function base_url($uri = '')
    {
        return 'http://mapos.test/' . ltrim((string) $uri, '/');
    }
}

if (! function_exists('site_url')) {
    function site_url($uri = '')
    {
        return 'http://mapos.test/index.php/' . ltrim((string) $uri, '/');
    }
}

if (! function_exists('redirect')) {
    function redirect($uri = '')
    {
        throw new RuntimeException('redirect:' . $uri);
    }
}

/**
 * Cadastro de cliente pelo operador com senha inicial aleatória (exibida uma
 * vez, enviada por e-mail síncrono), troca de senha na edição e exclusão em
 * transação. Controller, model e helper executados de verdade sobre SQLite.
 */
final class ClientesSenhaInicialTest extends MaposTestCase
{
    private const POST = [
        'fornecedor' => '0',
        'documento' => '123.456.789-09',
        'nomeCliente' => 'Ana Souza',
        'contato' => '',
        'telefone' => '',
        'celular' => '',
        'email' => 'ana@x.com',
        'cep' => '',
        'rua' => '',
        'numero' => '',
        'complemento' => '',
        'bairro' => '',
        'cidade' => '',
        'estado' => '',
        'senha' => '',
    ];

    private bool $handlerAtivo = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, contato TEXT, documento TEXT, telefone TEXT, celular TEXT, email TEXT,
            cep TEXT, rua TEXT, numero TEXT, complemento TEXT, bairro TEXT, cidade TEXT, estado TEXT, pessoa_fisica INTEGER, fornecedor INTEGER, senha TEXT, dataCadastro TEXT,
            senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)');
        $this->db->query('CREATE TABLE email_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, to_email TEXT)');
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL DEFAULT \'usuario\', usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)');
        $this->db->query('CREATE TABLE os (idOs INTEGER PRIMARY KEY, clientes_id INTEGER)');
        $this->db->query('CREATE TABLE servicos_os (id INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)');
        $this->db->query('CREATE TABLE produtos_os (id INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)');
        $this->db->query('CREATE TABLE vendas (idVendas INTEGER PRIMARY KEY, clientes_id INTEGER)');
        $this->db->query('CREATE TABLE itens_de_vendas (id INTEGER PRIMARY KEY AUTOINCREMENT, vendas_id INTEGER)');
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

    private function model(): Clientes_model
    {
        $model = $this->makeInstance(Clientes_model::class);
        $this->setPrivateProperty($model, 'db', $this->db);

        return $model;
    }

    private function controllerDeCadastro(array $post, bool $enviou = true, ?object $model = null, string $metodo = 'post'): object
    {
        $controller = new class() extends Clientes {
            public $clientes_model;

            public $input;

            public $output;

            public $permission;

            public $session;

            public $uri;

            public ?string $redirect = null;

            /** @var list<array{0: object, 1: string}> */
            public array $envios = [];

            public bool $enviou = true;

            public function __construct()
            {
            }

            protected function validarFormulario(string $regras): array
            {
                return trim((string) $this->input->post('nomeCliente')) === '' ? ['nomeCliente' => 'obrigatório'] : [];
            }

            protected function enviarSenhaInicial(object $cliente, string $senha): bool
            {
                $this->envios[] = [$cliente, $senha];

                return $this->enviou;
            }

            public function layout()
            {
                return 'layout';
            }
        };

        $controller->enviou = $enviou;
        $controller->clientes_model = $model ?? $this->model();
        $controller->input = new class($post, $metodo) {
            public function __construct(private array $post, private string $metodo)
            {
            }

            public function method()
            {
                return $this->metodo;
            }

            public function post($chave = null)
            {
                return $chave === null ? $this->post : ($this->post[$chave] ?? null);
            }

            public function get($chave = null)
            {
                return [];
            }
        };
        $controller->output = new class() {
            public int $status = 200;

            public string $corpo = '';

            public array $headers = [];

            public function set_status_header($status)
            {
                $this->status = $status;

                return $this;
            }

            public function set_content_type($tipo, $charset = null)
            {
                return $this;
            }

            public function set_output($corpo)
            {
                $this->corpo = $corpo;

                return $this;
            }

            public function set_header($header)
            {
                [$nome, $valor] = explode(': ', $header, 2);
                $this->headers[$nome] = $valor;

                return $this;
            }
        };
        $controller->permission = new class() {
            public function checkPermission($grupo, $permissao)
            {
                return true;
            }
        };
        $controller->session = new class() {
            public array $flash = [];

            public function userdata($chave)
            {
                return ['permissao' => '1'][$chave] ?? null;
            }

            public function set_flashdata($chave, $valor)
            {
                $this->flash[$chave] = $valor;
            }
        };

        return $controller;
    }

    private function cliente(int $id, string $email = 'c@x.com'): void
    {
        $this->db->query("INSERT INTO clientes (idClientes, nomeCliente, email, senha, senha_trocar, sessao_versao, token_versao) VALUES ({$id}, 'Cli {$id}', '{$email}', 'antigo', 0, 5, 7)");
    }

    private function tokens(): array
    {
        $tokens = [];
        foreach ($this->db->order_by('id')->get('api_refresh_tokens')->result() as $linha) {
            $tokens[$linha->tipo . ':' . $linha->usuario_id] = $linha->revogado_em !== null;
        }

        return $tokens;
    }

    // --- Helper --------------------------------------------------------

    public function testNovoSemSenhaUsaASenhaGeradaENuncaODocumento(): void
    {
        $dados = clienteDadosDoFormulario(self::POST, true, 'Abc23456789Defgh');

        $this->assertTrue(password_verify('Abc23456789Defgh', $dados['senha']));
        $this->assertFalse(password_verify('12345678909', $dados['senha']));
        $this->assertArrayNotHasKey('senha_trocar', $dados);
    }

    public function testNovoSemSenhaNemGeradaFicaComSenhaDesconhecida(): void
    {
        $dados = clienteDadosDoFormulario(self::POST, true);

        $this->assertFalse(password_verify('12345678909', $dados['senha']));
        $this->assertFalse(password_verify('', $dados['senha']));
        $this->assertStringStartsWith('$2y$', $dados['senha']);
    }

    private function mailer(bool|Throwable $retorno): object
    {
        return new class($retorno) {
            public array $chamadas = [];

            public function __construct(private bool|Throwable $retorno)
            {
            }

            public function clear($anexos = false)
            {
                $this->chamadas[] = 'clear';
            }

            public function from($email, $nome)
            {
                $this->chamadas[] = 'from:' . $email . '|' . $nome;
            }

            public function to($email)
            {
                $this->chamadas[] = 'to:' . $email;
            }

            public function subject($assunto)
            {
                $this->chamadas[] = 'subject:' . $assunto;
            }

            public function message($html)
            {
                $this->chamadas[] = 'message';
            }

            public function send($semFila = false)
            {
                $this->chamadas[] = 'send:' . var_export($semFila, true);
                if ($this->retorno instanceof Throwable) {
                    throw $this->retorno;
                }

                return $this->retorno;
            }
        };
    }

    public function testEnvioSemEmailOuSemEmitenteNaoChamaSend(): void
    {
        $emitente = (object) ['email' => 'loja@x.com', 'nome' => 'Loja'];
        foreach ([[$emitente, ''], [$emitente, 'invalido'], [null, 'ana@x.com']] as [$emi, $email]) {
            $mailer = $this->mailer(true);

            $this->assertFalse(clienteEnviarSenhaInicial($mailer, $emi, (object) ['idClientes' => 1, 'email' => $email], '<html>'));
            $this->assertSame([], $mailer->chamadas);
        }
    }

    public function testEnvioOkUsaSmtpDireto(): void
    {
        $mailer = $this->mailer(true);

        $ok = clienteEnviarSenhaInicial($mailer, (object) ['email' => 'loja@x.com', 'nome' => 'Loja'], (object) ['idClientes' => 4, 'email' => 'ana@x.com'], '<html>');

        $this->assertTrue($ok);
        $this->assertSame(
            ['clear', 'from:loja@x.com|Loja', 'to:ana@x.com', 'subject:Sua senha de acesso à área do cliente', 'message', 'send:true'],
            $mailer->chamadas
        );
    }

    public function testEnvioComExcecaoLogaSemASenha(): void
    {
        $mailer = $this->mailer(new RuntimeException('smtp caiu com SenhaSecreta99'));

        $ok = clienteEnviarSenhaInicial($mailer, (object) ['email' => 'loja@x.com', 'nome' => 'Loja'], (object) ['idClientes' => 4, 'email' => 'ana@x.com'], '<html>SenhaSecreta99</html>');

        $this->assertFalse($ok);
        $this->assertSame(['email senha_inicial falha cliente=4 erro=RuntimeException'], $this->logs('error'));
        $this->assertLogNaoContem('SenhaSecreta99');
        $this->assertLogNaoContem('ana@x.com');
    }

    // --- Cadastro ------------------------------------------------------

    public function testCadastroSemSenhaMostraASenhaUmaVezEMarcaTroca(): void
    {
        $controller = $this->controllerDeCadastro(self::POST);

        $controller->adicionar();

        $gravado = $this->db->query('SELECT senha, senha_trocar FROM clientes')->row();
        $this->assertSame(1, (int) $gravado->senha_trocar);
        $this->assertFalse(password_verify('12345678909', $gravado->senha));
        $this->assertSame('clientes/senha_inicial', $controller->data['view']);
        $this->assertSame(16, strlen($controller->data['senhaInicial']));
        $this->assertMatchesRegularExpression('/^[' . CLIENTE_SENHA_ALFABETO . ']{16}$/', $controller->data['senhaInicial']);
        $this->assertTrue(password_verify($controller->data['senhaInicial'], $gravado->senha));
        $this->assertTrue($controller->data['emailEnviado']);
        $this->assertSame('Ana Souza', $controller->data['cliente']->nomeCliente);
        $this->assertSame('no-store', $controller->output->headers['Cache-Control'] ?? null);
        $this->assertSame([], $controller->session->flash);
        $this->assertSame(1, count($controller->envios));
        $this->assertSame($controller->data['senhaInicial'], $controller->envios[0][1]);
        $this->assertLogNaoContem($controller->data['senhaInicial']);
    }

    public function testFalhaNoEnvioMostraTextoDeFalha(): void
    {
        $controller = $this->controllerDeCadastro(self::POST, false);

        $controller->adicionar();

        $this->assertSame('clientes/senha_inicial', $controller->data['view']);
        $this->assertFalse($controller->data['emailEnviado']);
        $this->assertSame(1, $this->db->count_all_results('clientes'));
    }

    public function testCadastroComSenhaDigitadaMarcaTrocaERedireciona(): void
    {
        $controller = $this->controllerDeCadastro(['senha' => 'digitada123'] + self::POST);

        try {
            $controller->adicionar();
            $this->fail('Esperava redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect:clientes', $e->getMessage());
        }

        $gravado = $this->db->query('SELECT senha, senha_trocar FROM clientes')->row();
        $this->assertSame(1, (int) $gravado->senha_trocar);
        $this->assertTrue(password_verify('digitada123', $gravado->senha));
        $this->assertSame([], $controller->envios);
        $this->assertArrayNotHasKey('senhaInicial', $controller->data);
        $this->assertSame('Cliente cadastrado com sucesso!', $controller->session->flash['success']);
    }

    public function testCadastroInvalidoNaoGeraNemEnviaSenha(): void
    {
        $controller = $this->controllerDeCadastro(['nomeCliente' => ' '] + self::POST);

        $controller->adicionar();

        $this->assertSame(0, $this->db->count_all_results('clientes'));
        $this->assertSame([], $controller->envios);
        $this->assertSame('clientes/formulario', $controller->data['view']);
        $this->assertSame(['nomeCliente' => 'obrigatório'], $controller->data['erros']);
    }

    public function testCadastroRapidoGeraSenhaSemExibirNemEnviar(): void
    {
        $controller = $this->controllerDeCadastro(self::POST);

        $controller->cadastroRapido();

        $this->assertSame(201, $controller->output->status);
        $this->assertStringNotContainsString('senha', $controller->output->corpo);
        $gravado = $this->db->query('SELECT senha, senha_trocar FROM clientes')->row();
        $this->assertSame(1, (int) $gravado->senha_trocar);
        $this->assertFalse(password_verify('12345678909', $gravado->senha));
        $this->assertSame([], $controller->envios);
    }

    public function testSenhaInicialNaoPassaPelaFila(): void
    {
        $controller = $this->controllerDeCadastro(self::POST);

        $controller->adicionar();

        $this->assertSame(1, $this->db->count_all_results('clientes'));
        $this->assertSame(0, $this->db->count_all_results('email_queue'));
    }

    // --- Edição --------------------------------------------------------

    private function editar(int $id, array $post, ?object $model = null): object
    {
        $controller = $this->controllerDeEdicao($id, $post, $model);

        try {
            $controller->editar();
        } catch (RuntimeException $e) {
            $controller->redirect = $e->getMessage();
        }

        return $controller;
    }

    private function controllerDeEdicao(int $id, array $post, ?object $model): object
    {
        $controller = $this->controllerDeCadastro($post, true, $model);
        $controller->uri = new class($id) {
            public function __construct(private int $id)
            {
            }

            public function segment($n)
            {
                return $n === 3 ? (string) $this->id : null;
            }
        };

        return $controller;
    }

    public function testEdicaoComSenhaDerrubaSessoesEExigeTroca(): void
    {
        $this->cliente(5);
        $agora = date('Y-m-d H:i:s');
        $this->db->query("INSERT INTO api_refresh_tokens (tipo, usuario_id, familia, token_hash, criado_em, expira_em) VALUES ('cliente', 5, 'f1', 'h1', '{$agora}', '2999-01-01 00:00:00'), ('usuario', 5, 'f2', 'h2', '{$agora}', '2999-01-01 00:00:00')");

        $controller = $this->editar(5, ['senha' => 'outra12345'] + self::POST);

        $this->assertSame('redirect:clientes/editar/5', $controller->redirect);
        $linha = $this->db->query('SELECT senha, senha_trocar, sessao_versao, token_versao FROM clientes WHERE idClientes = 5')->row();
        $this->assertTrue(password_verify('outra12345', $linha->senha));
        $this->assertSame(1, (int) $linha->senha_trocar);
        $this->assertSame(6, (int) $linha->sessao_versao);
        $this->assertSame(8, (int) $linha->token_versao);
        $this->assertSame(['cliente:5' => true, 'usuario:5' => false], $this->tokens());
    }

    public function testEdicaoSemSenhaNaoMexeNasVersoes(): void
    {
        $this->cliente(5);

        $controller = $this->editar(5, self::POST);

        $this->assertSame('redirect:clientes/editar/5', $controller->redirect);
        $linha = $this->db->query('SELECT senha, senha_trocar, sessao_versao, token_versao FROM clientes WHERE idClientes = 5')->row();
        $this->assertSame('antigo', $linha->senha);
        $this->assertSame(0, (int) $linha->senha_trocar);
        $this->assertSame(5, (int) $linha->sessao_versao);
        $this->assertSame(7, (int) $linha->token_versao);
    }

    public function testEdicaoComSenhaEFalhaNaTrocaMostraErro(): void
    {
        $this->cliente(5);
        $real = $this->model();
        $model = new class($real) {
            public function __construct(private Clientes_model $real)
            {
            }

            public function getById($id)
            {
                return $this->real->getById($id);
            }

            public function emailExists($email, $id = null)
            {
                return false;
            }

            public function edit($tabela, $dados, $campo, $id)
            {
                return true;
            }

            public function registrarTrocaDeSenha(int $id, string $hash, bool $trocar): bool
            {
                return false;
            }
        };

        $controller = $this->editar(5, ['senha' => 'outra12345'] + self::POST, $model);

        $this->assertSame(['_geral' => 'Não foi possível salvar a senha. Tente de novo.'], $controller->data['erros']);
    }

    // --- Exclusão ------------------------------------------------------

    private function massaDeExclusao(): void
    {
        $this->cliente(3);
        $this->cliente(4, 'd@x.com');
        $this->db->query('INSERT INTO os (idOs, clientes_id) VALUES (10, 3), (11, 3), (12, 4)');
        $this->db->query('INSERT INTO servicos_os (os_id) VALUES (10), (11), (12)');
        $this->db->query('INSERT INTO produtos_os (os_id) VALUES (10), (12)');
        $this->db->query('INSERT INTO vendas (idVendas, clientes_id) VALUES (20, 3), (21, 4)');
        $this->db->query('INSERT INTO itens_de_vendas (vendas_id) VALUES (20), (20), (21)');
        $agora = date('Y-m-d H:i:s');
        $this->db->query("INSERT INTO api_refresh_tokens (tipo, usuario_id, familia, token_hash, criado_em, expira_em) VALUES ('cliente', 3, 'a', 'h1', '{$agora}', '2999-01-01 00:00:00'), ('cliente', 4, 'b', 'h2', '{$agora}', '2999-01-01 00:00:00'), ('usuario', 3, 'c', 'h3', '{$agora}', '2999-01-01 00:00:00')");
    }

    private function contagens(): array
    {
        $r = [];
        foreach (['clientes', 'os', 'servicos_os', 'produtos_os', 'vendas', 'itens_de_vendas'] as $tabela) {
            $r[$tabela] = $this->db->count_all_results($tabela);
        }

        return $r;
    }

    public function testExclusaoDoClienteApagaTudoDeleERevogaRefresh(): void
    {
        $this->massaDeExclusao();

        $this->assertTrue($this->model()->excluirComDependentes(3));

        $this->assertSame(['clientes' => 1, 'os' => 1, 'servicos_os' => 1, 'produtos_os' => 1, 'vendas' => 1, 'itens_de_vendas' => 1], $this->contagens());
        $this->assertSame(4, (int) $this->db->query('SELECT idClientes FROM clientes')->row()->idClientes);
        $this->assertSame(['cliente:3' => true, 'cliente:4' => false, 'usuario:3' => false], $this->tokens());
    }

    public function testFalhaNaExclusaoDoClienteMantemTudo(): void
    {
        $this->massaDeExclusao();
        $model = $this->model();
        $this->falhaAntesDe('DELETE', 'clientes');

        $this->assertFalse($model->excluirComDependentes(3));

        $this->assertSame(['clientes' => 2, 'os' => 3, 'servicos_os' => 3, 'produtos_os' => 2, 'vendas' => 2, 'itens_de_vendas' => 3], $this->contagens());
        $this->assertSame(['cliente:3' => false, 'cliente:4' => false, 'usuario:3' => false], $this->tokens());
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_cliente id=3');
    }

    public function testExclusaoDeClienteInexistenteDevolveFalse(): void
    {
        $this->massaDeExclusao();

        $this->assertFalse($this->model()->excluirComDependentes(99));

        $this->assertLogContem('error', 'transacao rollback operacao=excluir_cliente id=99');
    }

    public function testRemoverOsDoClienteComFalhaLogaEDevolveFalse(): void
    {
        $this->massaDeExclusao();
        $model = $this->model();
        $this->falhaAntesDe('DELETE', 'os');

        $this->assertFalse($model->removeClientOs($model->getAllOsByClient(3)));

        $this->assertLogContem('error', 'cliente remover_os falha os=');
    }

    public function testExclusaoPeloControllerComFalhaMostraErro(): void
    {
        $model = new class() {
            public function excluirComDependentes(int $id): bool
            {
                return false;
            }
        };
        $controller = $this->controllerDeCadastro(['id' => '3'], true, $model);

        try {
            $controller->excluir();
        } catch (RuntimeException $e) {
            $this->assertSame('redirect:http://mapos.test/index.php/clientes', $e->getMessage());
        }

        $this->assertSame(['error' => 'Não foi possível excluir. Nada foi alterado.'], $controller->session->flash);
    }
}
