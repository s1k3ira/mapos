<?php

/*
    Ivan Sarkozin
    https://github.com/sarkozin
*/

defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . '/libraries/REST_Controller.php'; // @phpstan-ignore requireOnce.fileNotFound

class ClientLoginController extends REST_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Conecte_model');
        $this->load->library('Authorization_Token');
    }

    public function login_post()
    {
        header('Content-Type: application/json');
        
        $_POST = $this->corpoJson();
        $email = $this->input->post('email', true);
        $password = $this->input->post('password', true);

        $ip = $this->input->ip_address();

        // Limite de tentativas (#2870), no mesmo escopo da área do cliente.
        $this->load->library('Limite_login');
        $restantes = $this->limite_login->segundosRestantes('cliente', $email, $ip);
        if ($restantes > 0) {
            header('Retry-After: ' . $restantes);
            $this->response([
                'status' => false,
                'message' => Limite_login::MENSAGEM,
            ], REST_Controller::HTTP_TOO_MANY_REQUESTS);

            return;
        }

        $cliente = $this->check_credentials($email);

        // Mesma resposta para e-mail inexistente e senha errada: "Usuário não
        // encontrado" revelava quais e-mails possuem cadastro.
        $senhaConfere = senhaConfere((string) $password, $cliente->senha ?? null);
        if (!$cliente || !$senhaConfere) {
            $this->limite_login->registrarFalha('cliente', $email, $ip);
            $this->response([
                'status' => false,
                'message' => 'Os dados de acesso estão incorretos.'
                ], REST_Controller::HTTP_UNAUTHORIZED);

            return;
        }

        $this->limite_login->registrarSucesso('cliente', $email);

        $this->load->library('Refresh_token');
        $refresh = $this->refresh_token->emitir((int) $cliente->idClientes, $ip, $this->input->user_agent(), null, 'cliente');
        if ($refresh === null) {
            log_message('error', 'api cliente login sem refresh token');
            $this->response([
                'status' => false,
                'message' => 'Não foi possível iniciar a sessão. Tente novamente.',
            ], REST_Controller::HTTP_SERVICE_UNAVAILABLE);

            return;
        }

        $result = $this->sessaoDoCliente($cliente, $refresh);
        $result['troca_de_senha_obrigatoria'] = (int) ($cliente->senha_trocar ?? 0) === 1;

        $this->load->model('Audit_model');
        $log_data = [
            'usuario' => $cliente->nomeCliente,
            'tarefa' => 'Cliente ' . $cliente->nomeCliente . ' efetuou login pelo APP',
            'data' => date('Y-m-d'),
            'hora' => date('H:i:s'),
            'ip' => $ip
        ];
        $this->Audit_model->add($log_data);

        $this->response([
            'status' => true,
            'message' => 'Login bem-sucedido',
            'result' => $result
        ], REST_Controller::HTTP_OK);
    }

    /**
     * Renova o access token do cliente com o refresh token (uso único).
     * POST /api/v1/client/refresh {"refresh_token": "..."}
     */
    public function refresh_post()
    {
        $_POST = $this->corpoJson();
        $refreshToken = (string) $this->post('refresh_token', true);

        $this->load->library('Refresh_token');
        $this->load->model('Clientes_model');
        $girado = $this->refresh_token->girar($refreshToken, $this->input->ip_address(), $this->input->user_agent(), 'cliente');

        $cliente = $girado['ok'] ? $this->Clientes_model->getById($girado['usuario_id']) : null;
        // Cliente excluído: a sessão acaba aqui.
        if ($girado['ok'] && ! $cliente) {
            $this->refresh_token->revogarDoUsuario((int) $girado['usuario_id'], 'cliente');
            $girado = ['ok' => false];
        }

        if (! $girado['ok']) {
            $this->response([
                'status' => false,
                'message' => 'Faça o login novamente!',
            ], REST_Controller::HTTP_UNAUTHORIZED);

            return;
        }

        $this->response([
            'status' => true,
            'message' => 'Token renovado!',
            'result' => $this->sessaoDoCliente($cliente, $girado['refresh_token'], $girado['familia']),
        ], REST_Controller::HTTP_OK);
    }

    /**
     * Encerra a sessão: revoga o refresh token e a sessão a que ele pertence.
     * POST /api/v1/client/logout {"refresh_token": "..."}. Sempre 204.
     */
    public function logout_post()
    {
        $_POST = $this->corpoJson();

        $this->load->library('Refresh_token');
        $this->refresh_token->revogar((string) ($this->post('refresh_token', true) ?? ''));

        $this->response(null, REST_Controller::HTTP_NO_CONTENT);
    }

    /**
     * Troca a senha pelo app. Vale também com a troca obrigatória pendente.
     * PUT /api/v1/client/password {"senha_atual": "...", "senha_nova": "..."}
     */
    public function password_put()
    {
        $sessao = $this->logged_client(true);
        $cliente = $sessao->usuario;
        $corpo = $this->corpoJson();
        $atual = (string) ($corpo['senha_atual'] ?? '');
        $nova = (string) ($corpo['senha_nova'] ?? '');
        $email = (string) $cliente->email;
        $ip = $this->input->ip_address();

        $this->load->library('Limite_login');
        $this->load->model('Clientes_model');

        if (mb_strlen($nova) < 8 || mb_strlen($nova) > 72) {
            $this->respostaInvalida(['senha_nova' => 'A senha deve ter de 8 a 72 caracteres.']);

            return;
        }

        $confere = $this->limite_login->confirmarSenha($email, $ip, $atual, (string) $cliente->senha);
        if ($confere === Limite_login::SENHA_BLOQUEADA) {
            $this->output->set_header('Retry-After: ' . max(1, $this->limite_login->segundosRestantes('confirmacao', $email, $ip)));
            $this->response([
                'status' => false,
                'message' => Limite_login::MENSAGEM,
            ], REST_Controller::HTTP_TOO_MANY_REQUESTS);

            return;
        }

        if ($confere === Limite_login::SENHA_ERRADA) {
            $this->respostaInvalida(['senha_atual' => 'A senha atual não confere.']);

            return;
        }

        if (senhaConfere($nova, (string) $cliente->senha)) {
            $this->respostaInvalida(['senha_nova' => 'A nova senha precisa ser diferente da atual.']);

            return;
        }

        if (! $this->Clientes_model->registrarTrocaDeSenha((int) $cliente->idClientes, password_hash($nova, PASSWORD_DEFAULT), false)) {
            $this->response([
                'status' => false,
                'message' => 'Não foi possível alterar a senha.',
            ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);

            return;
        }

        $this->load->library('Refresh_token');
        $refresh = $this->refresh_token->emitir((int) $cliente->idClientes, $ip, $this->input->user_agent(), null, 'cliente');
        $atualizado = $this->Clientes_model->getById((int) $cliente->idClientes);

        $this->load->model('Audit_model');
        $this->Audit_model->add([
            'usuario' => $cliente->nomeCliente,
            'tarefa' => 'Cliente alterou a senha pelo APP',
            'data' => date('Y-m-d'),
            'hora' => date('H:i:s'),
            'ip' => $ip,
        ]);

        $this->response([
            'status' => true,
            'message' => 'Senha alterada.',
            'result' => $this->sessaoDoCliente($atualizado, (string) $refresh),
        ], REST_Controller::HTTP_OK);
    }

    /** Corpo JSON da requisição como array. */
    protected function corpoJson(): array
    {
        return (array) json_decode(file_get_contents('php://input'), true);
    }

    private function respostaInvalida(array $erros): void
    {
        $this->response([
            'status' => false,
            'message' => 'Dados inválidos.',
            'erros' => $erros,
        ], REST_Controller::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Par de tokens que o login, o refresh e a troca de senha devolvem.
     *
     * @return array<string, mixed>
     */
    private function sessaoDoCliente(object $cliente, string $refreshToken, ?string $familia = null): array
    {
        $this->load->library('Refresh_token');
        $familia ??= (string) $this->refresh_token->familiaDoToken($refreshToken);

        return [
            'access_token' => $this->accessTokenDoCliente($cliente, $familia),
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->authorization_token->expiresIn(Authorization_Token::TETO_COM_REFRESH),
        ];
    }

    /** Access token do cliente: dura no máximo 1 h e leva a versão (`tv`) e a sessão (`sid`). */
    private function accessTokenDoCliente(object $cliente, string $familia): string
    {
        return $this->authorization_token->generateToken([
            'uid' => (int) $cliente->idClientes,
            'nome' => (string) $cliente->nomeCliente,
            'email' => (string) $cliente->email,
            'type' => REST_Controller::TOKEN_TYPE_CLIENT,
            'tv' => $this->authorization_token->versaoAtual('clientes', 'idClientes', (int) $cliente->idClientes),
            'sid' => $familia,
        ], Authorization_Token::TETO_COM_REFRESH);
    }

    private function check_credentials($email)
    {
        $this->db->where('email', $email);
        $this->db->limit(1);

        return $this->db->get('clientes')->row();
    }
}
