<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once APPPATH . 'libraries/Refresh_token.php';
require_once APPPATH . 'libraries/Authorization_Token.php';
require_once APPPATH . 'libraries/Limite_login.php';
require_once APPPATH . 'models/Clientes_model.php';
require_once APPPATH . 'controllers/api/v1/client/ClientLoginController.php';

/**
 * Tokens da API do cliente: access token de no máximo 1 h, refresh rotativo,
 * logout, troca de senha e o bloqueio da troca obrigatória. Executa os métodos
 * reais; response() vira exceção (na API ele encerra a requisição).
 */
final class ApiClienteTokensTest extends MaposTestCase
{
    private const CHAVE = 'chave-de-teste-com-mais-de-32-caracteres!!';

    private const SENHA = 'Senha123!';

    private int $agora;

    private bool $tinhaAutorizacao = false;

    private mixed $autorizacaoAnterior = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tinhaAutorizacao = array_key_exists('HTTP_AUTHORIZATION', $_SERVER);
        $this->autorizacaoAnterior = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $this->agora = strtotime('2026-10-14 10:00:00');
        $this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, email TEXT, senha TEXT, senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)');
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL DEFAULT \'usuario\', usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)');
        $this->db->query('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, escopo VARCHAR(20) NOT NULL, tipo VARCHAR(10) NOT NULL, chave CHAR(64) NOT NULL, falhas INTEGER NOT NULL DEFAULT 0, bloqueado_ate DATETIME NULL, ultima_falha DATETIME NOT NULL)');
        $this->db->query('CREATE UNIQUE INDEX login_attempts_chave ON login_attempts (escopo, tipo, chave)');
        $this->cliente(1, 0);
    }

    protected function tearDown(): void
    {
        if ($this->tinhaAutorizacao) {
            $_SERVER['HTTP_AUTHORIZATION'] = $this->autorizacaoAnterior;
        } else {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }
        $_POST = [];
        JWT::$timestamp = null;
        JWT::$leeway = 0;

        parent::tearDown();
    }

    private function cliente(int $id, int $trocar, string $senha = self::SENHA): void
    {
        $this->db->insert('clientes', [
            'idClientes' => $id,
            'nomeCliente' => 'Ana',
            'email' => 'ana@x.com',
            'senha' => password_hash($senha, PASSWORD_DEFAULT),
            'senha_trocar' => $trocar,
        ]);
    }

    private function autorizacao(int $expiracao = 2592000): Authorization_Token
    {
        $autorizacao = $this->makeInstance(Authorization_Token::class);
        $this->setPrivateProperty($autorizacao, 'token_key', self::CHAVE);
        $this->setPrivateProperty($autorizacao, 'token_algorithm', 'HS256');
        $this->setPrivateProperty($autorizacao, 'token_header', 'Authorization');
        $this->setPrivateProperty($autorizacao, 'token_expire_time', $expiracao);
        $this->setPrivateProperty($autorizacao, 'CI', (object) [
            'db' => $this->db,
            'input' => new class() {
                public function request_headers(): array
                {
                    return ['Authorization' => 'Bearer x'];
                }
            },
        ]);

        return $autorizacao;
    }

    private function refresh(): Refresh_token
    {
        return new Refresh_token(['db' => $this->db, 'relogio' => fn () => $this->agora]);
    }

    private function limite(): Limite_login
    {
        return new Limite_login(['db' => $this->db, 'segredo' => 'segredo-de-teste', 'relogio' => fn () => $this->agora, 'auditar' => fn () => null]);
    }

    private function model(?bool $registrar = null): Clientes_model
    {
        $model = $registrar === null
            ? new Clientes_model()
            : new class($registrar) extends Clientes_model {
                public function __construct(private bool $resultado)
                {
                }

                public function registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool
                {
                    return $this->resultado;
                }
            };
        $model->db = $this->db;

        return $model;
    }

    /** Controller real da API do cliente, sem o framework: dependências injetadas e response() lançando. */
    private function controller(array $entrada = [], ?Clientes_model $model = null): ClientLoginController
    {
        return new class($this->db, $this->autorizacao(), $this->refresh(), $this->limite(), $model ?? $this->model(), $entrada) extends ClientLoginController {
            public $db;

            public $authorization_token;

            public $refresh_token;

            public $limite_login;

            public $Clientes_model;

            public $Audit_model;

            public $client;

            public $input;

            public $output;

            public $load;

            public function __construct($db, $autorizacao, $refresh, $limite, $model, private array $entrada)
            {
                $this->db = $db;
                $this->authorization_token = $autorizacao;
                $this->refresh_token = $refresh;
                $this->limite_login = $limite;
                $this->Clientes_model = $model;
                $this->Audit_model = new class() {
                    public function add($dados): void
                    {
                    }
                };
                $this->input = new class() {
                    public function ip_address(): string
                    {
                        return '10.0.0.1';
                    }

                    public function user_agent(): string
                    {
                        return 'app-teste';
                    }

                    public function post($chave): mixed
                    {
                        return $_POST[$chave] ?? null;
                    }
                };
                $this->output = new class() {
                    public array $cabecalhos = [];

                    public function set_header(string $cabecalho): void
                    {
                        $this->cabecalhos[] = $cabecalho;
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

            public function post($key = null, $xss_clean = null)
            {
                return $this->entrada[$key] ?? null;
            }

            protected function corpoJson(): array
            {
                return $this->entrada;
            }
        };
    }

    /** @return array{code: int, data: mixed} */
    private function chamar(ClientLoginController $api, string $metodo): array
    {
        try {
            $api->{$metodo}();
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        $this->fail('A API deveria ter respondido.');
    }

    private function login(array $entrada = ['email' => 'ana@x.com', 'password' => self::SENHA]): array
    {
        return $this->chamar($this->controller($entrada), 'login_post');
    }

    private function decodificar(string $jwt): object
    {
        return JWT::decode($jwt, new Key(self::CHAVE, 'HS256'));
    }

    /** Código e corpo de logged_client() com o access token dado. */
    private function logged(?string $accessToken, bool $permitirTroca = false): array
    {
        if ($accessToken === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        }

        $api = $this->controller();
        try {
            $sessao = $api->logged_client($permitirTroca);
        } catch (RuntimeException $e) {
            return json_decode($e->getMessage(), true);
        }

        return ['code' => 200, 'data' => $sessao];
    }

    private function jwt(array $dados): string
    {
        return $this->autorizacao()->generateToken($dados);
    }

    private function loginOk(): array
    {
        $resposta = $this->login();
        $this->assertSame(200, $resposta['code']);

        return $resposta['data']['result'];
    }

    public function testAccessTokenDoClienteDuraNoMaximoUmaHora(): void
    {
        $resposta = $this->login();

        $this->assertSame(200, $resposta['code']);
        $this->assertSame(3600, $resposta['data']['result']['expires_in']);
        $this->assertSame('Bearer', $resposta['data']['result']['token_type']);
        $dados = $this->decodificar($resposta['data']['result']['access_token']);
        $this->assertSame(3600, $dados->exp - $dados->iat);
        $this->assertSame('client', $dados->type);
        $this->assertSame(1, $dados->uid);
        $linha = $this->db->get('api_refresh_tokens')->row();
        $this->assertSame('cliente', $linha->tipo);
        $this->assertSame($linha->familia, $dados->sid);
        $this->assertSame(Refresh_token::hash($resposta['data']['result']['refresh_token']), $linha->token_hash);
    }

    public function testLoginInformaTrocaDeSenhaObrigatoria(): void
    {
        $this->assertFalse($this->loginOk()['troca_de_senha_obrigatoria']);

        $this->db->update('clientes', ['senha_trocar' => 1]);

        $this->assertTrue($this->loginOk()['troca_de_senha_obrigatoria']);
    }

    public function testLoginSemRefreshResponde503(): void
    {
        // Tabela sem a coluna `tipo` (migration não rodada): emitir() devolve null.
        $this->db->query('DROP TABLE api_refresh_tokens');
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)');

        $resposta = $this->login();

        $this->assertSame(503, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Não foi possível iniciar a sessão. Tente novamente.'], $resposta['data']);
        $this->assertLogContem('error', 'api cliente login sem refresh token');
    }

    public function testRefreshDoClienteGiraOPar(): void
    {
        $sessao = $this->loginOk();
        $sidAntigo = $this->decodificar($sessao['access_token'])->sid;

        $resposta = $this->chamar($this->controller(['refresh_token' => $sessao['refresh_token']]), 'refresh_post');

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('Token renovado!', $resposta['data']['message']);
        $novo = $resposta['data']['result'];
        $this->assertSame(3600, $novo['expires_in']);
        $this->assertNotSame($sessao['refresh_token'], $novo['refresh_token']);
        $this->assertSame($sidAntigo, $this->decodificar($novo['access_token'])->sid);
    }

    public function testReusoDoRefreshRevogaAFamilia(): void
    {
        $sessao = $this->loginOk();
        $this->chamar($this->controller(['refresh_token' => $sessao['refresh_token']]), 'refresh_post');
        $this->agora += Refresh_token::TOLERANCIA_SEGUNDOS + 60;

        $resposta = $this->chamar($this->controller(['refresh_token' => $sessao['refresh_token']]), 'refresh_post');

        $this->assertSame(401, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Faça o login novamente!'], $resposta['data']);
        $this->assertSame(401, $this->logged($sessao['access_token'])['code']);
        $this->assertSame(0, $this->db->where('revogado_em', null)->count_all_results('api_refresh_tokens'));
    }

    public function testRefreshNaoCruzaTipos(): void
    {
        $operador = $this->refresh()->emitir(5);

        $resposta = $this->chamar($this->controller(['refresh_token' => $operador]), 'refresh_post');

        $this->assertSame(401, $resposta['code']);
        $this->assertNull($this->db->where('tipo', 'usuario')->get('api_refresh_tokens')->row()->usado_em);

        $cliente = $this->refresh()->emitir(1, null, null, null, 'cliente');
        $this->assertSame(['ok' => false, 'motivo' => 'invalido'], $this->refresh()->girar($cliente));
        $this->assertNull($this->db->where('tipo', 'cliente')->get('api_refresh_tokens')->row()->usado_em);
    }

    public function testRefreshDeClienteExcluidoResponde401(): void
    {
        $token = $this->refresh()->emitir(99, null, null, null, 'cliente');

        $resposta = $this->chamar($this->controller(['refresh_token' => $token]), 'refresh_post');

        $this->assertSame(401, $resposta['code']);
        $this->assertSame(0, $this->db->where('usuario_id', 99)->where('revogado_em', null)->count_all_results('api_refresh_tokens'));
        // O giro consumiu o token e emitiu o sucessor; os dois ficam revogados.
        $this->assertSame(2, $this->db->where('usuario_id', 99)->count_all_results('api_refresh_tokens'));
    }

    public function testLogoutRevogaASessao(): void
    {
        $sessao = $this->loginOk();

        $resposta = $this->chamar($this->controller(['refresh_token' => $sessao['refresh_token']]), 'logout_post');

        $this->assertSame(['code' => 204, 'data' => null], $resposta);
        $this->assertSame(401, $this->chamar($this->controller(['refresh_token' => $sessao['refresh_token']]), 'refresh_post')['code']);
        $this->assertSame(['code' => 204, 'data' => null], $this->chamar($this->controller([]), 'logout_post'));
        $this->assertSame(['code' => 204, 'data' => null], $this->chamar($this->controller(['refresh_token' => 'desconhecido']), 'logout_post'));
    }

    public function testLoggedClientResponde401EmTodaFalhaDeToken(): void
    {
        $sessao = $this->loginOk();
        $sid = $this->decodificar($sessao['access_token'])->sid;
        $base = ['uid' => 1, 'type' => 'client', 'tv' => 0, 'sid' => $sid];

        $this->assertSame(['code' => 401, 'data' => ['status' => false, 'message' => 'Faça login para acessar a API.']], $this->logged(null));
        $respostaDeLogin = ['code' => 401, 'data' => ['status' => false, 'message' => 'Faça o login novamente!']];
        $this->assertSame($respostaDeLogin, $this->logged('token-invalido'));
        $this->assertSame($respostaDeLogin, $this->logged($this->jwt(['type' => 'user'] + $base)));
        $this->assertSame($respostaDeLogin, $this->logged($this->jwt(['sid' => ''] + $base)));
        $this->assertSame($respostaDeLogin, $this->logged($this->jwt(['uid' => 77] + $base)));
        $this->assertSame(200, $this->logged($this->jwt($base))['code']);

        $this->db->update('clientes', ['token_versao' => 1]);
        $this->assertSame($respostaDeLogin, $this->logged($this->jwt($base)));
        $this->db->update('clientes', ['token_versao' => 0]);

        $this->refresh()->revogar($sessao['refresh_token']);
        $this->assertSame($respostaDeLogin, $this->logged($this->jwt($base)));

        $semSid = $base;
        unset($semSid['sid']);
        $this->assertSame($respostaDeLogin, $this->logged($this->jwt($semSid)));
    }

    public function testTrocaPendenteBloqueiaExcetoQuemPermite(): void
    {
        $sessao = $this->loginOk();
        $this->db->update('clientes', ['senha_trocar' => 1]);

        $this->assertSame([
            'code' => 403,
            'data' => ['status' => false, 'code' => 'troca_de_senha_obrigatoria', 'message' => 'Defina uma nova senha antes de continuar.'],
        ], $this->logged($sessao['access_token']));

        $liberado = $this->logged($sessao['access_token'], true);
        $this->assertSame(200, $liberado['code']);
        $this->assertSame(1, (int) $liberado['data']->usuario->idClientes);
    }

    /** Chama PUT client/password com o access token dado. */
    private function trocar(string $accessToken, array $entrada, ?Clientes_model $model = null, ?ClientLoginController &$api = null): array
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        $api = $this->controller($entrada, $model);

        return $this->chamar($api, 'password_put');
    }

    private function falhasDeConfirmacao(): array
    {
        return array_map(static fn ($linha) => (int) $linha->falhas, $this->db->where('escopo', 'confirmacao')->get('login_attempts')->result());
    }

    public function testTrocaComSenhaAtualErradaResponde422(): void
    {
        $sessao = $this->loginOk();
        $this->db->update('clientes', ['senha_trocar' => 1]);

        $resposta = $this->trocar($sessao['access_token'], ['senha_atual' => 'errada', 'senha_nova' => 'NovaSenha123']);

        $this->assertSame(422, $resposta['code']);
        $this->assertSame(['status' => false, 'message' => 'Dados inválidos.', 'erros' => ['senha_atual' => 'A senha atual não confere.']], $resposta['data']);
        $this->assertSame([1, 1], $this->falhasDeConfirmacao());
    }

    public function testTrocaComSenhaNovaInvalidaResponde422(): void
    {
        $sessao = $this->loginOk();
        $curta = ['status' => false, 'message' => 'Dados inválidos.', 'erros' => ['senha_nova' => 'A senha deve ter de 8 a 72 caracteres.']];

        $resposta = $this->trocar($sessao['access_token'], ['senha_atual' => self::SENHA, 'senha_nova' => 'curta']);
        $this->assertSame(['code' => 422, 'data' => $curta], $resposta);
        $this->assertSame([], $this->falhasDeConfirmacao());

        $longa = $this->trocar($sessao['access_token'], ['senha_atual' => self::SENHA, 'senha_nova' => str_repeat('a', 73)]);
        $this->assertSame(['code' => 422, 'data' => $curta], $longa);

        $igual = $this->trocar($sessao['access_token'], ['senha_atual' => self::SENHA, 'senha_nova' => self::SENHA]);
        $this->assertSame(422, $igual['code']);
        $this->assertSame(['senha_nova' => 'A nova senha precisa ser diferente da atual.'], $igual['data']['erros']);
    }

    public function testTrocaBloqueadaResponde429(): void
    {
        $sessao = $this->loginOk();
        for ($i = 0; $i < Limite_login::LIMITE_EMAIL; $i++) {
            $this->limite()->registrarFalha('confirmacao', 'ana@x.com', '10.0.0.1');
        }

        $resposta = $this->trocar($sessao['access_token'], ['senha_atual' => self::SENHA, 'senha_nova' => 'NovaSenha123'], null, $api);

        $this->assertSame(['code' => 429, 'data' => ['status' => false, 'message' => Limite_login::MENSAGEM]], $resposta);
        $this->assertSame(['Retry-After: 60'], $api->output->cabecalhos);
        $this->assertSame(0, (int) $this->db->get('clientes')->row()->token_versao);
    }

    public function testTrocaDeSenhaPelaApiRenovaOsTokens(): void
    {
        $sessao = $this->loginOk();
        $this->db->update('clientes', ['senha_trocar' => 1]);

        $resposta = $this->trocar($sessao['access_token'], ['senha_atual' => self::SENHA, 'senha_nova' => 'NovaSenha123']);

        $this->assertSame(200, $resposta['code']);
        $this->assertSame('Senha alterada.', $resposta['data']['message']);
        $novo = $resposta['data']['result'];
        $this->assertSame(3600, $novo['expires_in']);
        $cliente = $this->db->get('clientes')->row();
        $this->assertSame([0, 1, 1], [(int) $cliente->senha_trocar, (int) $cliente->token_versao, (int) $cliente->sessao_versao]);
        $this->assertTrue(password_verify('NovaSenha123', $cliente->senha));
        $this->assertSame(401, $this->logged($sessao['access_token'], true)['code']);
        $this->assertSame(200, $this->logged($novo['access_token'])['code']);
        $this->assertSame(401, $this->chamar($this->controller(['refresh_token' => $sessao['refresh_token']]), 'refresh_post')['code']);
        $this->assertSame(200, $this->chamar($this->controller(['refresh_token' => $novo['refresh_token']]), 'refresh_post')['code']);
    }

    public function testTrocaComFalhaResponde500(): void
    {
        $sessao = $this->loginOk();

        $resposta = $this->trocar($sessao['access_token'], ['senha_atual' => self::SENHA, 'senha_nova' => 'NovaSenha123'], $this->model(false));

        $this->assertSame(['code' => 500, 'data' => ['status' => false, 'message' => 'Não foi possível alterar a senha.']], $resposta);
    }

    public function testRotasEContratoNoCodigo(): void
    {
        $rotas = (string) file_get_contents(APPPATH . 'config/routes_api.php');

        $this->assertStringContainsString("\$route['api/v1/client/refresh'] = 'api/v1/client/ClientLoginController/refresh';", $rotas);
        $this->assertStringContainsString("\$route['api/v1/client/logout'] = 'api/v1/client/ClientLoginController/logout';", $rotas);
        $this->assertStringContainsString("\$route['api/v1/client/password'] = 'api/v1/client/ClientLoginController/password';", $rotas);
    }
}
