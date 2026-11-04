<?php

require_once APPPATH . 'helpers/conecte_helper.php';
require_once APPPATH . 'libraries/Limite_login.php';
require_once APPPATH . 'libraries/Refresh_token.php';
require_once APPPATH . 'models/Clientes_model.php';
require_once APPPATH . 'models/Conecte_model.php';
require_once APPPATH . 'controllers/Mine.php';

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

if (! function_exists('log_info')) {
    function log_info($task)
    {
    }
}

if (! function_exists('redirect')) {
    function redirect($uri = '')
    {
        throw new RuntimeException('redirect:' . $uri);
    }
}

/**
 * Sessão, revalidação, primeiro acesso, reset atômico e 401/403 em AJAX da
 * área do cliente (#2879). O Mine roda de verdade sobre SQLite; só as saídas
 * (redirect, exit, view) e o framework (sessão, input, output) são stubs.
 */
final class AreaDoClienteSessaoTest extends MaposTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $sessionIdAnterior = '';

    private ?SessaoDeTeste $sessaoDoModelo = null;

    protected function setUp(): void
    {
        parent::setUp();

        // O clienteLogado() exige uma sessão PHP aberta, como no servidor.
        $this->sessionIdAnterior = session_id();
        session_id('sessao-de-teste');

        $this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, contato TEXT, documento TEXT, telefone TEXT, celular TEXT, email TEXT,
            cep TEXT, rua TEXT, numero TEXT, complemento TEXT, bairro TEXT, cidade TEXT, estado TEXT, pessoa_fisica INTEGER, fornecedor INTEGER, senha TEXT,
            senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)');
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL DEFAULT \'usuario\', usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)');
        $this->db->query('CREATE TABLE resets_de_senha (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, token TEXT, data_expiracao TEXT, token_utilizado INTEGER NOT NULL DEFAULT 0)');
        $this->db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, escopo VARCHAR(20) NOT NULL, tipo VARCHAR(10) NOT NULL, chave CHAR(64) NOT NULL, falhas INTEGER NOT NULL DEFAULT 0, bloqueado_ate DATETIME NULL, ultima_falha DATETIME NOT NULL)');
        $this->db->query('CREATE UNIQUE INDEX login_attempts_chave ON login_attempts (escopo, tipo, chave)');
    }

    protected function tearDown(): void
    {
        session_id($this->sessionIdAnterior);
        parent::tearDown();
    }

    private function cliente(int $id = 5, int $versao = 5, int $trocar = 0, string $senha = 'SenhaAtual2026'): void
    {
        $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->db->query('INSERT INTO clientes (idClientes, nomeCliente, email, senha, senha_trocar, sessao_versao) VALUES (?, ?, ?, ?, ?, ?)', [$id, 'Ana', 'ana@x.com', $hash, $trocar, $versao]);
    }

    /**
     * @param  array<string, mixed>  $sessao
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $query
     */
    private function mine(array $sessao = [], string $metodo = 'GET', array $post = [], bool $ajax = false, array $query = []): MineTestavel
    {
        $mine = $this->makeInstance(MineTestavel::class);
        $mine->db = $this->db;
        $mine->session = new SessaoDeTeste($sessao);
        $mine->input = new EntradaDeTeste($metodo, $post, $ajax, $query);
        $mine->output = new SaidaDeTeste();
        $mine->security = new SegurancaDeTeste();
        $mine->load = new CarregadorDeTeste($mine);
        $mine->config = new ConfigDeTeste();
        $mine->form_validation = new ValidacaoDeTeste();
        $mine->Audit_model = new AuditoriaDeTeste();
        $this->sessaoDoModelo = $mine->session;
        $mine->Conecte_model = $this->conecteModel();
        $mine->clientes_model = $this->clientesModel();
        $mine->limite_login = new Limite_login(['db' => $this->db, 'segredo' => 'segredo-de-teste', 'auditar' => static fn () => null]);
        (new ReflectionProperty(Mine::class, 'configuracoesLidas'))->setValue($mine, ['app_name' => 'Map-OS']);

        return $mine;
    }

    private function conecteModel(): Conecte_model
    {
        $model = $this->makeInstance(ConecteModelTestavel::class);
        $model->db = $this->db;
        $model->session = $this->sessaoDoModelo;

        return $model;
    }

    private function clientesModel(): Clientes_model
    {
        $model = $this->makeInstance(Clientes_model::class);
        $model->db = $this->db;

        return $model;
    }

    /** Roda a rota até ela terminar (redirect, exit ou fim normal). */
    private function chamar(MineTestavel $mine, string $metodo): void
    {
        $mine->session->userdata('x');
        $mine->input->post(null);
        $mine->output->limpar();
        try {
            ob_start();
            $mine->{$metodo}();
        } catch (ParadaDoTeste) {
            // redirect() ou exit: a rota não continua.
        } finally {
            ob_end_clean();
        }
    }

    private function loginDe(string $email, string $senha, bool $ajax = false): MineTestavel
    {
        $mine = $this->mine([], 'POST', ['email' => $email, 'senha' => $senha], $ajax);
        $this->chamar($mine, 'login');

        return $mine;
    }

    private function falhasContadas(): int
    {
        return (int) $this->db->select_sum('falhas')->get('login_attempts')->row()->falhas;
    }

    private function sessaoDoCliente(int $id = 5, int $versao = 5): array
    {
        return ['conectado' => true, 'cliente_id' => $id, 'cliente_sessao_versao' => $versao, 'nome' => 'Ana', 'email' => 'ana@x.com', 'isCliente' => true];
    }

    // --- Login por status HTTP e logout só por POST ---------------------------

    private function corpoDe(MineTestavel $mine): array
    {
        $this->assertSame('application/json', $mine->output->tipo);

        return json_decode($mine->output->corpo, true);
    }

    public function testLoginDoClienteComEmailInvalidoResponde422(): void
    {
        $mine = $this->mine([], 'POST', ['email' => 'nao-e-email', 'senha' => 'x']);
        $mine->form_validation->erros = ['email' => 'O campo E-mail deve conter um endereço de e-mail válido.'];

        $this->chamar($mine, 'login');

        $this->assertSame(422, $mine->output->status);
        $this->assertSame(
            ['result' => false, 'message' => 'Confira os campos destacados.', 'erros' => ['email' => 'O campo E-mail deve conter um endereço de e-mail válido.'], 'OUTRO' => 'hash-novo'],
            $this->corpoDe($mine)
        );
        $this->assertSame(0, $this->falhasContadas());
    }

    public function testLoginDoClienteComSenhaErradaResponde401(): void
    {
        $this->cliente();

        $mine = $this->loginDe('ana@x.com', 'errada');

        $this->assertSame(401, $mine->output->status);
        $this->assertSame(['result' => false, 'message' => 'Os dados de acesso estão incorretos.', 'OUTRO' => 'hash-novo'], $this->corpoDe($mine));
        $this->assertArrayNotHasKey('redirect', $this->corpoDe($mine));
        $this->assertSame(2, $this->falhasContadas());
    }

    public function testLoginDoClienteBloqueadoResponde429ComRetryAfter(): void
    {
        $this->cliente();
        for ($i = 0; $i < Limite_login::LIMITE_EMAIL; $i++) {
            $this->loginDe('ana@x.com', 'errada');
        }

        $mine = $this->loginDe('ana@x.com', 'SenhaAtual2026');

        $this->assertSame(429, $mine->output->status);
        $this->assertContains('Retry-After: 60', $mine->output->cabecalhos);
        $this->assertSame(['result' => false, 'message' => Limite_login::MENSAGEM, 'retry_after' => 60, 'OUTRO' => 'hash-novo'], $this->corpoDe($mine));
        $this->assertArrayNotHasKey('conectado', $mine->session->dados);
    }

    public function testLoginDoClienteComSucessoResponde200EmJson(): void
    {
        $this->cliente(5, 4);

        $mine = $this->loginDe('ana@x.com', 'SenhaAtual2026');

        $this->assertSame(200, $mine->output->status);
        $this->assertSame(['result' => true], $this->corpoDe($mine));
        $this->assertTrue($mine->session->dados['conectado']);
        $this->assertSame(5, $mine->session->dados['cliente_id']);
        $this->assertSame(4, $mine->session->dados['cliente_sessao_versao']);
    }

    public function testGetEmLogoutDoClienteResponde405SemEncerrarASessao(): void
    {
        $mine = $this->mine($this->sessaoDoCliente(), 'GET');

        $this->chamar($mine, 'sair');

        $this->assertSame(405, $mine->output->status);
        $this->assertContains('Allow: POST', $mine->output->cabecalhos);
        $this->assertFalse($mine->session->destruida);
        $this->assertTrue($mine->session->dados['conectado']);
        $this->assertNull($mine->destino);
    }

    public function testPostEmLogoutDoClienteEncerraASessao(): void
    {
        $mine = $this->mine($this->sessaoDoCliente(), 'POST');

        $this->chamar($mine, 'sair');

        $this->assertTrue($mine->session->destruida);
        $this->assertSame('mine?saiu=1', $mine->destino);
        $this->assertSame([], $mine->session->dados);
    }

    public function testIndexDoClienteMostraVoceSaiu(): void
    {
        $com = $this->mine([], 'GET', [], false, ['saiu' => '1']);
        $this->chamar($com, 'index');
        $this->assertSame('Você saiu.', $com->load->dados['sucesso']);

        $sem = $this->mine();
        $this->chamar($sem, 'index');
        $this->assertNull($sem->load->dados['sucesso']);

        $flash = $this->mine([], 'GET', [], false, ['saiu' => '1']);
        $flash->session->set_flashdata('success', 'Senha redefinida.');
        $this->chamar($flash, 'index');
        $this->assertSame('Senha redefinida.', $flash->load->dados['sucesso']);
    }

    // --- Versão da sessão -----------------------------------------------------

    public function testLoginGuardaAVersaoDaSessao(): void
    {
        $this->cliente(5, 4);

        $mine = $this->loginDe('ana@x.com', 'SenhaAtual2026');

        $this->assertSame(4, $mine->session->dados['cliente_sessao_versao']);
        $this->assertSame(5, $mine->session->dados['cliente_id']);
        $this->assertTrue($mine->session->dados['conectado']);
    }

    public function testSessaoComVersaoValidaMostraATela(): void
    {
        $this->cliente(5, 4);
        $mine = $this->mine($this->sessaoDoCliente(5, 4));

        $this->chamar($mine, 'conta');

        $this->assertNull($mine->destino);
        $this->assertSame('conecte/template', $mine->load->view);
        $this->assertSame('conecte/conta', $mine->load->dados['view']);
    }

    public function testSessaoComVersaoAntigaEEncerrada(): void
    {
        $this->cliente(5, 5);
        $mine = $this->mine($this->sessaoDoCliente(5, 4));

        $this->chamar($mine, 'painel');

        $this->assertSame('mine', $mine->destino);
        $this->assertSame('A senha desta conta foi alterada. Entre novamente.', $mine->session->flash['error']);
        $this->assertNull($mine->session->dados['cliente_id'] ?? null);
        $this->assertTrue($mine->session->regenerada);
    }

    public function testClienteExcluidoEncerraASessao(): void
    {
        $mine = $this->mine($this->sessaoDoCliente(9, 0));

        $this->chamar($mine, 'painel');

        $this->assertSame('mine', $mine->destino);
        $this->assertSame('Sua sessão expirou. Entre novamente.', $mine->session->flash['error']);
        $this->assertArrayNotHasKey('conectado', $mine->session->dados);
        $this->assertArrayNotHasKey('cliente_sessao_versao', $mine->session->dados);
    }

    public function testFalhaNaRevalidacaoEncerraASessao(): void
    {
        $this->cliente(5, 5);
        $this->db->db_debug = false;
        $mine = $this->mine($this->sessaoDoCliente(5, 5));
        $this->db->query('DROP TABLE clientes');

        // O SQLite avisa da tabela ausente; o get() devolve false (db_debug desligado).
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $this->chamar($mine, 'painel');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('mine', $mine->destino);
        $this->assertSame('Sua sessão expirou. Entre novamente.', $mine->session->flash['error']);
        $this->assertLogContem('error', 'cliente sessao falha id=5');
    }

    // --- Primeiro acesso --------------------------------------------------------

    public function testTrocaPendenteLevaParaDefinirSenha(): void
    {
        $this->cliente(5, 5, 1);

        foreach (['painel', 'os', 'conta'] as $rota) {
            $mine = $this->mine($this->sessaoDoCliente(5, 5));
            $this->chamar($mine, $rota);

            $this->assertSame('mine/definirSenha', $mine->destino, $rota);
            $this->assertNull($mine->load->view, $rota);
        }
    }

    public function testDefinirSenhaSoComTrocaPendente(): void
    {
        $this->cliente(5, 5, 1);
        $mine = $this->mine($this->sessaoDoCliente(5, 5));
        $this->chamar($mine, 'definirSenha');

        $this->assertNull($mine->destino);
        $this->assertSame('conecte/definir_senha', $mine->load->view);
        $this->assertSame(['erros' => []], $mine->load->dados);

        $this->db->query('UPDATE clientes SET senha_trocar = 0 WHERE idClientes = 5');
        $mine = $this->mine($this->sessaoDoCliente(5, 5));
        $this->chamar($mine, 'definirSenha');

        $this->assertSame('mine/painel', $mine->destino);
        $this->assertNull($mine->load->view);
    }

    public function testDefinirSenhaValidaLiberaAArea(): void
    {
        $this->cliente(5, 5, 1);
        $mine = $this->mine($this->sessaoDoCliente(5, 5), 'POST', ['senha_atual' => 'SenhaAtual2026', 'senha' => 'NovaSenha2026', 'senha_confirmacao' => 'NovaSenha2026']);

        $this->chamar($mine, 'definirSenha');

        $linha = $this->db->get('clientes')->row();
        $this->assertSame('mine/painel', $mine->destino);
        $this->assertSame('Senha definida.', $mine->session->flash['success']);
        $this->assertSame(0, (int) $linha->senha_trocar);
        $this->assertSame(6, (int) $linha->sessao_versao);
        $this->assertTrue(password_verify('NovaSenha2026', $linha->senha));
        $this->assertSame(6, $mine->session->dados['cliente_sessao_versao']);

        // A sessão segue válida: a próxima tela abre.
        $proxima = $this->mine($mine->session->dados);
        $this->chamar($proxima, 'conta');
        $this->assertNull($proxima->destino);
        $this->assertSame('conecte/conta', $proxima->load->dados['view']);
    }

    public function testDefinirSenhaComNovaInvalidaNaoConfereAAtual(): void
    {
        $this->cliente(5, 5, 1);

        $curta = $this->mine($this->sessaoDoCliente(5, 5), 'POST', ['senha_atual' => 'errada', 'senha' => 'curta', 'senha_confirmacao' => 'curta']);
        $this->chamar($curta, 'definirSenha');
        $this->assertSame(['senha' => 'A senha deve ter de 8 a 72 caracteres.'], $curta->load->dados['erros']);

        $diferente = $this->mine($this->sessaoDoCliente(5, 5), 'POST', ['senha_atual' => 'errada', 'senha' => 'NovaSenha2026', 'senha_confirmacao' => 'OutraSenha2026']);
        $this->chamar($diferente, 'definirSenha');
        $this->assertSame(['senha_confirmacao' => 'A confirmação não é igual à senha.'], $diferente->load->dados['erros']);

        $this->assertSame(0, $this->falhasContadas());
        $this->assertSame(1, (int) $this->db->get('clientes')->row()->senha_trocar);
    }

    public function testDefinirSenhaConfereAAtualComLimite(): void
    {
        $this->cliente(5, 5, 1);
        $post = ['senha_atual' => 'errada', 'senha' => 'NovaSenha2026', 'senha_confirmacao' => 'NovaSenha2026'];

        $mine = $this->mine($this->sessaoDoCliente(5, 5), 'POST', $post);
        $this->chamar($mine, 'definirSenha');
        $this->assertSame(['senha_atual' => 'A senha atual não confere.'], $mine->load->dados['erros']);
        $this->assertSame(2, $this->falhasContadas(), 'Uma falha no e-mail e outra no IP.');

        $igual = $this->mine($this->sessaoDoCliente(5, 5), 'POST', ['senha_atual' => 'SenhaAtual2026', 'senha' => 'SenhaAtual2026', 'senha_confirmacao' => 'SenhaAtual2026']);
        $this->chamar($igual, 'definirSenha');
        $this->assertSame(['senha' => 'A nova senha precisa ser diferente da atual.'], $igual->load->dados['erros']);

        // A senha certa zerou o contador; o limite conta de novo a partir daqui.
        for ($i = 0; $i < Limite_login::LIMITE_EMAIL; $i++) {
            $this->chamar($this->mine($this->sessaoDoCliente(5, 5), 'POST', $post), 'definirSenha');
        }
        $bloqueado = $this->mine($this->sessaoDoCliente(5, 5), 'POST', $post);
        $this->chamar($bloqueado, 'definirSenha');
        $this->assertSame(['_geral' => Limite_login::MENSAGEM], $bloqueado->load->dados['erros']);
        $this->assertSame(1, (int) $this->db->get('clientes')->row()->senha_trocar);
    }

    public function testDefinirSenhaComFalhaNoBancoMostraErroGeral(): void
    {
        $this->cliente(5, 5, 1);
        $mine = $this->mine($this->sessaoDoCliente(5, 5), 'POST', ['senha_atual' => 'SenhaAtual2026', 'senha' => 'NovaSenha2026', 'senha_confirmacao' => 'NovaSenha2026']);
        $mine->clientes_model = new class() extends Clientes_model {
            public function __construct()
            {
            }

            public function registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool
            {
                return false;
            }
        };

        $this->chamar($mine, 'definirSenha');

        $this->assertSame(['_geral' => 'Não foi possível salvar. Tente novamente.'], $mine->load->dados['erros']);
        $this->assertNull($mine->destino);
        $this->assertSame(1, (int) $this->db->get('clientes')->row()->senha_trocar);
    }

    // --- Troca de senha por editarDados ---------------------------------------------

    private function postDeEdicao(array $extra = []): array
    {
        return $extra + ['nomeCliente' => 'Ana Souza', 'email' => 'ana@x.com', 'documento' => '', 'senha' => '', 'senha_confirmacao' => '', 'senha_atual' => ''];
    }

    public function testEditarDadosComSenhaDerrubaOutrasSessoes(): void
    {
        $this->cliente(5, 5);
        $this->db->query("INSERT INTO api_refresh_tokens (tipo, usuario_id, familia, token_hash, criado_em, expira_em) VALUES ('cliente', 5, 'f', 'h', '2026-01-01 00:00:00', '2999-01-01 00:00:00')");

        $post = $this->postDeEdicao(['senha' => 'NovaSenha2026', 'senha_confirmacao' => 'NovaSenha2026', 'senha_atual' => 'SenhaAtual2026']);
        $mine = $this->mine($this->sessaoDoCliente(5, 5), 'POST', $post);
        $this->chamar($mine, 'editarDados');

        $linha = $this->db->get('clientes')->row();
        $this->assertSame('mine/conta', $mine->destino);
        $this->assertSame(6, (int) $linha->sessao_versao);
        $this->assertSame(1, (int) $linha->token_versao);
        $this->assertTrue(password_verify('NovaSenha2026', $linha->senha));
        $this->assertNotNull($this->db->get('api_refresh_tokens')->row()->revogado_em);
        $this->assertSame(6, $mine->session->dados['cliente_sessao_versao']);
        $this->assertSame('Ana Souza', $linha->nomeCliente);

        // Sem senha, as versões não mudam.
        $mine = $this->mine($this->sessaoDoCliente(5, 6), 'POST', $this->postDeEdicao(['nomeCliente' => 'Ana Maria']));
        $this->chamar($mine, 'editarDados');

        $linha = $this->db->get('clientes')->row();
        $this->assertSame('mine/conta', $mine->destino);
        $this->assertSame('Ana Maria', $linha->nomeCliente);
        $this->assertSame(6, (int) $linha->sessao_versao);
        $this->assertSame(1, (int) $linha->token_versao);
    }

    // --- Reset de senha atômico ----------------------------------------------------

    private function token(int $utilizado = 0): void
    {
        $this->db->query('INSERT INTO resets_de_senha (email, token, data_expiracao, token_utilizado) VALUES (?, ?, ?, ?)', ['ana@x.com', hash('sha256', self::TOKEN), date('Y-m-d H:i:s', time() + 3600), $utilizado]);
    }

    public function testTokenDeResetSoValeUmaVez(): void
    {
        $this->cliente(5, 5);
        $this->token();

        $post = ['token' => self::TOKEN, 'senha' => 'PrimeiraSenha2026', 'senha_confirmacao' => 'PrimeiraSenha2026'];
        $primeira = $this->mine([], 'POST', $post);
        $this->chamar($primeira, 'senhaSalvar');
        $this->assertSame('mine?e=ana%40x.com', $primeira->destino);
        $this->assertSame('Senha alterada. Entre com a senha nova.', $primeira->session->flash['success']);

        $segunda = $this->mine([], 'POST', ['senha' => 'SegundaSenha2026', 'senha_confirmacao' => 'SegundaSenha2026'] + $post);
        $this->chamar($segunda, 'senhaSalvar');

        $linha = $this->db->get('clientes')->row();
        $this->assertSame('mine', $segunda->destino);
        $this->assertSame('Token inválido ou expirado. Solicite uma nova recuperação de senha.', $segunda->session->flash['error']);
        $this->assertTrue(password_verify('PrimeiraSenha2026', $linha->senha));
        $this->assertSame(6, (int) $linha->sessao_versao, 'Só a primeira troca derrubou as sessões.');
    }

    public function testTokenConsumidoPorOutraRequisicaoNaoTrocaASenha(): void
    {
        $this->cliente(5, 5);
        $this->token();
        $hashAntes = $this->db->get('clientes')->row()->senha;

        // A outra requisição consumiu o token entre a conferência e o UPDATE.
        $mine = $this->mine([], 'POST', ['token' => self::TOKEN, 'senha' => 'SegundaSenha2026', 'senha_confirmacao' => 'SegundaSenha2026']);
        $mine->Conecte_model = new class() extends ConecteModelTestavel {
            public function consumirTokenDeReset(int $idToken): bool
            {
                return parent::consumirTokenDeReset($idToken) && parent::consumirTokenDeReset($idToken);
            }
        };
        $mine->Conecte_model->db = $this->db;
        $mine->Conecte_model->session = $mine->session;
        $this->chamar($mine, 'senhaSalvar');

        $this->assertSame('mine', $mine->destino);
        $this->assertSame('Token inválido ou expirado. Solicite uma nova recuperação de senha.', $mine->session->flash['error']);
        $this->assertSame($hashAntes, $this->db->get('clientes')->row()->senha);
    }

    public function testConsumirTokenDeResetDevolveTrueSoNaPrimeiraVez(): void
    {
        $this->token();
        $model = $this->conecteModel();

        $this->assertTrue($model->consumirTokenDeReset(1));
        $this->assertFalse($model->consumirTokenDeReset(1));
        $this->assertFalse($model->consumirTokenDeReset(99));
    }

    public function testTokenConsumidoComFalhaNaTrocaPedeNovaRecuperacao(): void
    {
        $this->cliente(5, 5);
        $this->token();
        $mine = $this->mine([], 'POST', ['token' => self::TOKEN, 'senha' => 'PrimeiraSenha2026', 'senha_confirmacao' => 'PrimeiraSenha2026']);
        $mine->clientes_model = new class() extends Clientes_model {
            public function __construct()
            {
            }

            public function registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool
            {
                return false;
            }
        };

        $this->chamar($mine, 'senhaSalvar');

        $this->assertSame('mine', $mine->destino);
        $this->assertSame('Não foi possível salvar a senha. Solicite uma nova recuperação de senha.', $mine->session->flash['error']);
    }

    // --- AJAX ----------------------------------------------------------------------------

    public function testAjaxSemSessaoNaAreaDoClienteResponde401(): void
    {
        $mine = $this->mine([], 'GET', [], true);

        $this->chamar($mine, 'painel');

        $this->assertSame(401, $mine->output->status);
        $this->assertSame('application/json', $mine->output->tipo);
        $this->assertSame(['result' => false, 'message' => 'Sessão expirada', 'redirect' => 'http://mapos.test/index.php/mine'], json_decode($mine->output->corpo, true));
        $this->assertSame('Sua sessão expirou. Entre novamente.', $mine->session->flash['error']);
        $this->assertNull($mine->destino);
        $this->assertNull($mine->load->view, 'Nada da rota executa.');
    }

    public function testAjaxComTrocaPendenteResponde403(): void
    {
        $this->cliente(5, 5, 1);
        $mine = $this->mine($this->sessaoDoCliente(5, 5), 'GET', [], true);

        $this->chamar($mine, 'painel');

        $this->assertSame(403, $mine->output->status);
        $this->assertSame(['result' => false, 'message' => 'Defina uma nova senha antes de continuar.', 'redirect' => 'http://mapos.test/index.php/mine/definirSenha'], json_decode($mine->output->corpo, true));
        $this->assertNull($mine->destino);
        $this->assertNull($mine->load->view);
    }

    // --- Código ------------------------------------------------------------------------------

    public function testMineNaoGravaMaisASenhaPorEditNemMarcaOTokenDepois(): void
    {
        $codigo = (string) file_get_contents(APPPATH . 'controllers/Mine.php');

        $this->assertStringNotContainsString("edit('clientes', ['senha'", $codigo);
        $this->assertStringNotContainsString('resetSenhas_model->edit', $codigo);
        $this->assertStringContainsString('registrarTrocaDeSenha', $codigo);
    }
}

