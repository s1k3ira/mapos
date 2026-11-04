<?php

require_once APPPATH . 'helpers/api_helper.php';
require_once APPPATH . 'libraries/Authorization_Token.php';
require_once APPPATH . 'controllers/api/v1/ApiController.php';
require_once APPPATH . 'controllers/api/v1/UsuariosController.php';

/**
 * Base da API: exceção vira 500 em JSON, 401 x 403, grupo desativado,
 * paginação, datas e calendário. Executa os métodos reais; response() vira
 * exceção (na API ele encerra a requisição).
 */
final class ApiBaseTest extends MaposTestCase
{
    private mixed $autorizacaoAnterior = null;

    private bool $tinhaAutorizacao = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tinhaAutorizacao = array_key_exists('HTTP_AUTHORIZATION', $_SERVER);
        $this->autorizacaoAnterior = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        if ($this->tinhaAutorizacao) {
            $_SERVER['HTTP_AUTHORIZATION'] = $this->autorizacaoAnterior;
        } else {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }
        parent::tearDown();
    }

    private function permissao(bool $pode): object
    {
        return new class($pode) {
            public function __construct(public bool $pode)
            {
            }

            public function checkPermission($nivel, $permissao): bool
            {
                return $this->pode;
            }
        };
    }

    /** Controller de API com tudo o que o framework injetaria substituído por dados do teste. */
    private function controller(array $opcoes = []): ApiController
    {
        $permissao = $this->permissao($opcoes['pode'] ?? true);
        $token = new class($opcoes['token'] ?? ['status' => false]) {
            public function __construct(public array $token)
            {
            }

            public function validateToken($valor): array
            {
                return $this->token;
            }

            public function versaoAtual(string $tabela, string $campoId, int $id): int
            {
                return 0;
            }
        };
        $usuarios = new class($opcoes['usuario'] ?? null) {
            public function __construct(public ?object $usuario)
            {
            }

            public function getById($id): ?object
            {
                return $this->usuario;
            }
        };
        $mapos = new class($opcoes['grupoAtivo'] ?? true, $opcoes['os'] ?? []) {
            public function __construct(public bool $grupoAtivo, public array $os)
            {
            }

            public function grupoAtivo(int $grupo): bool
            {
                return $this->grupoAtivo;
            }

            public function calendario($start, $end, $status): array
            {
                return $this->os;
            }
        };

        return new class($permissao, $token, $usuarios, $mapos, $opcoes['get'] ?? []) extends ApiController {
            public $permission;

            public $authorization_token;

            public $Usuarios_model;

            public $Mapos_model;

            public $mapos_model;

            public $os_model;

            public $load;

            public $uri;

            public array $consulta;

            public function __construct($permissao, $token, $usuarios, $mapos, array $consulta)
            {
                $this->permission = $permissao;
                $this->authorization_token = $token;
                $this->Usuarios_model = $usuarios;
                $this->Mapos_model = $mapos;
                $this->mapos_model = $mapos;
                $this->os_model = new class() {
                    public function isEditable($id): bool
                    {
                        return true;
                    }
                };
                $this->consulta = $consulta;
                $this->uri = new class() {
                    public function uri_string(): string
                    {
                        return 'api/v1/teste';
                    }
                };
                $this->load = new class() {
                    public function __call($nome, $args)
                    {
                        return null;
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

            public function get($key = null, $xss_clean = null)
            {
                return $this->consulta[$key] ?? null;
            }

            public function lancaTypeError(): void
            {
                throw new TypeError('tipo errado');
            }

            public function lancaRuntime(): void
            {
                throw new RuntimeException('boom');
            }

            public function segue(): void
            {
                $this->exigirPermissao('vOs', 'Você não está autorizado a Visualizar OSs');
                $this->naoResponde = true;
            }

            public bool $naoResponde = false;

            public function logged_user_nivel()
            {
                return (object) ['level' => 1];
            }
        };
    }

    /** @return array{code: int, data: array} */
    private function resposta(callable $acao): array
    {
        try {
            $acao();
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        $this->fail('A API deveria ter respondido.');
    }

    private function usuario(array $campos = []): object
    {
        return (object) ($campos + ['situacao' => '1', 'dataExpiracao' => null, 'permissoes_id' => '2']);
    }

    private function tokenValido(string $tipo = 'user'): array
    {
        return ['status' => true, 'data' => (object) ['type' => $tipo, 'uid' => 5, 'tv' => 0]];
    }

    // ---- exceção não tratada -------------------------------------------------

    public function testExcecaoNaoTratadaViraErroInternoEmJsonComLinhaNoLog(): void
    {
        $api = $this->controller();
        $resposta = $this->resposta(fn () => $this->invokeMethod($api, 'executarMetodo', ['lancaTypeError', []]));

        $this->assertSame(500, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Erro interno'], $resposta['data']);
        $this->assertLogContem('error', 'api excecao classe=TypeError mensagem=tipo errado');
        $this->assertLogContem('error', 'rota=api/v1/teste');
        $this->assertStringNotContainsString('.php', json_encode($resposta['data']));
    }

    public function testRuntimeExceptionTambemViraErroInterno(): void
    {
        $api = $this->controller();
        $resposta = $this->resposta(fn () => $this->invokeMethod($api, 'executarMetodo', ['lancaRuntime', []]));

        $this->assertSame(500, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Erro interno'], $resposta['data']);
        $this->assertLogContem('error', 'api excecao classe=RuntimeException mensagem=boom');
    }

    // ---- 403 por falta de permissão ------------------------------------------

    public function testExigirPermissaoNegaComForbiddenEAMensagem(): void
    {
        $api = $this->controller(['pode' => false, 'token' => $this->tokenValido(), 'usuario' => $this->usuario()]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';

        $resposta = $this->resposta(fn () => $api->segue());

        $this->assertSame(403, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Você não está autorizado a Visualizar OSs'], $resposta['data']);
    }

    public function testExigirPermissaoNaoRespondeQuandoPermite(): void
    {
        $api = $this->controller(['pode' => true, 'token' => $this->tokenValido(), 'usuario' => $this->usuario()]);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';

        $api->segue();

        $this->assertTrue($api->naoResponde);
    }

    // ---- 401 em logged_user --------------------------------------------------

    public function testLoggedUserSemHeaderResponde401(): void
    {
        $resposta = $this->resposta(fn () => $this->controller()->logged_user());

        $this->assertSame(401, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Faça login para acessar a API.'], $resposta['data']);
    }

    public function testLoggedUserComTokenInvalidoResponde401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ruim';
        $resposta = $this->resposta(fn () => $this->controller(['token' => ['status' => false]])->logged_user());

        $this->assertSame(401, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Faça o login novamente!'], $resposta['data']);
    }

    public function testLoggedUserComTokenDeClienteResponde401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer cliente';
        $resposta = $this->resposta(fn () => $this->controller(['token' => $this->tokenValido('client'), 'usuario' => $this->usuario()])->logged_user());

        $this->assertSame(401, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Faça o login novamente!'], $resposta['data']);
    }

    public function testLoggedUserComUsuarioDesativadoResponde401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller(['token' => $this->tokenValido(), 'usuario' => $this->usuario(['situacao' => '0'])]);

        $this->assertSame(401, $this->resposta(fn () => $api->logged_user())['code']);
    }

    public function testLoggedUserComContaExpiradaResponde401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller(['token' => $this->tokenValido(), 'usuario' => $this->usuario(['dataExpiracao' => '2020-01-01'])]);

        $this->assertSame(401, $this->resposta(fn () => $api->logged_user())['code']);
    }

    public function testLoggedUserComGrupoDesativadoResponde401(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller(['token' => $this->tokenValido(), 'usuario' => $this->usuario(), 'grupoAtivo' => false]);
        $resposta = $this->resposta(fn () => $api->logged_user());

        $this->assertSame(401, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Faça o login novamente!'], $resposta['data']);
    }

    public function testLoggedUserValidoDevolveONivelDoBanco(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller(['token' => $this->tokenValido(), 'usuario' => $this->usuario()]);

        $this->assertSame('2', $api->logged_user()->level);
    }

    // ---- grupo ativo ---------------------------------------------------------

    public function testApiUsuarioPodeUsarApiComGrupoAtivo(): void
    {
        $this->assertTrue(apiUsuarioPodeUsarApi($this->usuario(), fn (int $g): bool => $g === 2));
    }

    public function testApiUsuarioPodeUsarApiNegaGrupoDesativado(): void
    {
        $this->assertFalse(apiUsuarioPodeUsarApi($this->usuario(), fn (int $g): bool => false));
    }

    public function testApiUsuarioPodeUsarApiNegaUsuarioInexistenteDesativadoEExpirado(): void
    {
        $this->assertFalse(apiUsuarioPodeUsarApi(null, fn (int $g): bool => true));
        $this->assertFalse(apiUsuarioPodeUsarApi($this->usuario(['situacao' => '0']), fn (int $g): bool => true));
        $this->assertFalse(apiUsuarioPodeUsarApi($this->usuario(['dataExpiracao' => '2020-01-01']), fn (int $g): bool => true));
    }

    public function testApiUsuarioPodeUsarApiNaoGeraDeprecatedComCamposNulos(): void
    {
        $usuario = (object) ['situacao' => 1];

        $this->assertTrue($this->semDeprecated(fn () => apiUsuarioPodeUsarApi($usuario, fn (int $g): bool => true)));
    }

    public function testRefreshDeGrupoDesativadoResponde401ERevogaOsRefreshTokens(): void
    {
        $refresh = new class() {
            public array $revogados = [];

            public function girar($token, $ip, $agente): array
            {
                return ['ok' => true, 'usuario_id' => 7, 'familia' => 'f', 'refresh_token' => 'novo'];
            }

            public function revogarDoUsuario(int $id): void
            {
                $this->revogados[] = $id;
            }
        };
        $usuarios = new class($this->usuario()) {
            public function __construct(public object $usuario)
            {
            }

            public function getById($id): object
            {
                return $this->usuario;
            }
        };
        $mapos = new class() {
            public function grupoAtivo(int $grupo): bool
            {
                return false;
            }
        };
        $api = new class($refresh, $usuarios, $mapos) extends UsuariosController {
            public $refresh_token;

            public $usuarios_model;

            public $Mapos_model;

            public $load;

            public $input;

            public function __construct($refresh, $usuarios, $mapos)
            {
                $this->refresh_token = $refresh;
                $this->usuarios_model = $usuarios;
                $this->Mapos_model = $mapos;
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

                    public function user_agent(): string
                    {
                        return 'teste';
                    }
                };
            }

            public function __destruct()
            {
            }

            public function response($data = null, $http_code = null, $continue = false)
            {
                throw new RuntimeException(json_encode(['code' => $http_code, 'data' => $data]));
            }

            public function post($key = null, $xss_clean = null)
            {
                return 'refresh-valido';
            }
        };

        $resposta = $this->resposta(fn () => $api->refresh_post());

        $this->assertSame(401, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Faça o login novamente!'], $resposta['data']);
        $this->assertSame([7], $refresh->revogados);
    }

    // ---- paginação -----------------------------------------------------------

    public function testApiPaginacaoPadrao(): void
    {
        $this->assertSame(['ok' => true, 'limite' => 20, 'offset' => 0], apiPaginacao(null, null));
    }

    public function testApiPaginacaoCalculaOOffset(): void
    {
        $this->assertSame(['ok' => true, 'limite' => 10, 'offset' => 20], apiPaginacao('10', '2'));
    }

    public function testApiPaginacaoLimitaPerPageACem(): void
    {
        $this->assertSame(['ok' => true, 'limite' => 100, 'offset' => 0], apiPaginacao('1000000', '0'));
    }

    public function testApiPaginacaoRecusaPerPageInvalido(): void
    {
        $esperado = ['ok' => false, 'erros' => ['perPage' => 'Informe um número inteiro de 1 a 100.']];

        $this->assertSame($esperado, apiPaginacao('abc', '0'));
        $this->assertSame($esperado, apiPaginacao('0', '0'));
        $this->assertSame($esperado, apiPaginacao('-5', '0'));
    }

    public function testApiPaginacaoRecusaPageInvalido(): void
    {
        $esperado = ['ok' => false, 'erros' => ['page' => 'Informe um número inteiro maior ou igual a 0.']];

        $this->assertSame($esperado, apiPaginacao('10', '-1'));
        $this->assertSame($esperado, apiPaginacao('10', '1.5'));
    }

    public function testPaginacaoDaApiResponde422ParaPerPageNaoInteiro(): void
    {
        $api = $this->controller(['get' => ['perPage' => 'abc']]);
        $resposta = $this->resposta(fn () => $this->invokeMethod($api, 'paginacaoDaApi'));

        $this->assertSame(422, $resposta['code']);
        $this->assertSame([
            'status' => false,
            'message' => 'Parâmetros de paginação inválidos.',
            'erros' => ['perPage' => 'Informe um número inteiro de 1 a 100.'],
        ], $resposta['data']);
    }

    public function testPaginacaoDaApiDevolveLimiteEOffset(): void
    {
        $api = $this->controller(['get' => ['perPage' => '10', 'page' => '3']]);

        $this->assertSame([10, 30], $this->invokeMethod($api, 'paginacaoDaApi'));
    }

    // ---- datas ---------------------------------------------------------------

    public function testApiDataValidaRecusaDiaInexistenteEFormatoErrado(): void
    {
        $this->assertNull(apiDataValida('31/02/2026', 'd/m/Y'));
        $this->assertNull(apiDataValida('2026-01-01', 'd/m/Y'));
    }

    public function testApiDataValidaAceitaEConverteParaYmd(): void
    {
        $this->assertSame('2026-03-15', apiDataValida('15/03/2026', 'd/m/Y'));
        $this->assertSame('2026-03-15', apiDataValida('2026-03-15', 'Y-m-d'));
    }

    public function testApiJanelaValida(): void
    {
        $this->assertFalse(apiJanelaValida('2026-01-01', '2027-02-05'));
        $this->assertTrue(apiJanelaValida('2026-01-01', '2026-12-31'));
        $this->assertFalse(apiJanelaValida('2026-02-01', '2026-01-01'));
    }

    // ---- calendário ----------------------------------------------------------

    private function os(int $id, string $cliente): object
    {
        return (object) [
            'idOs' => $id, 'nomeCliente' => $cliente, 'status' => 'Aberto', 'dataInicial' => '2026-01-10', 'dataFinal' => '2026-01-12',
            'garantia' => '30', 'descricaoProduto' => 'Notebook', 'defeito' => 'Liga', 'observacoes' => '-',
            'totalProdutos' => 10, 'totalServicos' => 5, 'valorTotal' => 15, 'desconto' => 0, 'tipo_desconto' => 'real', 'faturado' => 0,
        ];
    }

    public function testCalendarioDevolveOsEventosMontadosSemAllOs(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller([
            'token' => $this->tokenValido(), 'usuario' => $this->usuario(),
            'os' => [$this->os(1, 'Ana'), $this->os(2, 'Bia')],
            'get' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
        ]);

        $resposta = $this->resposta(fn () => $api->calendario_get());

        $this->assertSame(200, $resposta['code']);
        $this->assertSame(['start', 'end', 'events'], array_keys($resposta['data']['result']));
        $this->assertSame(['OS: 1, Cliente: Ana', 'OS: 2, Cliente: Bia'], array_column($resposta['data']['result']['events'], 'title'));
    }

    public function testCalendarioComStartInvalidoResponde422(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller(['token' => $this->tokenValido(), 'usuario' => $this->usuario(), 'get' => ['start' => '2026-13-01']]);

        $resposta = $this->resposta(fn () => $api->calendario_get());

        $this->assertSame(422, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Datas inválidas.', 'erros' => ['start' => 'Use o formato aaaa-mm-dd.']], $resposta['data']);
    }

    public function testCalendarioComJanelaAcimaDe366DiasResponde422(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller(['token' => $this->tokenValido(), 'usuario' => $this->usuario(), 'get' => ['start' => '2026-01-01', 'end' => '2027-02-05']]);

        $resposta = $this->resposta(fn () => $api->calendario_get());

        $this->assertSame(422, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Datas inválidas.', 'erros' => ['end' => 'O período pode ter no máximo 366 dias.']], $resposta['data']);
    }

    public function testCalendarioSemPermissaoResponde403(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc';
        $api = $this->controller(['pode' => false, 'token' => $this->tokenValido(), 'usuario' => $this->usuario()]);

        $this->assertSame(403, $this->resposta(fn () => $api->calendario_get())['code']);
    }
}
