<?php

use Efi\EfiPay;
use Efi\Exception\EfiException;
use Libraries\Gateways\BasePaymentGateway;
use Libraries\Gateways\Contracts\PaymentGateway;
use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;

class GerencianetSdk extends BasePaymentGateway
{
    /** @var Gerencianet */
    private $gerenciaNetApi;

    private $gerenciaNetConfig;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->config('payment_gateways');
        $this->ci->load->model('Os_model');
        $this->ci->load->model('vendas_model');
        $this->ci->load->model('cobrancas_model');
        $this->ci->load->model('mapos_model');
        $this->ci->load->model('email_model');

        $gerenciaNetConfig = $this->ci->config->item('payment_gateways')['GerencianetSdk'];
        $this->gerenciaNetConfig = $gerenciaNetConfig;
        $this->gerenciaNetApi = new EfiPay([
            'client_id' => $gerenciaNetConfig['credentials']['client_id'],
            'client_secret' => $gerenciaNetConfig['credentials']['client_secret'],
            'sandbox' => $gerenciaNetConfig['production'] !== true,
            'timeout' => $gerenciaNetConfig['timeout'],
        ]);
    }

    protected function nomeDoGateway(): string
    {
        return 'GerencianetSdk';
    }

    protected function codigoHttp(\Throwable $e): ?int
    {
        return $e instanceof EfiException || $e instanceof \UnexpectedValueException ? (int) $e->getCode() : null;
    }

    protected function traduzirFalha(\Throwable $e, string $operacao): \RuntimeException
    {
        if ($e instanceof EfiException) {
            $status = (int) $e->getCode();
            if ($status >= 400 && $status < 500 && ! in_array($status, [401, 403, 429], true)) {
                return new GatewayRecusouException(
                    in_array($operacao, ['criar_boleto', 'criar_link'], true)
                        ? 'A Efí recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.'
                        : 'A Efí recusou a operação. Confira os dados e tente novamente.',
                    $e
                );
            }
        }

        return new GatewayIndisponivelException(GatewayIndisponivelException::MENSAGEM, $e);
    }

    /** Chama o SDK; resposta com code diferente de 200 vira indisponível, guardando o code para o log. */
    private function chamarEfi(string $operacao, $idCobranca, callable $chamada)
    {
        return $this->chamarGateway($operacao, $idCobranca, function () use ($chamada) {
            $resposta = $chamada();
            if (intval($resposta['code'] ?? 0) !== 200) {
                throw new GatewayIndisponivelException(
                    GatewayIndisponivelException::MENSAGEM,
                    new \UnexpectedValueException('code', (int) ($resposta['code'] ?? 0))
                );
            }

            return $resposta;
        });
    }

    /**
     * Reconsulta no provedor a cobrança de uma notificação (webhook): o status vem do detailCharge.
     *
     * @return array{charge_id: string, status: string, valor_centavos: int}
     *
     * @throws GatewayRecusouException|GatewayIndisponivelException
     */
    public function consultarPagamento(string $idExterno): array
    {
        $notificacao = $this->chamarEfi('consultar_webhook', null, fn () => $this->gerenciaNetApi->getNotification(['token' => $idExterno]));

        $chargeId = null;
        foreach (array_reverse((array) ($notificacao['data'] ?? [])) as $item) {
            $id = $item['identifiers']['charge_id'] ?? null;
            if ($id !== null && $id !== '') {
                $chargeId = $id;
                break;
            }
        }
        if ($chargeId === null) {
            throw new GatewayRecusouException('Notificação da Efí sem cobrança.');
        }

        $cobranca = $this->chamarEfi('consultar_webhook', null, fn () => $this->gerenciaNetApi->detailCharge(['id' => $chargeId], []));
        $dados = $cobranca['data'] ?? [];

        return [
            'charge_id' => (string) ($dados['charge_id'] ?? $chargeId),
            'status' => (string) ($dados['status'] ?? ''),
            'valor_centavos' => (int) ($dados['total'] ?? 0),
        ];
    }

    private function descricaoDaCobranca($id, string $tipo): string
    {
        return $tipo === PaymentGateway::PAYMENT_TYPE_OS ? "OS #$id" : "Venda #$id";
    }

    /** Corpo com metadata só quando há segredo de webhook configurado. */
    private function comNotificationUrl(array $corpo): array
    {
        $url = $this->urlDeNotificacao('EFI_WEBHOOK_SECRET', 'webhooks/efi/' . trim((string) ($_ENV['EFI_WEBHOOK_SECRET'] ?? '')));
        if ($url !== null) {
            $corpo['metadata'] = ['notification_url' => $url];
        }

        return $corpo;
    }

    public function cancelar($id)
    {
        $cobranca = $this->ci->cobrancas_model->getById($id);
        if (! $cobranca) {
            throw new \Exception('Cobrança não existe!');
        }

        $this->chamarEfi('cancelar', $id, fn () => $this->gerenciaNetApi->cancelCharge(['id' => $cobranca->charge_id], []));

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

        $result = $this->chamarEfi('atualizar', $id, fn () => $this->gerenciaNetApi->detailCharge(['id' => $cobranca->charge_id], []));

        // Cobrança foi paga ou foi confirmada de forma manual, então damos baixa
        if ($result['data']['status'] == 'paid' || $result['data']['status'] == 'settled') {
            // TODO: dar baixa no lançamento caso exista
        }

        $databaseResult = $this->ci->cobrancas_model->edit(
            'cobrancas',
            [
                'status' => $result['data']['status'],
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

        $this->chamarEfi('confirmar', $id, fn () => $this->gerenciaNetApi->settleCharge(['id' => $cobranca->charge_id], []));

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

        $address = [
            'street' => $entity->rua,
            'number' => $entity->numero,
            'neighborhood' => $entity->bairro,
            'zipcode' => preg_replace('/[^0-9]/', '', (string) ($entity->cep ?? '')),
            'city' => $entity->cidade,
            'complement' => $entity->complemento,
            'state' => $entity->estado,
        ];

        $documento = preg_replace('/[^0-9]/', '', (string) ($entity->documento ?? ''));
        $telefone = preg_replace('/[^0-9]/', '', (string) ($entity->telefone ?? ''));
        if (strlen($documento) == 11) {
            $customer = [
                'name' => $entity->nomeCliente,
                'cpf' => $documento,
                'phone_number' => $telefone,
                'email' => $entity->email,
                'address' => $address,
            ];
        } else {
            $customer = [
                'juridical_person' => [
                    'corporate_name' => $entity->nomeCliente,
                    'cnpj' => $documento,
                ],
                'phone_number' => $telefone,
                'email' => $entity->email,
                'address' => $address,
            ];
        }
        $expirationDate = (new DateTime())->add(new DateInterval($this->gerenciaNetConfig['boleto_expiration']));
        $expirationDate = ($expirationDate->format('Y-m-d'));
        $body = [
            'items' => [
                [
                    'name' => $this->descricaoDaCobranca($id, $tipo),
                    'amount' => 1,
                    'value' => getMoneyAsCents($valor),
                ],
            ],
            'payment' => [
                'banking_billet' => [
                    'expire_at' => $expirationDate,
                    'message' => 'Pago em qualquer loterica\nPagar até o vencimento\nCaixa após vencimento não aceitar',
                    'customer' => $customer,
                ],
            ],
        ];
        $body = $this->comNotificationUrl($body);

        $result = $this->chamarEfi('criar_boleto', null, fn () => $this->gerenciaNetApi->createOneStepCharge([], $body));

        $data = [
            'barcode' => $result['data']['barcode'],
            'link' => $result['data']['link'],
            'pdf' => $result['data']['pdf']['charge'],
            'expire_at' => $result['data']['expire_at'],
            'charge_id' => $result['data']['charge_id'],
            'status' => $result['data']['status'],
            'total' => getMoneyAsCents($valor),
            'payment' => $result['data']['payment'],
            'clientes_id' => $entity->idClientes,
            'payment_method' => 'boleto',
            'payment_gateway' => 'GerencianetSdk',
        ];

        if ($tipo === PaymentGateway::PAYMENT_TYPE_OS) {
            $data['os_id'] = $id;
        } else {
            $data['vendas_id'] = $id;
        }

        if ($id = $this->ci->cobrancas_model->add('cobrancas', $data, true)) {
            $data['idCobranca'] = $id;
            log_info('Cobrança criada com successo. ID: ' . $result['data']['charge_id']);
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

        $expirationDate = (new DateTime())->add(new DateInterval('P3D'));
        $expirationDate = ($expirationDate->format('Y-m-d'));
        $title = $this->descricaoDaCobranca($id, $tipo);

        $items = [
            [
                'name' => $title,
                'amount' => 1,
                'value' => getMoneyAsCents($valor),
            ],
        ];

        $settings = [
            "payment_method" => "all",
            "expire_at" => $expirationDate,
            "request_delivery_address" => false,
            'message' => 'Pagamento referente a ' . $title,
        ];

        $body = $this->comNotificationUrl([
            "items" => $items,
            "settings" => $settings,
        ]);

        $result = $this->chamarEfi('criar_link', null, fn () => $this->gerenciaNetApi->createOneStepLink([], $body));

        $data = [
            'expire_at' => $result['data']['expire_at'],
            'charge_id' => $result['data']['charge_id'],
            'status' => $result['data']['status'],
            'total' => getMoneyAsCents($valor),
            'clientes_id' => $entity->idClientes,
            'payment_method' => 'link',
            'payment_gateway' => 'GerencianetSdk',
            'payment_url' => $result['data']['payment_url'],
            'link' => $result['data']['payment_url'],
            'message' => $result['data']['message'],
        ];

        if ($tipo === PaymentGateway::PAYMENT_TYPE_OS) {
            $data['os_id'] = $id;
        } else {
            $data['vendas_id'] = $id;
        }

        if ($id = $this->ci->cobrancas_model->add('cobrancas', $data, true)) {
            $data['idCobranca'] = $id;
            log_info('Cobrança criada com successo. ID: ' . $result['data']['charge_id']);
        } else {
            throw new \Exception('Erro ao salvar cobrança!');
        }

        return $data;
    }
}