class ConecteModelTestavel extends Conecte_model
{
    public $session;
}

final class ParadaDoTeste extends RuntimeException
{
}

final class SessaoDeTeste
{
    /** @var array<string, mixed> */
    public array $flash = [];

    public bool $regenerada = false;

    public bool $destruida = false;

    /** @param array<string, mixed> $dados */
    public function __construct(public array $dados = [])
    {
    }

    public function userdata($chave)
    {
        return $this->dados[$chave] ?? null;
    }

    public function set_userdata($chave, $valor = null): void
    {
        foreach (is_array($chave) ? $chave : [$chave => $valor] as $nome => $v) {
            $this->dados[$nome] = $v;
        }
    }

    public function unset_userdata($chave): void
    {
        foreach ((array) $chave as $nome) {
            unset($this->dados[$nome]);
        }
    }

    public function set_flashdata($chave, $valor = null): void
    {
        $this->flash[$chave] = $valor;
    }

    public function flashdata($chave)
    {
        return $this->flash[$chave] ?? null;
    }

    public function sess_regenerate($destruir = false): void
    {
        $this->regenerada = true;
    }

    public function sess_destroy(): void
    {
        $this->destruida = true;
        $this->dados = [];
    }
}

final class EntradaDeTeste
{
    /** @param array<string, mixed> $post */
    /**
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $query
     */
    public function __construct(private string $metodo, private array $post, private bool $ajax, private array $query = [])
    {
    }

