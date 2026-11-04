<?php

require_once APPPATH . 'libraries/Limite_login.php';
require_once APPPATH . 'libraries/Totp.php';
require_once APPPATH . 'models/Mapos_model.php';
require_once APPPATH . 'controllers/Mapos.php';

// O audit_helper (log_info) não é carregado nos testes.
if (! function_exists('log_info')) {
    function log_info($task)
    {
    }
}

/**
 * Confirmação de senha com limite de tentativas na ativação do 2FA e retornos
 * conferíveis de desativarTotp() e saveConfiguracao() (#2879).
 */
final class DoisFatoresConfirmacaoTest extends MaposTestCase
{
    private bool $handlerAtivo = false;

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

    private function criarTabelasDeUsuario(): void
    {
        $this->db->query('CREATE TABLE usuarios (idUsuarios INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, senha TEXT, situacao INTEGER DEFAULT 1, permissoes_id INTEGER, totp_secreto TEXT NULL, totp_ativado_em TEXT NULL, totp_ultimo_passo INTEGER NULL)');
        $this->db->query('CREATE TABLE usuarios_codigos_recuperacao (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER, codigo_hash TEXT UNIQUE, criado_em TEXT, usado_em TEXT NULL)');
        $this->db->query("INSERT INTO usuarios (nome, email, senha, permissoes_id, totp_secreto, totp_ativado_em) VALUES ('Ana', 'ana@x.com', 'h', 1, 'segredo', '2026-01-01 00:00:00')");
        $this->db->query("INSERT INTO usuarios_codigos_recuperacao (usuario_id, codigo_hash, criado_em) VALUES (1, 'a', 'x'), (1, 'b', 'x')");
    }

    private function modelo(): Mapos_model
    {
        $modelo = $this->makeInstance(Mapos_model::class);
        $this->setPrivateProperty($modelo, 'db', $this->db);

        return $modelo;
    }

    private function criarLoginAttempts(): void
    {
        $this->db->query('CREATE TABLE login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            escopo VARCHAR(20) NOT NULL,
            tipo VARCHAR(10) NOT NULL,
            chave CHAR(64) NOT NULL,
            falhas INTEGER NOT NULL DEFAULT 0,
            bloqueado_ate DATETIME NULL,
            ultima_falha DATETIME NOT NULL
        )');
        $this->db->query('CREATE UNIQUE INDEX login_attempts_chave ON login_attempts (escopo, tipo, chave)');
    }

    private function falhasDeConfirmacao(): int
    {
        return (int) $this->db->select_sum('falhas')->where('escopo', 'confirmacao')->where('tipo', 'email')->get('login_attempts')->row()->falhas;
    }

