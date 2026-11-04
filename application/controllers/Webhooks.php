<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use Libraries\Gateways\BasePaymentGateway;
use Libraries\Gateways\Exceptions\GatewayRecusouException;

/**
 * Webhooks de pagamento de Efí, Mercado Pago e Asaas.
 *
 * Não estende MY_Controller nem os controllers da API: o gateway chama sem sessão,
 * com a API ligada ou desligada, e as rotas estão em csrf_exclude_uris. Todo
 * método público é uma rota do CodeIgniter, então só os três endpoints são
 * públicos; o resto é protected/private.
 *
 * Ordem de segurança: autentica o gateway (hash_equals) antes de qualquer
 * consulta ou escrita, nunca confia em status, valor ou identificação do corpo
 * (reconsulta o pagamento no provedor), é idempotente por evento e só abre a
 * transação da baixa depois da chamada HTTP ao provedor.
 */
class Webhooks extends CI_Controller
{
    public const LIMITE_CORPO = 65536;

    public const GATEWAYS = ['efi' => 'GerencianetSdk', 'mercadopago' => 'MercadoPago', 'asaas' => 'Asaas'];

    /** @var Webhooks_model */
    private $modelo;

    /** @var Limite_requisicoes */
    private $limite;

    /** @var callable(): int */
    private $relogio = 'time';

