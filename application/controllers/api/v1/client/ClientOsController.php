<?php

/*
    Ivan Sarkozin
    https://github.com/sarkozin
*/

defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . '/libraries/REST_Controller.php'; // @phpstan-ignore requireOnce.fileNotFound


class ClientOsController extends REST_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Conecte_model');
        $this->load->helper('os');
        $this->load->helper('conecte');
        $this->load->library('Authorization_Token');
        $this->load->library('format');
        $this->load->model('mapos_model');
        $this->load->model('os_model');
    }

    public function Index_get()
    {
        $clientLogged = $this->logged_client();
        
        $data['os'] = $this->Conecte_model->getLastOs($this->logged_client()->usuario->idClientes);
        $data['compras'] = $this->Conecte_model->getLastCompras($this->logged_client()->usuario->idClientes);
        
        if (empty($data['os'])) {
            $this->response([
                'status' => true,
                'message' => 'Nenhuma ordem de serviço encontrada'
            ], REST_Controller::HTTP_OK);
        } else {
            $this->response([
                'status' => true,
                'message' => 'Listando resultados',
                'result' => [
                    'Os' => $data['os'],
                    'compras' => $data['compras']
                ],
            ], REST_Controller::HTTP_OK);
        }
    }

    public function os_get(int $id = null)
    {
        $clientLogged = $this->logged_client();
        if (! $id) {
            [$limite, $offset] = $this->paginacaoDaApi();
            $data['results'] = $this->Conecte_model->getOs('os', '*', '', $limite, $offset, '', '', $clientLogged->usuario->idClientes);
        
            $this->response([
                'status' => true,
                'message' => 'Listando resultados',
                'result' => [
                    'Os' => $data['results'],
                ],
            ], REST_Controller::HTTP_OK);
        }
        
        $osId = (int) $id;

        $data['pix_key'] = $this->CI->db->get_where('configuracoes', ['config' => 'pix_key'])->row_object()->valor;
        $data['result'] = $this->os_model->getById($osId);
        if (empty($data['result'])) {
            $this->response([
                'status' => false,
                'message' => 'Ordem de serviço não encontrada'
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        // A OS precisa pertencer ao cliente autenticado.
        if ((int) $data['result']->idClientes !== (int) $clientLogged->usuario->idClientes) {
            $this->response([
                'status' => false,
                'message' => 'Ordem de serviço não encontrada'
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        $os = $data['result'];
        $produtos = $this->os_model->getProdutos($osId);
        $servicos = $this->os_model->getServicos($osId);
        $anexos = $this->os_model->getAnexos($osId);
        $emitente = $this->mapos_model->getEmitente();
        $qrCode = $this->os_model->getQrCode(
            $id,
            $data['pix_key'],
            $emitente
        );
        $chaveFormatada = $this->format->formatarChave($data['pix_key']);
        foreach ($produtos as $produto) {
            unset(
                $produto->precoCompra,
                $produto->estoque,
                $produto->estoqueMinimo,
                $produto->saida,
                $produto->entrada
            );
        }

        $anexos = osAnexosParaApi($anexos, site_url('api/v1/client/os/' . (int) $osId . '/anexos'));

        unset($os->senha);

        $data = [
            'pix_key' => $data['pix_key'],
            'os' => $os,
            'produtos' => $produtos,
            'servicos' => $servicos,
            'anexos' => $anexos,
            'emitente' => $emitente,
            'qrCode' => $qrCode,
            'chaveFormatada' => $chaveFormatada,
        ];
        
        $this->response([
            'status' => true,
            'message' => 'Listando resultados',
            'result' => $data,
        ], REST_Controller::HTTP_OK);
    }

    public function os_post()
    {
        $clientLogged = $this->logged_client();
        $idCliente = (int) $clientLogged->usuario->idClientes;
        $corpo = $this->corpoJson();

        $erros = conecteErrosDaNovaOs($corpo);
        if ($erros !== []) {
            $this->response([
                'status' => false,
                'message' => 'Dados inválidos.',
                'erros' => $erros,
            ], REST_Controller::HTTP_UNPROCESSABLE_ENTITY);
            return;
        }

        $usuarioId = $this->Conecte_model->tecnicoParaNovaOs();
        if ($usuarioId === null) {
            $this->response([
                'status' => false,
                'message' => 'Ocorreu um erro ao atribuir a ordem de serviço. Por favor, contate o administrador do sistema.',
            ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        $texto = static fn (string $campo): string => osTextoParaGravar(is_string($corpo[$campo] ?? null) ? $corpo[$campo] : '');
        $data = [
            'dataInicial' => date('Y-m-d'),
            'usuarios_id' => $usuarioId,
            'dataFinal' => date('Y-m-d'),
            'descricaoProduto' => $texto('descricaoProduto'),
            'defeito' => $texto('defeito'),
            'status' => 'Aberto',
            'observacoes' => $texto('observacoes'),
            'faturado' => 0,
        ];

        $resultado = $this->Conecte_model->abrirOsDoCliente($idCliente, $data, clienteOsLimiteDiario());

        if (! $resultado['ok']) {
            if ($resultado['motivo'] === 'limite') {
                $this->output->set_header('Retry-After: ' . segundosAteAmanha($this->agora()));
                $this->response([
                    'status' => false,
                    'message' => Conecte_model::LIMITE_OS_MENSAGEM,
                ], REST_Controller::HTTP_TOO_MANY_REQUESTS);
                return;
            }

            $this->response([
                'status' => false,
                'message' => 'Ocorreu um erro ao criar a ordem de serviço.',
            ], REST_Controller::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        $osId = $resultado['id'];
        $this->load->model('Audit_model');
        $this->Audit_model->add([
            'Usuario' => '[APP]',
            'tarefa' => 'Cliente ' . $clientLogged->usuario->nomeCliente . ' Adicionou uma OS Nº ' . $osId . ' pelo APP',
            'data' => date('Y-m-d'),
            'hora' => date('H:i:s'),
            'ip' => $this->input->ip_address(),
        ]);

        $this->response([
            'status' => true,
            'message' => 'Ordem de serviço criada com sucesso.',
            'os_id' => $osId,
        ], REST_Controller::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    protected function corpoJson(): array
    {
        $dados = json_decode((string) file_get_contents('php://input'), true);

        return is_array($dados) ? $dados : [];
    }

    protected function agora(): int
    {
        return time();
    }

    /** Arquivo (ou miniatura com ?miniatura=1) de um anexo de uma OS do cliente autenticado. */
    public function anexo_get(int $id, int $idAnexo)
    {
        $clientLogged = $this->logged_client();
        $os = $this->os_model->getById($id);

        if (! $os || (int) $os->idClientes !== (int) $clientLogged->usuario->idClientes) {
            $this->response([
                'status' => false,
                'message' => 'Anexo não encontrado',
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        $anexo = $this->os_model->getAnexoDaOs($id, $idAnexo);
        $arquivo = $anexo ? armazenamentoArquivoDoAnexo($anexo, $this->get('miniatura') !== null) : null;

        if ($arquivo === null) {
            $this->response([
                'status' => false,
                'message' => 'Anexo não encontrado',
            ], REST_Controller::HTTP_NOT_FOUND);
        }

        armazenamentoEnviar($arquivo, 'anexo-' . (int) $anexo->idAnexos . '.' . strtolower(pathinfo($arquivo, PATHINFO_EXTENSION)));
    }
}
