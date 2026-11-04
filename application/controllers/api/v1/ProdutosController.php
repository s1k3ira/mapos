<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . '/libraries/REST_Controller.php'; // @phpstan-ignore requireOnce.fileNotFound

class ProdutosController extends REST_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('produtos_model');
        $this->load->helper('validation_helper');
    }

    public function index_get($id = '')
    {
        $this->exigirPermissao('vProduto', 'Você não está autorizado a Visualizar Produtos');

        if (! $id) {
            $search = trim((string) $this->get('search', true));

            [$limite, $offset] = $this->paginacaoDaApi();

            $produtos = $this->produtos_model->get('produtos', '*', $search, $limite, $offset);

            $this->response([
                'status' => true,
                'message' => 'Listando Produtos',
                'result' => $produtos,
            ], REST_Controller::HTTP_OK);
        }

        if ($id && is_numeric($id)) {
            $produto = $this->produtos_model->getById($id);

            $this->response([
                'status' => true,
                'message' => 'Detalhes do Produto',
                'result' => $produto,
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
        $this->exigirPermissao('aProduto', 'Você não está autorizado a Adicionar Produtos!');

        $_POST = (array) json_decode(file_get_contents('php://input'), true);

        $this->load->library('form_validation');

        if ($this->form_validation->run('produtos') == false) {
            $this->response([
                'status' => false,
                'message' => validation_errors(),
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $data = $this->dadosDoProduto(fn (string $campo) => $this->post($campo, true));

        if ($this->produtos_model->add('produtos', $data)) {
            $this->response([
                'status' => true,
                'message' => 'Produto adicionado com sucesso!',
                'result' => $this->produtos_model->getById((int) $this->db->insert_id()),
            ], REST_Controller::HTTP_CREATED);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível adicionar o Produto. Avise ao Administrador.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function index_put($id)
    {
        $this->exigirPermissao('eProduto', 'Você não está autorizado a Editar Produtos!');

        if (! $id) {
            $this->response([
                'status' => false,
                'message' => 'Informe o ID do produto!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        if (! $this->put('descricao', true) ||
        ! $this->put('unidade', true) ||
        ! $this->put('precoCompra', true) ||
        ! $this->put('precoVenda', true) ||
        ! $this->put('estoque', true)) {
            $this->response([
                'status' => false,
                'message' => 'Preencha todos os campos obrigatórios!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $data = $this->dadosDoProduto(fn (string $campo) => $this->put($campo, true));

        if ($this->produtos_model->edit('produtos', $data, 'idProdutos', $id)) {
            $this->response([
                'status' => true,
                'message' => 'Produto editado com sucesso!',
                'result' => $this->produtos_model->getById($id),
            ], REST_Controller::HTTP_OK);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível editar o Produto. Avise ao Administrador.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function index_delete($id)
    {
        $this->exigirPermissao('dProduto', 'Você não está autorizado a Apagar Produtos!');

        if (! $id) {
            $this->response([
                'status' => false,
                'message' => 'Informe o ID do Produto!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        $id = (int) $id;
        if ($id <= 0) {
            $this->response([
                'status' => false,
                'message' => 'Informe o ID do Produto!',
            ], REST_Controller::HTTP_BAD_REQUEST);
        }

        if (! $this->produtos_model->getById($id)) {
            $this->response([
                'status' => false,
                'message' => 'Produto não encontrado.',
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        if ($this->produtos_model->excluirComDependentes($id)) {
            $this->log_app('Removeu um Produto. ID' . $id);
            $this->response([
                'status' => true,
                'message' => 'Produto excluído com sucesso!',
            ], REST_Controller::HTTP_OK);
        }

        $this->response([
            'status' => false,
            'message' => 'Não foi possível excluir. Nada foi alterado.',
        ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Allow-list de colunas do produto, igual no POST e no PUT: o id e o que
     * mais vier no corpo nunca chegam ao banco. $campo lê o corpo da requisição.
     *
     * @param  callable(string): mixed  $campo
     * @return array<string, mixed>
     */
    private function dadosDoProduto(callable $campo): array
    {
        return [
            'codDeBarra' => $campo('codDeBarra') ?: 0,
            'descricao' => $campo('descricao'),
            'unidade' => $campo('unidade'),
            'precoCompra' => str_replace(',', '', (string) ($campo('precoCompra') ?? '')),
            'precoVenda' => str_replace(',', '', (string) ($campo('precoVenda') ?? '')),
            'estoque' => $campo('estoque'),
            'estoqueMinimo' => $campo('estoqueMinimo') ?: 0,
            'saida' => $campo('saida') ?: 0,
            'entrada' => $campo('entrada') ?: 0,
        ];
    }
}