    /** @var (callable(string $gateway, string $idExterno): array)|null Dublê de consulta, injetado só nos testes */
    private $consultor = null; // @phpstan-ignore property.unusedType

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Webhooks_model');
        $this->load->library('Limite_requisicoes');
        $this->modelo = $this->Webhooks_model;
        $this->limite = $this->limite_requisicoes;
    }

    public function efi($segredo = null): void
    {
        $this->emitir($this->atender('efi', $this->montarRequisicao($segredo === null ? null : (string) $segredo)));
    }

    public function mercadopago(): void
    {
        $this->emitir($this->atender('mercadopago', $this->montarRequisicao(null)));
    }

    public function asaas(): void
    {
        $this->emitir($this->atender('asaas', $this->montarRequisicao(null)));
    }

    /**
     * @param array{metodo: string, ip: string, corpo: ?string, headers: array<string,string>, query: array, segredo_path: ?string} $requisicao
     *        corpo null = acima de LIMITE_CORPO; headers com chaves em minúsculas
     *
     * @return array{status: int, corpo: ?array, headers: array<string,string>}
     */
    protected function atender(string $gateway, array $requisicao): array
    {
        $inicio = microtime(true);
        $resposta = $this->decidir($gateway, $requisicao, $eventoId, $cobrancaId);

        $resultado = $resposta['corpo']['status'] ?? '-';
        $resultado = match ($resposta['status']) {
            401, 413 => 'recusado',
            503 => 'falha',
            default => $resultado,
        };
        $linha = sprintf(
            'webhook gateway=%s evento=%s cobranca=%s resultado=%s http=%d duracao_ms=%d',
            $gateway,
            $eventoId ?? '-',
            $cobrancaId ?? '-',
            $resultado,
            $resposta['status'],
            (int) round((microtime(true) - $inicio) * 1000)
        );
        log_message(in_array($resposta['status'], [200, 405, 429], true) && $resultado !== 'divergente' ? 'info' : 'error', $linha);

        return $resposta;
    }

    private function decidir(string $gateway, array $requisicao, ?string &$eventoId, ?int &$cobrancaId): array
    {
        $eventoId = null;
        $cobrancaId = null;

        if ($requisicao['metodo'] !== 'POST') {
            return $this->responder(405, null, ['Allow' => 'POST']);
        }

        $espera = $this->limite->segundosParaLiberar('webhook', (string) $requisicao['ip'], (int) ($_ENV['WEBHOOK_RATE_LIMIT'] ?? 120));
        if ($espera > 0) {
            return $this->responder(429, null, ['Retry-After' => (string) $espera]);
        }

        if ($requisicao['corpo'] === null) {
            return $this->responder(413, ['status' => 'recusado']);
        }

        if (! $this->autenticar($gateway, $requisicao)) {
            return $this->responder(401, ['status' => 'recusado']);
        }

        $evento = $this->extrairEvento($gateway, $requisicao);
        if ($evento === null) {
            return $this->responder(200, ['status' => 'ignorado']);
        }
        $eventoId = $evento['evento_id'];

        return $this->processar($gateway, $evento['evento_id'], $evento['id_externo'], $requisicao, $cobrancaId);
    }

    private function processar(string $gateway, string $eventoId, string $idExterno, array $requisicao, ?int &$cobrancaId): array
    {
        $corpo = (string) $requisicao['corpo'];
        $payloadHash = hash('sha256', $corpo);
        $paymentGateway = self::GATEWAYS[$gateway];

        try {
            $evento = $this->modelo->buscarEvento($gateway, $eventoId);
            if ($evento !== null && $evento->processado_em !== null) {
                return $this->responder(200, ['status' => 'repetido']);
            }

            try {
                $pagamento = $this->consultarPagamento($gateway, $idExterno);
            } catch (GatewayRecusouException $e) {
                return $this->registrarEResponder($gateway, $eventoId, $payloadHash, null, 'ignorado');
            }

            $cobranca = $this->modelo->cobrancaPorCharge((string) $pagamento['charge_id'], $paymentGateway);
            if ($cobranca === null) {
                return $this->registrarEResponder($gateway, $eventoId, $payloadHash, null, 'ignorado');
            }
            $cobrancaId = (int) $cobranca->idCobranca;

            if ((int) $pagamento['valor_centavos'] !== (int) $cobranca->total) {
                log_message('error', "webhook alerta divergencia gateway={$gateway} cobranca={$cobrancaId}");

                return $this->registrarEResponder($gateway, $eventoId, $payloadHash, $cobrancaId, 'divergente');
            }

            $status = (string) $pagamento['status'];
            $resultado = $this->modelo->aplicarPagamento(
                $gateway,
                $eventoId,
                $payloadHash,
                $cobranca,
                $status,
                BasePaymentGateway::statusPago($paymentGateway, $status)
            );
        } catch (\Throwable $e) {
            log_message('error', 'webhook gateway=' . $gateway . ' erro=' . get_class($e));

            return $this->responder(503, ['status' => 'tente_novamente']);
        }

        if ($resultado === 'falha') {
            return $this->responder(503, ['status' => 'tente_novamente']);
        }

        if ($resultado === 'aplicado') {
            $this->modelo->auditar('Pagamento confirmado por webhook. Cobrança ' . $cobrancaId, $gateway, (string) $requisicao['ip']);
        }

        return $this->responder(200, ['status' => $resultado]);
    }

    private function registrarEResponder(string $gateway, string $eventoId, string $payloadHash, ?int $cobrancaId, string $resultado): array
    {
        if (! $this->modelo->registrarResultado($gateway, $eventoId, $payloadHash, $cobrancaId, $resultado)) {
            return $this->responder(503, ['status' => 'tente_novamente']);
        }

        return $this->responder(200, ['status' => $resultado]);
    }

    private function consultarPagamento(string $gateway, string $idExterno): array
    {
        if ($this->consultor !== null) {
            return ($this->consultor)($gateway, $idExterno);
        }

        $this->load->library('Gateways/' . self::GATEWAYS[$gateway], null, 'gatewayDoWebhook');

        return $this->gatewayDoWebhook->consultarPagamento($idExterno);
    }

    private function autenticar(string $gateway, array $requisicao): bool
    {
        $variavel = ['efi' => 'EFI_WEBHOOK_SECRET', 'mercadopago' => 'MP_WEBHOOK_SECRET', 'asaas' => 'ASAAS_WEBHOOK_TOKEN'][$gateway];
        $segredo = trim((string) ($_ENV[$variavel] ?? ''));
        if ($segredo === '') {
            log_message('error', "webhook desabilitado gateway={$gateway}");

            return false;
        }

        return match ($gateway) {
            'efi' => hash_equals($segredo, (string) $requisicao['segredo_path']),
            'asaas' => hash_equals($segredo, (string) ($requisicao['headers']['asaas-access-token'] ?? '')),
            default => $this->assinaturaMercadoPagoValida($segredo, $requisicao),
        };
    }

    private function assinaturaMercadoPagoValida(string $segredo, array $requisicao): bool
    {
        $partes = [];
        foreach (explode(',', (string) ($requisicao['headers']['x-signature'] ?? '')) as $parte) {
            [$chave, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');
            $partes[$chave] = $valor;
        }
        $ts = $partes['ts'] ?? '';
        $v1 = $partes['v1'] ?? '';
        $requestId = (string) ($requisicao['headers']['x-request-id'] ?? '');
        if ($ts === '' || $v1 === '' || ! ctype_digit($ts) || $requestId === '') {
            return false;
        }

        $segundos = strlen($ts) > 12 ? intdiv((int) $ts, 1000) : (int) $ts; // ts pode vir em milissegundos
        if (abs(($this->relogio)() - $segundos) > 300) {
            return false;
        }

        $corpo = json_decode((string) $requisicao['corpo'], true);
        $dataId = (string) ($requisicao['query']['data_id'] ?? ($corpo['data']['id'] ?? ''));
        if (ctype_alnum($dataId)) {
            $dataId = strtolower($dataId);
        }
        $manifesto = "id:{$dataId};request-id:{$requestId};ts:{$ts};"; // ts exatamente como veio no header

        return hash_equals(hash_hmac('sha256', $manifesto, $segredo), $v1);
    }

    /** @return array{evento_id: string, id_externo: string}|null null = notificação irrelevante */
    private function extrairEvento(string $gateway, array $requisicao): ?array
    {
        $corpo = (string) $requisicao['corpo'];

        if ($gateway === 'efi') {
            parse_str($corpo, $post);
            $notificacao = (string) ($post['notification'] ?? '');

            return $notificacao === '' ? null : $this->evento($notificacao, $notificacao);
        }

        $json = json_decode($corpo, true);
        $json = is_array($json) ? $json : [];

        if ($gateway === 'mercadopago') {
            if ((string) ($json['type'] ?? $requisicao['query']['type'] ?? '') !== 'payment') {
                return null;
            }
            $idExterno = (string) ($requisicao['query']['data_id'] ?? ($json['data']['id'] ?? ''));
            if ($idExterno === '') {
                return null;
            }

            return $this->evento((string) ($json['id'] ?? $requisicao['headers']['x-request-id'] ?? ''), $idExterno);
        }

        $idExterno = (string) ($json['payment']['id'] ?? '');
        if ($idExterno === '') {
            return null;
        }

        return $this->evento((string) ($json['id'] ?? (($json['event'] ?? '') . ':' . $idExterno)), $idExterno);
    }

    /** @return array{evento_id: string, id_externo: string} */
    private function evento(string $eventoId, string $idExterno): array
    {
        return ['evento_id' => mb_substr($eventoId, 0, 128), 'id_externo' => $idExterno];
    }

    /** @return array{metodo: string, ip: string, corpo: ?string, headers: array<string,string>, query: array, segredo_path: ?string} */
    private function montarRequisicao(?string $segredoPath): array
    {
        $bruto = (string) file_get_contents('php://input', false, null, 0, self::LIMITE_CORPO + 1);

        return [
            'metodo' => strtoupper((string) $this->input->method()),
            'ip' => (string) $this->input->ip_address(),
            'corpo' => strlen($bruto) > self::LIMITE_CORPO ? null : $bruto,
            'headers' => array_change_key_case((array) $this->input->request_headers(), CASE_LOWER),
            'query' => (array) $this->input->get(),
            'segredo_path' => $segredoPath,
        ];
    }

    /** @param array<string,string> $headers */
    private function responder(int $status, ?array $corpo, array $headers = []): array
    {
        return ['status' => $status, 'corpo' => $corpo, 'headers' => $headers];
    }

    private function emitir(array $resposta): void
    {
        $this->output->set_status_header($resposta['status'])->set_content_type('application/json')->set_header('Cache-Control: no-store');
        foreach ($resposta['headers'] as $nome => $valor) {
            $this->output->set_header("{$nome}: {$valor}");
        }
        if ($resposta['corpo'] !== null) {
            $this->output->set_output(json_encode($resposta['corpo']));
        }
    }
}