    /** Controller sem construtor, com a tela capturada em vez de renderizada. */
    private function controller(bool $desativarFunciona = true): Mapos
    {
        $this->criarLoginAttempts();

        $controller = new class() extends Mapos {
            public array $post = [];

            public ?array $errosDaTela = null;

            public bool $telaFoiMostrada = false;

            public array $flash = [];

            public array $chamadas = ['verificar' => 0, 'ativarTotp' => 0, 'desativarTotp' => 0];

            public function __construct()
            {
            }

            protected function telaDoisFatores(array $erros = [], ?array $codigos = null)
            {
                $this->telaFoiMostrada = true;
                $this->errosDaTela = $erros;

                return null;
            }

            protected function usuarioDoDoisFatores(): object
            {
                return (object) [
                    'idUsuarios' => 1,
                    'email' => 'ana@x.com',
                    'senha' => password_hash('certa', PASSWORD_DEFAULT),
                    'totp_ativado_em' => $this->ativado ? '2026-01-01 00:00:00' : null,
                    'permissoes_id' => 1,
                ];
            }

            public bool $ativado = false;
        };

        $dono = $controller;
        $controller->input = new class($dono) {
            public function __construct(private object $dono)
            {
            }

            public function method(): string
            {
                return 'post';
            }

            public function post($chave)
            {
                return $this->dono->post[$chave] ?? null;
            }

            public function ip_address(): string
            {
                return '10.0.0.1';
            }
        };
        $controller->session = new class($dono) {
            public function __construct(private object $dono)
            {
            }

            public function userdata($chave)
            {
                return $chave === 'totp_pendente' ? 'SEGREDOPENDENTE' : null;
            }

            public function unset_userdata($chaves): void
            {
            }

            public function set_flashdata($tipo, $mensagem): void
            {
                $this->dono->flash[$tipo] = $mensagem;
            }
        };
        $controller->totp = new class($dono) {
            public function __construct(private object $dono)
            {
            }

            public function verificar($segredo, $codigo)
            {
                $this->dono->chamadas['verificar']++;

                return $codigo === '111111' ? 7 : null;
            }

            public function gerarCodigosDeRecuperacao(): array
            {
                return ['codigo-um'];
            }

            public function cifrar($segredo): string
            {
                return 'cifrado';
            }
        };
        $controller->mapos_model = new class($dono, $desativarFunciona) {
            public function __construct(private object $dono, private bool $desativa)
            {
            }

            public function ativarTotp(): bool
            {
                $this->dono->chamadas['ativarTotp']++;

                return true;
            }

            public function desativarTotp(): bool
            {
                $this->dono->chamadas['desativarTotp']++;

                return $this->desativa;
            }

            public function grupoExige2fa(): bool
            {
                return false;
            }
        };
        $controller->limite_login = new Limite_login(['db' => $this->db, 'segredo' => 'segredo-de-teste', 'auditar' => fn () => null]);
        $controller->load = new class() {
            public function __call($nome, $argumentos)
            {
                return null;
            }
        };

        return $controller;
    }

    public function testAtivacaoComSenhaErradaContaFalhaENaoConfereCodigo(): void
    {
        $controller = $this->controller();
        $controller->post = ['senha' => 'errada', 'codigo' => '111111'];

        $controller->ativarDoisFatores();

        $this->assertSame(['senha_ativar' => 'A senha não confere.'], $controller->errosDaTela);
        $this->assertSame(1, $this->falhasDeConfirmacao());
        $this->assertSame(0, $controller->chamadas['verificar']);
    }

    public function testSextaTentativaBloqueadaNemConfereOCodigo(): void
    {
        $controller = $this->controller();
        for ($i = 0; $i < 5; $i++) {
            $controller->post = ['senha' => 'errada', 'codigo' => '111111'];
            $controller->ativarDoisFatores();
        }

        $controller->post = ['senha' => 'certa', 'codigo' => '111111'];
        $controller->ativarDoisFatores();

        $this->assertSame(['senha_ativar' => Limite_login::MENSAGEM], $controller->errosDaTela);
        $this->assertSame(0, $controller->chamadas['verificar']);
        $this->assertSame(0, $controller->chamadas['ativarTotp']);
    }

    public function testCodigoErradoNaAtivacaoContaFalhaDeConfirmacao(): void
    {
        $controller = $this->controller();
        $controller->post = ['senha' => 'certa', 'codigo' => '000000'];

        $controller->ativarDoisFatores();

        $this->assertSame(['codigo' => 'Código incorreto. Confira o aplicativo e tente de novo.'], $controller->errosDaTela);
        $this->assertSame(1, $controller->chamadas['verificar']);
        $this->assertSame(1, $this->falhasDeConfirmacao());
        $this->assertSame(0, $controller->chamadas['ativarTotp']);
    }

    public function testSenhaECodigoCertosAtivam(): void
    {
        $controller = $this->controller();
        $controller->post = ['senha' => 'certa', 'codigo' => '111111'];

        $controller->ativarDoisFatores();

        $this->assertSame([], $controller->errosDaTela);
        $this->assertSame(1, $controller->chamadas['ativarTotp']);
        $this->assertSame(0, $this->falhasDeConfirmacao());
    }

