<?php

use CodePhix\Asaas\Asaas as AsaasSdk;
use Libraries\Gateways\AsaasConexao;
use Libraries\Gateways\BasePaymentGateway;
use Libraries\Gateways\Contracts\PaymentGateway;
use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;

class Asaas extends BasePaymentGateway
{
    /** @var AsaasSdk */
    private $asaasApi;

    private $asaasConfig;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->config('payment_gateways');
        $this->ci->load->model('Os_model');
        $this->ci->load->model('vendas_model');
        $this->ci->load->model('cobrancas_model');
        $this->ci->load->model('mapos_model');
        $this->ci->load->model('email_model');
        $this->ci->load->model('clientes_model');

        $asaasConfig = $this->ci->config->item('payment_gateways')['Asaas'];
        $this->asaasConfig = $asaasConfig;
        $this->asaasApi = new AsaasSdk(
            $asaasConfig['credentials']['api_key'],
            $asaasConfig['production'] === true ? 'producao' : 'homologacao'
        );
        $this->configurarTimeout((int) ($asaasConfig['timeout'] ?? 10));
    }

    /** Troca a conexão do SDK por uma com tempo máximo de conexão e de resposta. */
    protected function configurarTimeout(int $segundos): void
    {
        $conexao = new AsaasConexao(
            $this->asaasApi->Cobranca()->http->api_key,
            $this->asaasApi->Cobranca()->http->api_status ? 'homologacao' : 'producao',
            $segundos
        );
        (new \ReflectionProperty(AsaasSdk::class, 'connection'))->setValue($this->asaasApi, $conexao);
    }

    protected function nomeDoGateway(): string
    {
        return 'Asaas';
    }

    /** Resposta do SDK: errors = recusa do provedor; falso ou erro de transporte = indisponível. */
    private function conferirResposta($resposta, string $operacao)
    {
        if (! $resposta || ! empty($resposta->error)) {
            throw new GatewayIndisponivelException();
        }
        if (! empty($resposta->errors)) {
            throw new GatewayRecusouException(
                in_array($operacao, ['criar_boleto', 'criar_link', 'criar_cliente'], true)
                    ? 'O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.'
                    : 'O Asaas recusou a operação. Confira os dados e tente novamente.'
            );
        }

        return $resposta;
    }

    /** Chama o SDK, confere o retorno e devolve o objeto da resposta. */
    private function chamarAsaas(string $operacao, $idCobranca, callable $chamada)
    {
        return $this->chamarGateway($operacao, $idCobranca, fn () => $this->conferirResposta($chamada(), $operacao));
    }

    private function descricaoDaCobranca($id, string $tipo): string
    {
        return $tipo === PaymentGateway::PAYMENT_TYPE_OS ? "OS #$id" : "Venda #$id";
    }

    public function cancelar($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (! $cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        if ($cobranca->payment_method == 'boleto') {
            $this->chamarAsaas('cancelar', $id, fn () => $this->asaasApi->Cobranca()->delete($cobranca->charge_id));
        } else {
            $this->chamarAsaas('cancelar', $id, fn () => $this->asaasApi->LinkPagamento()->delete($cobranca->charge_id));
        }

        return $this->atualizarDados($id);
    }

    public function enviarPorEmail($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (! $cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        $emitente = $this->ci->mapos_model->getEmitente();
        if (! $emitente) {
            throw new \Exception('Emitente não configurado!');
        }

        $html = $this->ci->load->view(
            'cobrancas/emails/cobranca',
            [
                'cobranca' => $cobranca,
                'emitente' => $emitente,
                'paymentGatewaysConfig' => $this->ci->config->item('payment_gateways'),
            ],
            true
        );

        $assunto = 'Cobrança - ' . $emitente->nome;
        if ($cobranca->os_id) {
            $assunto .= ' - OS #' . $cobranca->os_id;
        } else {
            $assunto .= ' - Venda #' . $cobranca->vendas_id;
        }

        $remetentes = [$cobranca->email];
        foreach ($remetentes as $remetente) {
            $headers = [
                'From' => $emitente->email,
                'Subject' => $assunto,
                'Return-Path' => '',
            ];
            $email = [
                'to' => $remetente,
                'message' => $html,
                'status' => 'pending',
                'date' => date('Y-m-d H:i:s'),
                'headers' => json_encode($headers),
            ];
            $this->ci->email_model->add('email_queue', $email);
        }
    }

    public function atualizarDados($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (! $cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        if ($cobranca->payment_method == 'boleto') {
            $result = $this->chamarAsaas('atualizar', $id, fn () => $this->asaasApi->Cobranca()->getById($cobranca->charge_id));
        } else {
            throw new Exception('Devido à limitação da Asaas, somente é possível atualizar cobranças com boletos!');
        }

        // Verifica se a cobrança já foi deletada
        if ($result->deleted) {
            // Atribui o valor DELETED ao status para atualizar o status no banco de dados
            $result->status = 'DELETED';
        }
        
        // Cobrança foi paga ou foi confirmada de forma manual, então damos baixa
        if ($result->status == 'RECEIVED' || $result->status == 'CONFIRMED' || $result->status == 'DUNNING_RECEIVED') {
            // TODO: dar baixa no lançamento caso exista
        }

        $databaseResult = $this->ci->cobrancas_model->edit(
            'cobrancas',
            [
                'status' => $result->status,
            ],
            'idCobranca',
            $id
        );

        if ($databaseResult == true) {
            $this->ci->session->set_flashdata('success', 'Cobrança atualizada com sucesso!');
            log_info('Alterou um status de cobrança. ID' . $id);
        } else {
            $this->ci->session->set_flashdata('error', 'Erro ao atualizar cobrança!');
            throw new \Exception('Erro ao atualizar cobrança!');
        }
    }

    public function confirmarPagamento($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (! $cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        if ($cobranca->payment_method == 'boleto') {
            $this->chamarAsaas('confirmar', $id, fn () => $this->asaasApi->Cobranca()->confirmacao(
                $cobranca->charge_id,
                [
                    'paymentDate' => (new DateTime())->format('Y-m-d'),
                    'value' => round($cobranca->total / 100, 2),
                ]
            ));
        } else {
            throw new Exception('Devido à limitação da Asaas, somente é possível confirmar cobranças com boletos!');
        }

        return $this->atualizarDados($id);
    }

    protected function gerarCobrancaBoleto($id, $tipo)
    {
        $entity = $this->findEntity($id, $tipo);

        if (empty($entity)) {
            throw new \Exception('OS ou venda não existe!');
        }

        $valor = $this->valorDaCobranca($entity, $tipo);

        if ($valor <= 0) {
            throw new \Exception('OS ou venda com valor negativo ou zero!');
        }

        if ($err = $this->errosCadastro($entity)) {
            throw new \Exception($err);
        }

        $expirationDate = (new DateTime())->add(new DateInterval($this->asaasConfig['boleto_expiration']));
        $expirationDate = ($expirationDate->format('Y-m-d'));
        $body = [
            'customer' => $this->criarOuRetornarClienteAsaasId($entity->clientes_id),
            'billingType' => 'BOLETO',
            'dueDate' => $expirationDate,
            'value' => $valor,
            'description' => $this->descricaoDaCobranca($id, $tipo),
            'externalReference' => $id,
            'postalService' => false,
        ];

        $result = $this->chamarAsaas('criar_boleto', null, fn () => $this->asaasApi->Cobranca()->create($body));

        $title = $this->descricaoDaCobranca($id, $tipo);
        $data = [
            'barcode' => '',
            'link' => $result->invoiceUrl,
            'payment_url' => $result->invoiceUrl,
            'pdf' => $result->bankSlipUrl,
            'expire_at' => $result->dueDate,
            'charge_id' => $result->id,
            'status' => $result->status,
            'total' => getMoneyAsCents($valor),
            'payment' => $result->billingType,
            'clientes_id' => $entity->idClientes,
            'payment_method' => 'boleto',
            'payment_gateway' => 'Asaas',
            'message' => 'Pagamento referente a ' . $title,
        ];

        if ($tipo === PaymentGateway::PAYMENT_TYPE_OS) {
            $data['os_id'] = $id;
        } else {
            $data['vendas_id'] = $id;
        }

        if ($id = $this->ci->cobrancas_model->add('cobrancas', $data, true)) {
            $data['idCobranca'] = $id;
            log_info('Cobrança criada com successo. ID: ' . $result->id);
        } else {
            throw new \Exception('Erro ao salvar cobrança!');
        }

        return $data;
    }

    protected function gerarCobrancaLink($id, $tipo)
    {
        $entity = $this->findEntity($id, $tipo);

        if (empty($entity)) {
            throw new \Exception('OS ou venda não existe!');
        }

        $valor = $this->valorDaCobranca($entity, $tipo);

        if ($valor <= 0) {
            throw new \Exception('OS ou venda com valor negativo ou zero!');
        }

        if ($err = $this->errosCadastro($entity)) {
            throw new \Exception($err);
        }

        $expirationDate = (new DateTime())->add(new DateInterval($this->asaasConfig['boleto_expiration']));
        $expirationDate = ($expirationDate->format('Y-m-d'));
        $body = [
            'name' => $this->descricaoDaCobranca($id, $tipo),
            'description' => $this->descricaoDaCobranca($id, $tipo),
            'endDate' => $expirationDate,
            'value' => $valor,
            'billingType' => 'UNDEFINED',
            'chargeType' => 'DETACHED',
            'dueDateLimitDays' => preg_replace('/[^0-9]/', '', $this->asaasConfig['boleto_expiration']),
            'subscriptionCycle' => null,
            'maxInstallmentCount' => 1,
        ];

        $result = $this->chamarAsaas('criar_link', null, fn () => $this->asaasApi->LinkPagamento()->create($body));

        $title = $this->descricaoDaCobranca($id, $tipo);
        $data = [
            'expire_at' => $result->endDate,
            'charge_id' => $result->id,
            'status' => 'PENDING',
            'total' => getMoneyAsCents($valor),
            'clientes_id' => $entity->idClientes,
            'payment_method' => 'link',
            'payment_gateway' => 'Asaas',
            'payment_url' => $result->url,
            'link' => $result->url,
            'message' => $result->description,
            'message' => 'Pagamento referente a ' . $title,
        ];

        if ($tipo === PaymentGateway::PAYMENT_TYPE_OS) {
            $data['os_id'] = $id;
        } else {
            $data['vendas_id'] = $id;
        }

        if ($id = $this->ci->cobrancas_model->add('cobrancas', $data, true)) {
            $data['idCobranca'] = $id;
            log_info('Cobrança criada com successo. ID: ' . $result->id);
        } else {
            throw new \Exception('Erro ao salvar cobrança!');
        }

        return $data;
    }


    private function criarOuRetornarClienteAsaasId($clienteId)
    {
        $cliente = (array) $this->ci->clientes_model->getById($clienteId);
        if (! $cliente) {
            throw new Exception('Cliente não encontrado: ' . $clienteId);
        }

        if (isset($cliente['asaas_id'])) {
            return $cliente['asaas_id'];
        }

        $result = $this->chamarAsaas('criar_cliente', null, fn () => $this->asaasApi->Cliente()->create([
            'name' => $cliente['nomeCliente'],
            'email' => $cliente['email'],
            'phone' => preg_replace('/[^0-9]/', '', (string) ($cliente['telefone'] ?? '')),
            'mobilePhone' => preg_replace('/[^0-9]/', '', (string) ($cliente['celular'] ?? '')),
            'cpfCnpj' => preg_replace('/[^0-9]/', '', (string) ($cliente['documento'] ?? '')),
            'postalCode' => $cliente['cep'],
            'address' => $cliente['rua'],
            'addressNumber' => $cliente['numero'],
            'complement' => $cliente['complemento'],
            'province' => $cliente['bairro'],
            'city' => $cliente['cidade'],
            'state' => $cliente['estado'],
            'country' => 'Brasil',
            'externalReference' => $clienteId,
            'notificationDisabled' => $this->asaasConfig['notify'] === false,
            'observations' => '',
            'groupName' => 'mapos',
        ]));

        $success = $this->ci->clientes_model->edit(
            'clientes',
            [
                'asaas_id' => $result->id,
            ],
            'idClientes',
            $clienteId
        );

        if ($success) {
            return $result->id;
        } else {
            throw new Exception('Erro ao criar cliente na Asaas!');
        }
    }
}
