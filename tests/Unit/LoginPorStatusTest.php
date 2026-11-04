<?php

require_once APPPATH . 'helpers/general_helper.php';
require_once APPPATH . 'libraries/Limite_login.php';

if (! function_exists('site_url')) {
    function site_url($uri = '')
    {
        return 'http://mapos.test/index.php/' . ltrim((string) $uri, '/');
    }
}

if (! function_exists('base_url')) {
    function base_url($uri = '')
    {
        return 'http://mapos.test/' . ltrim((string) $uri, '/');
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
 * Login do painel por status HTTP (RF-29) e logout só por POST (RF-27). O Login
 * roda de verdade sobre SQLite (limite de tentativas); sessão, input, output,
 * segurança e model são dublês, e as saídas (exit, redirect) viram exceção.
 */
final class LoginPorStatusTest extends MaposTestCase
{
    private const MENSAGEM_GENERICA = 'Os dados de acesso estão incorretos.';

    private int $agora = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, escopo VARCHAR(20) NOT NULL, tipo VARCHAR(10) NOT NULL, chave CHAR(64) NOT NULL, falhas INTEGER NOT NULL DEFAULT 0, bloqueado_ate DATETIME NULL, ultima_falha DATETIME NOT NULL)');
        $this->db->query('CREATE UNIQUE INDEX login_attempts_chave ON login_attempts (escopo, tipo, chave)');
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $sessao
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $errosDeValidacao
     * @param  array<string, object>  $contas  e-mail => linha de usuarios
     */
    private function login(array $post = [], string $metodo = 'POST', array $sessao = [], array $query = [], array $errosDeValidacao = [], ?array $contas = null, string $csrf = 'OUTRO', bool $grupoAtivo = true): object
    {
        $contas ??= ['ana@x.com' => $this->usuario()];
        $agora = $this->agora;

        $mapos = new class($contas, $grupoAtivo) {
            public function __construct(private array $contas, private bool $grupoAtivo)
            {
            }

            public function check_credentials($email)
            {
                return $this->contas[$email] ?? null;
            }

            public function grupoAtivo(int $id): bool
            {
                return $this->grupoAtivo;
            }

            public function grupoExige2fa(int $id): bool
            {
                return false;
            }
        };

        $classe = new class() extends Login {
            public function __construct()
            {
            }

            public $session;

            public $input;

            public $output;

            public $security;

            public $form_validation;

            public $limite_login;

            public $Mapos_model;

            public $mapos_model;

            public $load;

            public ?string $destino = null;

            protected function irPara(string $url)
            {
                $this->destino = $url;

                throw new RuntimeException('redirect');
            }

            protected function encerrar()
            {
                throw new RuntimeException('exit');
            }
        };
        $login = $classe;

        $login->session = new class($sessao) {
            public bool $destruida = false;

            public bool $regenerada = false;

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

            public function flashdata($chave)
            {
                return null;
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
        };
        $login->input = new class($metodo, $post, $query) {
            public function __construct(private string $metodo, private array $post, private array $query)
            {
            }

            public function method()
            {
                return strtolower($this->metodo);
            }

            public function post($chave = null)
            {
                return $this->post[$chave] ?? null;
            }

            public function get($chave = null)
            {
                return $this->query[$chave] ?? null;
            }

            public function ip_address()
            {
                return '10.0.0.1';
            }
        };
        $login->output = new class() {
            public ?int $status = null;

            public ?string $tipo = null;

            public string $corpo = '';

            /** @var list<string> */
            public array $cabecalhos = [];

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
        };
        $login->security = new class($csrf) {
            public function __construct(private string $nome)
            {
            }

            public function get_csrf_token_name(): string
            {
                return $this->nome;
            }

            public function get_csrf_hash(): string
            {
                return 'hash-novo';
            }
        };
        $login->form_validation = new class($errosDeValidacao) {
            public function __construct(private array $erros)
            {
            }

            public function run()
            {
                return $this->erros === [];
            }

            public function error_array(): array
            {
                return $this->erros;
            }

            public function __call($metodo, $argumentos)
            {
                return $this;
            }
        };
        $login->limite_login = new Limite_login(['db' => $this->db, 'segredo' => 's', 'relogio' => fn () => $agora, 'auditar' => fn () => null]);
        $login->Mapos_model = $mapos;
        $login->mapos_model = $mapos;
        $login->load = new class() {
            public ?string $view = null;

            public array $dados = [];

            public function view($arquivo, array $dados = []): void
            {
                $this->view = $arquivo;
                $this->dados = $dados;
            }

            public function __call($metodo, $argumentos)
            {
                return null;
            }
        };

        return $login;
    }

    private function usuario(array $extra = []): object
    {
        return (object) ($extra + [
            'idUsuarios' => 1,
            'nome' => 'Ana',
            'email' => 'ana@x.com',
            'senha' => password_hash('Certa2026', PASSWORD_BCRYPT, ['cost' => 4]),
            'url_image_user' => null,
            'permissoes_id' => 1,
            'dataExpiracao' => null,
            'tema_modo' => null,
            'tema_cor' => null,
            'sessao_versao' => 0,
            'totp_ativado_em' => null,
        ]);
    }

    /** Roda a rota até ela terminar (redirect, exit ou fim normal). */
    private function chamar(object $login, string $metodo): void
    {
        try {
            ob_start();
            $login->{$metodo}();
        } catch (RuntimeException) {
            // redirect ou exit: a rota não continua.
        } finally {
            ob_end_clean();
        }
    }

    private function corpoDe(object $login): array
    {
        $this->assertSame('application/json', $login->output->tipo);

        return json_decode($login->output->corpo, true);
    }

    private function falhasContadas(): int
    {
        return (int) $this->db->select_sum('falhas')->get('login_attempts')->row()->falhas;
    }

    public function testEmailInvalidoResponde422ComErrosPorCampo(): void
    {
        $erro = 'O campo E-mail deve conter um endereço de e-mail válido.';
        $login = $this->login(['email' => 'x', 'senha' => 'y'], errosDeValidacao: ['email' => $erro]);

        $this->chamar($login, 'verificarLogin');

        $this->assertSame(422, $login->output->status);
        $this->assertSame(['result' => false, 'message' => 'Confira os campos destacados.', 'erros' => ['email' => $erro], 'OUTRO' => 'hash-novo'], $this->corpoDe($login));
        $this->assertSame(0, $this->falhasContadas());
    }

    public function testSenhaErradaResponde401ComMensagemGenerica(): void
    {
        $login = $this->login(['email' => 'ana@x.com', 'senha' => 'errada']);

        $this->chamar($login, 'verificarLogin');

        $this->assertSame(401, $login->output->status);
        $this->assertSame(['result' => false, 'message' => self::MENSAGEM_GENERICA, 'OUTRO' => 'hash-novo'], $this->corpoDe($login));
        $this->assertSame(2, $this->falhasContadas(), 'Uma falha para o e-mail e uma para o IP.');
    }

    public function testEmailDesconhecidoResponde401ComAMesmaMensagem(): void
    {
        $login = $this->login(['email' => 'ninguem@x.com', 'senha' => 'qualquer']);

        $this->chamar($login, 'verificarLogin');

        $this->assertSame(401, $login->output->status);
        $this->assertSame(self::MENSAGEM_GENERICA, $this->corpoDe($login)['message']);
    }

    public function testContaBloqueadaResponde403SoComASenhaCerta(): void
    {
        $expirada = ['ana@x.com' => $this->usuario(['dataExpiracao' => '2020-01-01'])];

        $certa = $this->login(['email' => 'ana@x.com', 'senha' => 'Certa2026'], contas: $expirada);
        $this->chamar($certa, 'verificarLogin');
        $this->assertSame(403, $certa->output->status);
        $this->assertSame(['result' => false, 'message' => 'A conta do usuário está expirada, por favor entre em contato com o administrador do sistema.', 'OUTRO' => 'hash-novo'], $this->corpoDe($certa));

        $errada = $this->login(['email' => 'ana@x.com', 'senha' => 'errada'], contas: $expirada);
        $this->chamar($errada, 'verificarLogin');
        $this->assertSame(401, $errada->output->status);

        $desativado = $this->login(['email' => 'ana@x.com', 'senha' => 'Certa2026'], grupoAtivo: false);
        $this->chamar($desativado, 'verificarLogin');
        $this->assertSame(403, $desativado->output->status);
        $this->assertSame('O grupo de permissão desta conta está desativado. Fale com o administrador do sistema.', $this->corpoDe($desativado)['message']);
        $this->assertArrayNotHasKey('logado', $desativado->session->dados);
    }

    public function testBloqueadoResponde429ComRetryAfter(): void
    {
        for ($i = 0; $i < Limite_login::LIMITE_EMAIL; $i++) {
            $this->chamar($this->login(['email' => 'ana@x.com', 'senha' => 'errada']), 'verificarLogin');
        }

        $login = $this->login(['email' => 'ana@x.com', 'senha' => 'Certa2026']);
        $this->chamar($login, 'verificarLogin');

        $this->assertSame(429, $login->output->status);
        $this->assertContains('Retry-After: 60', $login->output->cabecalhos);
        $this->assertSame(['result' => false, 'message' => Limite_login::MENSAGEM, 'retry_after' => 60, 'OUTRO' => 'hash-novo'], $this->corpoDe($login));
        $this->assertArrayNotHasKey('logado', $login->session->dados, 'Bloqueado, a senha nem é conferida.');
    }

    public function testSucessoResponde200EmJson(): void
    {
        $login = $this->login(['email' => 'ana@x.com', 'senha' => 'Certa2026']);

        $this->chamar($login, 'verificarLogin');

        $this->assertSame(200, $login->output->status);
        $this->assertSame(['result' => true], $this->corpoDe($login));
        $this->assertTrue($login->session->dados['logado']);
        $this->assertSame(1, $login->session->dados['id_admin']);
    }

    public function testDoisFatoresResponde200ComDestino(): void
    {
        $contas = ['ana@x.com' => $this->usuario(['totp_ativado_em' => '2026-01-01 00:00:00'])];
        $login = $this->login(['email' => 'ana@x.com', 'senha' => 'Certa2026'], contas: $contas);

        $this->chamar($login, 'verificarLogin');

        $this->assertSame(200, $login->output->status);
        $this->assertSame(['result' => true, 'destino' => 'http://mapos.test/index.php/login/doisFatores'], $this->corpoDe($login));
        $this->assertArrayNotHasKey('logado', $login->session->dados);
        $this->assertSame('ana@x.com', $login->session->dados['dois_fatores']['email']);
    }

    public function testTokenCsrfVemNoCampoDeNomeConfigurado(): void
    {
        $login = $this->login(['email' => 'ana@x.com', 'senha' => 'errada'], csrf: 'OUTRO');

        $this->chamar($login, 'verificarLogin');

        $corpo = $this->corpoDe($login);
        $this->assertSame('hash-novo', $corpo['OUTRO']);
        $this->assertArrayNotHasKey('MAPOS_TOKEN', $corpo);
    }

    public function testRespostasDoLoginNuncaTrazemRedirect(): void
    {
        $cenarios = [
            $this->login(['email' => 'x', 'senha' => 'y'], errosDeValidacao: ['email' => 'inválido']),
            $this->login(['email' => 'ana@x.com', 'senha' => 'errada']),
            $this->login(['email' => 'ana@x.com', 'senha' => 'Certa2026'], grupoAtivo: false),
            $this->login(['email' => 'ana@x.com', 'senha' => 'Certa2026']),
        ];

        foreach ($cenarios as $indice => $login) {
            $this->chamar($login, 'verificarLogin');

            $this->assertNotNull($login->output->status, "Cenário {$indice} sem resposta.");
            $this->assertArrayNotHasKey('redirect', $this->corpoDe($login), "Cenário {$indice}");
        }
    }

    public function testGetEmLogoutResponde405SemEncerrarASessao(): void
    {
        $login = $this->login([], 'GET', ['logado' => true, 'id_admin' => 1]);

        $this->chamar($login, 'sair');

        $this->assertSame(405, $login->output->status);
        $this->assertContains('Allow: POST', $login->output->cabecalhos);
        $this->assertFalse($login->session->destruida);
        $this->assertTrue($login->session->dados['logado']);
        $this->assertNull($login->destino);
    }

    public function testPostEmLogoutEncerraASessaoEVaiAoLogin(): void
    {
        $login = $this->login([], 'POST', ['logado' => true, 'id_admin' => 1]);

        $this->chamar($login, 'sair');

        $this->assertTrue($login->session->destruida);
        $this->assertSame('http://mapos.test/index.php/login?saiu=1', $login->destino);
    }

    public function testIndexMostraVoceSaiuComSaiu(): void
    {
        $com = $this->login([], 'GET', [], ['saiu' => '1']);
        $this->chamar($com, 'index');
        $this->assertSame('mapos/login', $com->load->view);
        $this->assertSame('Você saiu.', $com->load->dados['sucesso']);

        $sem = $this->login([], 'GET');
        $this->chamar($sem, 'index');
        $this->assertNull($sem->load->dados['sucesso']);
    }

    public function testIndexDescartaODoisFatoresPendente(): void
    {
        $login = $this->login([], 'GET', ['dois_fatores' => ['email' => 'ana@x.com', 'expira' => PHP_INT_MAX, 'tentativas' => 0]]);

        $this->chamar($login, 'index');

        $this->assertArrayNotHasKey('dois_fatores', $login->session->dados);
    }

    public function testLoginSemDeprecated(): void
    {
        $cenarios = [
            ['post' => ['email' => 'x', 'senha' => 'y'], 'erros' => ['email' => 'inválido'], 'status' => 422],
            ['post' => ['email' => 'ana@x.com', 'senha' => 'errada'], 'erros' => [], 'status' => 401],
            ['post' => ['email' => 'ana@x.com', 'senha' => 'Certa2026'], 'erros' => [], 'status' => 200],
        ];

        foreach ($cenarios as $cenario) {
            $login = $this->login($cenario['post'], errosDeValidacao: $cenario['erros']);

            $this->semDeprecated(fn () => $this->chamar($login, 'verificarLogin'));

            $this->assertSame($cenario['status'], $login->output->status);
        }
    }
}