    public function testDesativarTotpDevolveTrueEApagaTudo(): void
    {
        $this->criarTabelasDeUsuario();
        $this->db->query('UPDATE usuarios SET totp_ultimo_passo = 5 WHERE idUsuarios = 1');

        $this->assertTrue($this->modelo()->desativarTotp(1));

        $linha = $this->db->where('idUsuarios', 1)->get('usuarios')->row();
        $this->assertNull($linha->totp_secreto);
        $this->assertNull($linha->totp_ativado_em);
        $this->assertNull($linha->totp_ultimo_passo);
        $this->assertSame(0, $this->db->count_all('usuarios_codigos_recuperacao'));
    }

    public function testFalhaAoDesativarTotpReverteELoga(): void
    {
        $this->criarTabelasDeUsuario();
        $this->falhaAntesDe('DELETE', 'usuarios_codigos_recuperacao');

        $this->assertFalse($this->modelo()->desativarTotp(1));

        $linha = $this->db->where('idUsuarios', 1)->get('usuarios')->row();
        $this->assertSame('segredo', $linha->totp_secreto);
        $this->assertSame(2, $this->db->count_all('usuarios_codigos_recuperacao'));
        $this->assertLogContem('error', 'transacao rollback operacao=desativar_totp id=1');
    }

    public function testDesativarDoisFatoresComFalhaMostraErro(): void
    {
        $controller = $this->controller(false);
        $controller->ativado = true;
        $controller->post = ['senha' => 'certa'];

        $controller->desativarDoisFatores();

        $this->assertSame(['_geral' => 'Não foi possível desativar. Tente de novo.'], $controller->errosDaTela);
        $this->assertSame(1, $controller->chamadas['desativarTotp']);
        $this->assertSame([], $controller->flash);
    }

    public function testSalvarConfiguracaoComFalhaReverteTudo(): void
    {
        $this->db->query('CREATE TABLE configuracoes (idConfig INTEGER PRIMARY KEY AUTOINCREMENT, config TEXT UNIQUE, valor TEXT)');
        $this->db->query("INSERT INTO configuracoes (config, valor) VALUES ('app_name', 'Velho'), ('per_page', '10')");
        $this->falhaAntesDe('UPDATE', 'configuracoes');

        $this->assertFalse($this->modelo()->saveConfiguracao(['app_name' => 'X', 'per_page' => '20']));

        $this->assertSame('Velho', $this->db->where('config', 'app_name')->get('configuracoes')->row()->valor);
        $this->assertSame('10', $this->db->where('config', 'per_page')->get('configuracoes')->row()->valor);
        $this->assertLogContem('error', 'transacao rollback operacao=salvar_configuracao');
    }

    public function testSalvarConfiguracaoGravaAsDuasChaves(): void
    {
        $this->db->query('CREATE TABLE configuracoes (idConfig INTEGER PRIMARY KEY AUTOINCREMENT, config TEXT UNIQUE, valor TEXT)');
        $this->db->query("INSERT INTO configuracoes (config, valor) VALUES ('app_name', 'Velho'), ('per_page', '10')");

        $this->assertTrue($this->modelo()->saveConfiguracao(['app_name' => 'X', 'per_page' => '20']));

        $this->assertSame('X', $this->db->where('config', 'app_name')->get('configuracoes')->row()->valor);
        $this->assertSame('20', $this->db->where('config', 'per_page')->get('configuracoes')->row()->valor);
    }

    public function testSalvarConfiguracaoInsereChaveNova(): void
    {
        $this->db->query('CREATE TABLE configuracoes (idConfig INTEGER PRIMARY KEY AUTOINCREMENT, config TEXT UNIQUE, valor TEXT)');

        $this->assertTrue($this->modelo()->saveConfiguracao(['chave_nova' => 'v']));

        $this->assertSame('v', $this->db->where('config', 'chave_nova')->get('configuracoes')->row()->valor);
    }
}