    public function method()
    {
        return strtolower($this->metodo);
    }

    public function post($chave = null)
    {
        return $chave === null ? $this->post : ($this->post[$chave] ?? null);
    }

    public function get($chave = null)
    {
        return $this->query[$chave] ?? null;
    }

    public function ip_address()
    {
        return '10.0.0.1';
    }

    public function is_ajax_request()
    {
        return $this->ajax;
    }
}

final class SaidaDeTeste
{
    public ?int $status = null;

    public ?string $tipo = null;

    public string $corpo = '';

    /** @var list<string> */
    public array $cabecalhos = [];

    public function limpar(): void
    {
        $this->cabecalhos = [];
        $this->status = null;
        $this->tipo = null;
        $this->corpo = '';
    }

    public function set_status_header($status)
    {
        $this->status = $status;

        return $this;
    }

    public function set_content_type($tipo, $charset = null)
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function set_header($cabecalho)
    {
        $this->cabecalhos[] = $cabecalho;

        return $this;
    }

    public function set_output($corpo)
    {
        $this->corpo = $corpo;

        return $this;
    }

    public function _display(): void
    {
    }
}

final class CarregadorDeTeste
{
    public ?string $view = null;

    /** @var array<string, mixed>|null */
    public ?array $dados = null;

    public function __construct(private object $controller)
    {
    }

