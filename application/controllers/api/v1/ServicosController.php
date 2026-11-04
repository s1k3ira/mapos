<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

require_once APPPATH . 'libraries/REST_Controller.php'; // @phpstan-ignore requireOnce.fileNotFound

class ServicosController extends REST_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('servicos_model');
    }

    public function index_get($id = '')
    {
        $this->exigirPermissao('vServico', 'Você não está autorizado a Visualizar Serviços');

        if (! $id) {
            $search = trim((string) $this->get('search', true));

            [$limite, $offset] = $this->paginacaoDaApi();

            $servicos = $this->servicos_model->get('servicos', '*', $search, $limite, $offset);

            $this->response([
                'status' => true,
                'message' => 'Listando Serviços',
                'result' => $servicos,
            ], REST_Controller::HTTP_OK);
        }

        if ($id && is_numeric($id)) {
            $servico = $this->servicos_model->getById($id);

            $this->response([
                'status' => true,
                'message' => 'Detalhes do Serviço',
                'result' => $servico,
            ], REST_Controller::HTTP_OK);
        }

        $this->response([
            'status' => false,
            'message' => 'Nenhum Produto localizado.',
            'result' => null,
        ], REST_Controller::HTTP_OK);
    }

    public function index_post()
    {
        $this->exigirPermissao('aServico', 'Você não está autorizado a Adicionar Serviços!');

        $_POST = (array) json_decode(file_get_contents('php://input'), true);

        $this->load->library('form_validation');

        if ($this->form_validation->run('servicos') == false) {
            $this->response([
                'status' => false,
                'message' => validation_errors(),
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $data = $this->dadosDoServico(fn (string $campo) => $this->post($campo, true));

        if ($this->servicos_model->add('servicos', $data)) {
            $this->response([
                'status' => true,
                'message' => 'Serviço adicionado com sucesso!',
                'result' => $this->servicos_model->getById((int) $this->db->insert_id()),
            ], REST_Controller::HTTP_CREATED);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível adicionar o Serviço. Avise ao Administrador.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function index_put($id)
    {
        $this->exigirPermissao('eServico', 'Você não está autorizado a Editar Serviços!');

        if (! $id) {
            $this->response([
                'status' => false,
                'message' => 'Informe o ID do Serviço!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $inputData = json_decode(trim(file_get_contents('php://input')));

        if (! $this->put('nome', true) || ! $this->put('preco', true)) {
            $this->response([
                'status' => false,
                'message' => 'Preencha todos os campos obrigatórios!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $data = $this->dadosDoServico(fn (string $campo) => $this->put($campo, true));

        if ($this->servicos_model->edit('servicos', $data, 'idServicos', $id)) {
            $this->response([
                'status' => true,
                'message' => 'Serviço editado com sucesso!',
                'result' => $this->servicos_model->getById($id),
            ], REST_Controller::HTTP_OK);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível editar o Serviço. Avise ao Administrador.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function index_delete($id)
    {
        $this->exigirPermissao('dServico', 'Você não está autorizado a Apagar Serviços!');

        if (! $id) {
            $this->response([
                'status' => false,
                'message' => 'Informe o ID do Serviço!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $id = (int) $id;
        if ($id <= 0) {
            $this->response([
                'status' => false,
                'message' => 'Informe o ID do Serviço!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        if (! $this->servicos_model->getById($id)) {
            $this->response([
                'status' => false,
                'message' => 'Serviço não encontrado.',
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        if ($this->servicos_model->excluirComDependentes($id)) {
            $this->log_app('Removeu um Serviço. ID' . $id);
            $this->response([
                'status' => true,
                'message' => 'Serviço excluído com sucesso!',
            ], REST_Controller::HTTP_OK);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível excluir. Nada foi alterado.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Allow-list de colunas do serviço, igual no POST e no PUT: o id e o que
     * mais vier no corpo nunca chegam ao banco. $campo lê o corpo da requisição.
     *
     * @param  callable(string): mixed  $campo
     * @return array<string, mixed>
     */
    private function dadosDoServico(callable $campo): array
    {
        return [
            'nome' => $campo('nome'),
            'descricao' => $campo('descricao') ?: '',
            'preco' => str_replace(',', '', (string) ($campo('preco') ?? '')),
        ];
    }
}
