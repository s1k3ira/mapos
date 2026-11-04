<?php

require_once APPPATH . 'models/Mapos_model.php';

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
 * Painel sem sessão válida (#2879): AJAX recebe 401 JSON com o redirect para o
 * login (o fetch seguiria um 302 para o HTML do login e trataria como sucesso);
 * as demais requisições continuam redirecionadas.
 */
final class SessaoExpiradaAjaxTest extends MaposTestCase
{
    private string $sessionIdAnterior = '';

    protected function setUp(): void
    {
        parent::setUp();

        // O portão exige uma sessão PHP aberta, como no servidor.
        $this->sessionIdAnterior = session_id();
        session_id('sessao-de-teste');
    }

    protected function tearDown(): void
    {
        session_id($this->sessionIdAnterior);
        parent::tearDown();
    }

    private function controller(array $sessao, bool $ajax): ControllerDoPainelDeTeste
    {
        $c = $this->makeInstance(ControllerDoPainelDeTeste::class);
        $c->session = new class($sessao) {
            /** @var array<string, mixed> */
            public array $flash = [];

            public function __construct(public array $dados)
            {
            }

            public function userdata($chave)
            {
                return $this->dados[$chave] ?? null;
            }

            public function unset_userdata($chaves): void
            {
                foreach ((array) $chaves as $chave) {
                    unset($this->dados[$chave]);
                }
            }

            public function sess_regenerate($destruir = false): void
            {
            }

            public function set_flashdata($chave, $valor): void
            {
                $this->flash[$chave] = $valor;
            }
        };
        $c->input = new class($ajax) {
            public function __construct(private bool $ajax)
            {
            }

            public function is_ajax_request()
            {
                return $this->ajax;
            }
        };
        $c->output = new class() {
            public ?int $status = null;

            public ?string $tipo = null;

            public string $corpo = '';

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

            public function set_output($corpo)
            {
                $this->corpo = $corpo;

                return $this;
            }

            public function _display(): void
            {
            }
        };
        $c->load = new class() {
            public function model($nome): void
            {
            }
        };
        $c->mapos_model = new Mapos_model();
        $c->mapos_model->db = $this->db;

        return $c;
    }

    private function bancoComUsuario(int $situacao = 1): void
    {
        $this->db->query('CREATE TABLE usuarios (idUsuarios INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, senha TEXT, situacao INTEGER DEFAULT 1, dataExpiracao TEXT NULL, permissoes_id INTEGER, totp_ativado_em TEXT NULL, sessao_versao INTEGER NOT NULL DEFAULT 0)');
        $this->db->query('CREATE TABLE permissoes (idPermissao INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, situacao INTEGER DEFAULT 1, exigir_2fa INTEGER DEFAULT 0)');
        $this->db->query("INSERT INTO permissoes (nome, situacao, exigir_2fa) VALUES ('Livre', 1, 0)");
        $this->db->query("INSERT INTO usuarios (nome, email, senha, situacao, permissoes_id) VALUES ('Ana', 'ana@x.com', 'h', {$situacao}, 1)");
    }

    /** Roda o portão até ele terminar; devolve true se a rota seguiria. */
    private function portao(ControllerDoPainelDeTeste $c): bool
    {
        try {
            $c->exigirSessao();
            $this->invokeMethod($c, 'validarSessaoDoUsuario');
        } catch (RuntimeException $e) {
            $c->parada = $e->getMessage();

            return false;
        }

        return true;
    }

    public function testAjaxSemSessaoNoPainelResponde401(): void
    {
        $c = $this->controller([], true);

        $this->assertFalse($this->portao($c));

        $this->assertSame('exit', $c->parada);
        $this->assertSame(401, $c->output->status);
        $this->assertSame('application/json', $c->output->tipo);
        $this->assertSame(['result' => false, 'message' => 'Sessão expirada', 'redirect' => 'http://mapos.test/index.php/login'], json_decode($c->output->corpo, true));
        $this->assertSame('Sua sessão expirou. Entre novamente.', $c->session->flash['error']);
    }

    public function testSemAjaxContinuaORedirect(): void
    {
        $c = $this->controller([], false);

        $this->assertFalse($this->portao($c));

        $this->assertSame('redirect:login', $c->parada);
        $this->assertNull($c->output->status);
        $this->assertSame('', $c->output->corpo);
    }

    public function testAjaxComSessaoEncerradaResponde401ComMotivo(): void
    {
        $this->bancoComUsuario(0);
        $c = $this->controller(['logado' => true, 'id_admin' => 1, 'permissao' => 1, 'sessao_versao' => 0], true);

        $this->assertFalse($this->portao($c));

        $this->assertSame('exit', $c->parada);
        $this->assertSame(401, $c->output->status);
        $this->assertSame(['result' => false, 'message' => 'Sessão expirada', 'redirect' => 'http://mapos.test/index.php/login'], json_decode($c->output->corpo, true));
        $this->assertSame('Sua conta está desativada. Fale com o administrador do sistema.', $c->session->flash['error']);
        $this->assertArrayNotHasKey('logado', $c->session->dados);
    }

    public function testSessaoValidaSegueSemResposta(): void
    {
        $this->bancoComUsuario(1);
        $c = $this->controller(['logado' => true, 'id_admin' => 1, 'permissao' => 1, 'sessao_versao' => 0], true);
        $this->assertTrue($this->portao($c));

        $this->assertNull($c->output->status);
        $this->assertSame([], $c->session->flash);
    }
}

/**
 * MY_Controller sem o construtor, com redirect e exit trocados por exceção.
 */
final class ControllerDoPainelDeTeste extends MY_Controller
{
    public $session;

    public $input;

    public $output;

    public $load;

    public $mapos_model;

    public ?string $parada = null;

    public function exigirSessao(): void
    {
        parent::exigirSessao();
    }

    protected function irPara(string $rota)
    {
        throw new RuntimeException('redirect:' . $rota);
    }

    protected function encerrar()
    {
        throw new RuntimeException('exit');
    }
}