    public function view($arquivo, array $dados = []): void
    {
        $this->view = $arquivo;
        $this->dados = $dados;
    }

    public function __call($metodo, $argumentos)
    {
        return null;
    }
}

final class ConfigDeTeste
{
    public function item($chave)
    {
        return 'pt-br';
    }

    public function set_item($chave, $valor): void
    {
    }
}

final class ValidacaoDeTeste
{
    public function __call($metodo, $argumentos)
    {
        return $this;
    }

    /** @var array<string, string> */
    public array $erros = [];

    public function run()
    {
        return $this->erros === [];
    }

    public function error_array(): array
    {
        return $this->erros;
    }
}

final class SegurancaDeTeste
{
    public string $nome = 'OUTRO';

    public function get_csrf_token_name(): string
    {
        return $this->nome;
    }

    public function get_csrf_hash(): string
    {
        return 'hash-novo';
    }
}

final class AuditoriaDeTeste
{
    public function add($dados): bool
    {
        return true;
    }
}

/**
 * Mine sem o construtor, com as saídas trocadas por exceção e as dependências
 * do framework expostas para o teste injetar.
 */
final class MineTestavel extends Mine
{
    public $db;

    public $session;

    public $input;

    public $output;

    public $load;

    public $config;

    public $form_validation;

    public $Audit_model;

    public $Conecte_model;

    public $clientes_model;

    public $limite_login;

    public $security;

    public ?string $destino = null;

    protected function irPara(string $rota)
    {
        $this->destino = $rota;

        throw new ParadaDoTeste('redirect');
    }

    protected function encerrar()
    {
        throw new ParadaDoTeste('exit');
    }
}
