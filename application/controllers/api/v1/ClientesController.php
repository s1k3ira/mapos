<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . '/libraries/REST_Controller.php'; // @phpstan-ignore requireOnce.fileNotFound

class ClientesController extends REST_Controller
{
    /** Campos que o integrador pode enviar; senha_trocar, versões e asaas_id nunca vêm do corpo. */
    private const CAMPOS = ['nomeCliente', 'contato', 'documento', 'telefone', 'celular', 'email', 'rua', 'numero', 'complemento', 'bairro', 'cidade', 'estado', 'cep'];

    public function __construct()
    {
        parent::__construct();

        $this->load->model('clientes_model');
        $this->load->helper(['validation_helper', 'api', 'clientes']);
    }

    public function index_get($id = '')
    {
        $this->exigirPermissao('vCliente', 'Você não está autorizado a Visualizar Clientes');

        if (! $id) {
            $search = trim((string) $this->get('search', true));

            [$limite, $offset] = $this->paginacaoDaApi();

            $clientes = $this->clientes_model->get('clientes', '*', $search, $limite, $offset);

            if ($clientes) {
                $this->response([
                    'status' => true,
                    'message' => 'Lista de Clientes',
                    'result' => apiSemSenha($clientes),
                ], REST_Controller::HTTP_OK);
            }
        }

        if ($id && is_numeric($id)) {
            $cliente = $this->clientes_model->getById($id);

            if ($cliente) {
                $cliente->ordensServicos = $this->clientes_model->getOsByCliente($id);
                $this->response([
                    'status' => true,
                    'message' => 'Detalhes do Cliente',
                    'result' => apiSemSenha($cliente),
                ], REST_Controller::HTTP_OK);
            }

            $this->response([
                'status' => false,
                'message' => 'Nenhum cliente localizado com esse ID.',
                'result' => null,
            ], REST_Controller::HTTP_OK);
        }

        $this->response([
            'status' => false,
            'message' => 'Nenhum cliente localizado.',
            'result' => null,
        ], REST_Controller::HTTP_OK);
    }

    public function index_post()
    {
        $this->exigirPermissao('aCliente', 'Você não está autorizado a Adicionar Clientes!');

        $_POST = (array) json_decode(file_get_contents('php://input'), true);

        $this->load->library('form_validation');

        if ($this->form_validation->run('clientes') == false) {
            $this->response([
                'status' => false,
                'message' => validation_errors(),
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $corpo = [];
        foreach ([...self::CAMPOS, 'senha'] as $campo) {
            $corpo[$campo] = (string) ($this->post($campo, true) ?? '');
        }

        $senhaGerada = trim($corpo['senha']) === '' ? clienteSenhaAleatoria() : null;
        $cpf_cnpj = preg_replace('/[^\p{L}\p{N}\s]/', '', $corpo['documento']);

        $data = array_diff_key($corpo, ['senha' => true]) + [
            'pessoa_fisica' => strlen($cpf_cnpj) == 11,
            'senha' => password_hash($senhaGerada ?? $corpo['senha'], PASSWORD_DEFAULT),
            'senha_trocar' => 1,
            'dataCadastro' => date('Y-m-d'),
            'fornecedor' => $this->post('fornecedor', true) == true ? 1 : 0,
        ];

        if ($idCliente = $this->clientes_model->add('clientes', $data)) {
            $this->log_app('Adicionou um cliente. ID: ' . $idCliente);

            $cliente = $this->clientes_model->getById($idCliente);
            $result = (array) apiSemSenha($cliente);

            if ($senhaGerada !== null) {
                $result['senha_inicial'] = $senhaGerada;
                $result['email_enviado'] = $this->enviarSenhaInicial($cliente, $senhaGerada);
            }

            $this->output->set_header('Cache-Control: no-store');
            $this->response([
                'status' => true,
                'message' => 'Cliente adicionado com sucesso!',
                'result' => $result,
            ], REST_Controller::HTTP_CREATED);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível adicionar o Cliente.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function index_put($id)
    {
        $this->exigirPermissao('eCliente', 'Você não está autorizado a Editar Clientes!');

        if ($this->put('documento', true) && ! verific_cpf_cnpj($this->put('documento', true))) {
            $this->response([
                'status' => false,
                'message' => 'CPF/CNPJ inválido. Verifique o número do documento e tente novamente.',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        if (! $this->clientes_model->getById($id)) {
            $this->response([
                'status' => false,
                'message' => 'Cliente não encontrado.',
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        $data = [];
        foreach (self::CAMPOS as $campo) {
            $data[$campo] = $this->put($campo, true);
        }
        $data['fornecedor'] = $this->put('fornecedor', true) == true ? 1 : 0;

        if ($this->clientes_model->edit('clientes', $data, 'idClientes', $id) == true) {
            $senha = (string) ($this->put('senha', true) ?? '');
            if (trim($senha) !== '' && ! $this->clientes_model->registrarTrocaDeSenha((int) $id, password_hash($senha, PASSWORD_DEFAULT), true)) {
                $this->response([
                    'status' => false,
                    'message' => 'Não foi possível alterar a senha do cliente.',
                ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
            }

            $this->response([
                'status' => true,
                'message' => 'Cliente editado com sucesso!',
                'result' => apiSemSenha($this->clientes_model->getById($id)),
            ], REST_Controller::HTTP_OK);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível editar o Cliente.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function index_delete($id)
    {
        $this->exigirPermissao('dCliente', 'Você não está autorizado a Apagar Clientes!');

        if (! ctype_digit((string) $id) || (int) $id <= 0) {
            $this->response([
                'status' => false,
                'message' => 'Informe o ID do cliente!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        if (! $this->clientes_model->getById($id)) {
            $this->response([
                'status' => false,
                'message' => 'Cliente não encontrado.',
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        if (! $this->clientes_model->excluirComDependentes((int) $id)) {
            $this->response([
                'status' => false,
                'message' => 'Não foi possível excluir. Nada foi alterado.',
            ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->log_app('Removeu um cliente. ID: ' . $id);
        $this->response([
            'status' => true,
            'message' => 'Cliente excluído com sucesso!',
        ], REST_Controller::HTTP_OK);
    }

    protected function enviarSenhaInicial(object $cliente, string $senha): bool
    {
        $this->load->model('mapos_model');
        $this->load->library('email');
        $emitente = $this->mapos_model->getEmitente();
        $html = $this->load->view('clientes/emails/senha_inicial', [
            'cliente' => $cliente,
            'emitente' => $emitente,
            'senha' => $senha,
            'urlAcesso' => site_url('mine'),
        ], true);

        return clienteEnviarSenhaInicial($this->email, $emitente, $cliente, $html);
    }
}
