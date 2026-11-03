<?php

use Libraries\Gateways\BasePaymentGateway;
use Libraries\Gateways\Contracts\PaymentGateway;
use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;
use Libraries\Gateways\MercadoPagoHttpClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;

class MercadoPago extends BasePaymentGateway
{
    private $mercadoPagoConfig;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->config('payment_gateways');
        $this->ci->load->model('Os_model');
        $this->ci->load->model('vendas_model');
        $this->ci->load->model('cobrancas_model');
        $this->ci->load->model('mapos_model');
        $this->ci->load->model('email_model');

        $this->mercadoPagoConfig = $this->ci->config->item('payment_gateways')['MercadoPago'];

        MercadoPagoConfig::setAccessToken($this->mercadoPagoConfig['credentials']['access_token']);
        $this->configurarTimeout((int) ($this->mercadoPagoConfig['timeout'] ?? 10));
    }

    protected function configurarTimeout(int $segundos): void
    {
        MercadoPagoConfig::setConnectionTimeout($segundos * 1000);
        MercadoPagoConfig::setHttpClient(new MercadoPagoHttpClient($segundos));
    }

    protected function nomeDoGateway(): string
    {
        return 'MercadoPago';
    }

    protected function codigoHttp(\Throwable $e): ?int
    {
        return $e instanceof MPApiException ? $e->getStatusCode() : null;
    }

    protected function traduzirFalha(\Throwable $e, string $operacao): \RuntimeException
    {
        if ($e instanceof MPApiException) {
            $status = $e->getStatusCode();
            if ($status >= 400 && $status < 500 && ! in_array($status, [401, 403, 429], true)) {
                return new GatewayRecusouException($this->mpErrorMessage($e, $operacao), $e);
            }
        }

        return new GatewayIndisponivelException(GatewayIndisponivelException::MENSAGEM, $e);
    }

    public function cancelar($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (!$cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        $this->chamarGateway('cancelar', $id, fn () => (new PaymentClient())->cancel($cobranca->charge_id));

        return $this->atualizarDados($id);
    }

    public function enviarPorEmail($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (!$cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        $emitente = $this->ci->mapos_model->getEmitente();
        if (!$emitente) {
            throw new \Exception('Emitente não configurado!');
        }

        $html = $this->ci->load->view(
            'cobrancas/emails/cobranca',
            [
                'cobranca' => $cobranca,
                'emitente' => $emitente[0],
                'paymentGatewaysConfig' => $this->ci->config->item('payment_gateways'),
            ],
            true
        );

        $assunto = 'Cobrança - ' . $emitente[0]->nome;
        if ($cobranca->os_id) {
            $assunto .= ' - OS #' . $cobranca->os_id;
        } else {
            $assunto .= ' - Venda #' . $cobranca->vendas_id;
        }

        $remetentes = [$cobranca->email];
        foreach ($remetentes as $remetente) {
            $headers = [
                'From' => $emitente[0]->email,
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
        if (!$cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        $payment = $this->chamarGateway('atualizar', $id, fn () => (new PaymentClient())->get($cobranca->charge_id));

        $this->ci->cobrancas_model->edit(
            'cobrancas',
            ['status' => $payment->status],
            'idCobranca',
            $id
        );

        $this->ci->session->set_flashdata('success', 'Cobrança atualizada com sucesso!');
        log_info('Atualizou cobrança Mercado Pago. ID: ' . $id);
    }

    /** Só textos nossos: a mensagem do provedor pode conter dado do pagador. */
    private function mpErrorMessage(\Throwable $e, string $operacao): string
    {
        if ($e instanceof MPApiException && $e->getStatusCode() === 404) {
            return 'Cobrança não encontrada no Mercado Pago.';
        }

        if ($operacao === 'criar_boleto') {
            return 'O Mercado Pago recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.';
        }

        return 'O Mercado Pago recusou a operação. Confira os dados e tente novamente.';
    }

    public function confirmarPagamento($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (!$cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        $client = new PaymentClient();
        $payment = $this->chamarGateway('confirmar', $id, fn () => $client->get($cobranca->charge_id));

        if ($payment->status === 'approved' || $payment->payment_method_id === 'bolbradesco') {
            $this->atualizarDados($id);

            return $payment;
        }

        if ($payment->status !== 'authorized') {
            $erro = new GatewayRecusouException('O pagamento não está em um estado que permite captura.');
            $this->registrarFalha('confirmar', $id, null, microtime(true), $erro);

            throw $erro;
        }

        $valor = isset($cobranca->total) ? (float) $cobranca->total : 0;
        if ($valor > 1000) {
            $valor /= 100;
        }

        $payment = $this->chamarGateway('confirmar', $id, fn () => $client->capture((int) $cobranca->charge_id, (float) $valor));

        $this->atualizarDados($id);

        return $payment;
    }

    protected function gerarCobrancaBoleto($id, $tipo)
    {
        $entity = $this->findEntity($id, $tipo);

        if (empty($entity)) {
            throw new \Exception('OS ou venda não existe!');
        }

        $valor = $this->valorDaCobranca($entity, $tipo);

        if ($valor <= 0) {
            throw new \Exception('Valor inválido!');
        }

        if ($err = $this->errosCadastro($entity)) {
            throw new \Exception($err);
        }

        $expirationDate = (new DateTime())->add(new DateInterval($this->mercadoPagoConfig['boleto_expiration']))->format(DateTime::RFC3339_EXTENDED);
        $dados = $this->dadosDoBoleto($entity, $id, $tipo, $valor, (string) ($entity->documento ?? ''), $expirationDate);

        $payment = $this->chamarGateway('criar_boleto', null, fn () => (new PaymentClient())->create($dados));

        $data = [
            'barcode' => $payment->barcode->content ?? '',
            'link' => $payment->transaction_details->external_resource_url ?? '',
            'pdf' => $payment->transaction_details->external_resource_url ?? '',
            'expire_at' => $payment->date_of_expiration ?? $expirationDate,
            'charge_id' => $payment->id,
            'status' => $payment->status,
            'total' => getMoneyAsCents($valor),
            'clientes_id' => $entity->idClientes,
            'payment_method' => 'boleto',
            'payment_gateway' => 'MercadoPago',
        ];

        if ($tipo === PaymentGateway::PAYMENT_TYPE_OS) {
            $data['os_id'] = $id;
        } else {
            $data['vendas_id'] = $id;
        }

        if ($id = $this->ci->cobrancas_model->add('cobrancas', $data, true)) {
            $data['idCobranca'] = $id;
            log_info('Cobrança criada com sucesso. ID: ' . $payment->id);
        } else {
            throw new \Exception('Erro ao salvar cobrança!');
        }

        return $data;
    }

    protected function dadosDoBoleto(object $entity, $id, string $tipo, float $valor, string $documento, string $expiracao): array
    {
        $clientNameParts = explode(' ', (string) ($entity->nomeCliente ?? ''));
        $documento = preg_replace('/[^0-9]/', '', $documento);
        $rotulo = match ($tipo) {
            PaymentGateway::PAYMENT_TYPE_OS => 'OS',
            default => 'Venda',
        };

        $dados = [
            'transaction_amount' => $valor,
            'description' => "$rotulo #$id",
            'payment_method_id' => 'bolbradesco',
            'date_of_expiration' => $expiracao,
            'payer' => [
                'email' => $entity->email ?? '',
                'first_name' => $clientNameParts[0],
                'last_name' => end($clientNameParts),
                'identification' => [
                    'type' => strlen($documento) == 11 ? 'CPF' : 'CNPJ',
                    'number' => $documento,
                ],
                'address' => [
                    'zip_code' => preg_replace('/[^0-9]/', '', (string) ($entity->cep ?? '')),
                    'street_name' => $entity->rua ?? '',
                    'street_number' => $entity->numero ?? '',
                    'neighborhood' => $entity->bairro ?? '',
                    'city' => $entity->cidade ?? '',
                    'federal_unit' => $entity->estado ?? '',
                ],
            ],
        ];

        $notificacao = $this->urlDeNotificacao('MP_WEBHOOK_SECRET', 'webhooks/mercadopago');
        if ($notificacao !== null) {
            $dados['notification_url'] = $notificacao;
        }

        return $dados;
    }

    protected function gerarCobrancaLink($id, $tipo)
    {
        throw new \Exception('Mercado Pago não suporta gerar link pela API, apenas pelo painel!');
    }

}
