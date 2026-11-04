<?php

use Efi\Exception\EfiException;
use Libraries\Gateways\BasePaymentGateway;
use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResponse;
use PHPUnit\Framework\Attributes\DataProvider;

if (! function_exists('site_url')) {
    function site_url($uri = '')
    {
        return 'http://mapos.test/index.php/' . ltrim((string) $uri, '/');
    }
}

if (! function_exists('log_info')) {
    function log_info($task)
    {
    }
}

require_once APPPATH . 'libraries/Gateways/MercadoPago.php';
require_once APPPATH . 'libraries/Gateways/GerencianetSdk.php';
require_once APPPATH . 'libraries/Gateways/Asaas.php';

/** Consulta do pagamento no provedor (usada pelos webhooks): formato comum, falhas tipadas e status pagos. */
final class GatewayConsultaPagamentoTest extends MaposTestCase
{
    private function mp(callable $busca): MercadoPago
    {
        $gateway = new class($busca) extends MercadoPago {
            public int $chamadas = 0;

            public function __construct(private $busca)
            {
            }

            protected function buscarPagamentoMp(int $id): object
            {
                $this->chamadas++;

                return ($this->busca)($id);
            }
        };

        return $gateway;
    }

    private function sdk(callable $tratador): object
    {
        return new class($tratador) {
            public array $chamadas = [];

            public function __construct(private $tratador)
            {
            }

            public function Cobranca()
            {
                $dono = $this;

                return new class($dono) {
                    public function __construct(private object $dono)
                    {
                    }

                    public function __call($metodo, $args)
                    {
                        return $this->dono->registrar('Cobranca.' . $metodo, $args);
                    }
                };
            }

            public function __call($metodo, $args)
            {
                return $this->registrar($metodo, $args);
            }

            public function registrar(string $metodo, array $args)
            {
                $this->chamadas[] = [$metodo, $args];

                return ($this->tratador)($metodo, $args);
            }
        };
    }

    private function efi(callable $tratador): array
    {
        $gateway = $this->makeInstance(GerencianetSdk::class);
        $sdk = $this->sdk($tratador);
        $this->setPrivateProperty($gateway, 'gerenciaNetApi', $sdk);

        return [$gateway, $sdk];
    }

    private function asaas(callable $tratador): Asaas
    {
        $gateway = $this->makeInstance(Asaas::class);
        $this->setPrivateProperty($gateway, 'asaasApi', $this->sdk($tratador));

        return $gateway;
    }

    private function api(int $status): MPApiException
    {
        return new MPApiException('Api error', new MPResponse($status, ['message' => 'x']));
    }

    public function testMercadoPagoDevolveOFormatoComumComValorEmCentavos(): void
    {
        $gateway = $this->mp(fn (int $id) => (object) ['id' => $id, 'status' => 'approved', 'transaction_amount' => 150.9]);

        $this->assertSame(
            ['charge_id' => '123456', 'status' => 'approved', 'valor_centavos' => 15090],
            $gateway->consultarPagamento('123456')
        );
    }

    public function testMercadoPagoArredondaCentavos(): void
    {
        $gateway = $this->mp(fn (int $id) => (object) ['id' => $id, 'status' => 'approved', 'transaction_amount' => 0.1]);

        $this->assertSame(10, $gateway->consultarPagamento('7')['valor_centavos']);
    }

    public function testMercadoPagoIdNaoNumericoRecusaSemConsultar(): void
    {
        $gateway = $this->mp(fn (int $id) => (object) []);

        try {
            $gateway->consultarPagamento('abc');
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('Pagamento não encontrado no Mercado Pago.', $e->getMessage());
        }
        $this->assertSame(0, $gateway->chamadas);
    }

    public function testMercadoPagoFalhasDaConsulta(): void
    {
        $recusa = $this->mp(fn (int $id) => throw $this->api(404));
        try {
            $recusa->consultarPagamento('9');
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('Cobrança não encontrada no Mercado Pago.', $e->getMessage());
        }
        $this->assertLogContem('error', 'gateway op=consultar_webhook gateway=MercadoPago cobranca=- http=404');

        $indisponivel = $this->mp(fn (int $id) => throw $this->api(500));
        try {
            $indisponivel->consultarPagamento('9');
            $this->fail('Deveria ficar indisponível.');
        } catch (GatewayIndisponivelException $e) {
            $this->assertSame(GatewayIndisponivelException::MENSAGEM, $e->getMessage());
        }
        $this->assertLogContem('error', 'gateway op=consultar_webhook gateway=MercadoPago cobranca=- http=500');
    }

    public function testEfiConsultaANotificacaoEDetalhaACobranca(): void
    {
        [$gateway, $sdk] = $this->efi(fn (string $metodo) => $metodo === 'getNotification'
            ? ['code' => 200, 'data' => [
                ['identifiers' => ['charge_id' => 111], 'status' => ['current' => 'waiting']],
                ['identifiers' => ['charge_id' => 111], 'status' => ['current' => 'paid']],
            ]]
            : ['code' => 200, 'data' => ['charge_id' => 111, 'status' => 'paid', 'total' => 5000]]);

        $this->assertSame(
            ['charge_id' => '111', 'status' => 'paid', 'valor_centavos' => 5000],
            $gateway->consultarPagamento('tok-1')
        );
        $this->assertSame([['getNotification', [['token' => 'tok-1']]], ['detailCharge', [['id' => 111], []]]], $sdk->chamadas);
    }

    public function testEfiSemChargeIdNaNotificacaoRecusaSemDetalhar(): void
    {
        [$gateway, $sdk] = $this->efi(fn (string $metodo) => $metodo === 'getNotification'
            ? ['code' => 200, 'data' => [['status' => ['current' => 'paid']]]]
            : throw new LogicException('detailCharge não deveria ser chamado'));

        try {
            $gateway->consultarPagamento('tok-1');
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('Notificação da Efí sem cobrança.', $e->getMessage());
        }
        $this->assertSame(['getNotification'], array_column($sdk->chamadas, 0));
    }

    public function testEfiIndisponivelNaConsulta(): void
    {
        [$gateway] = $this->efi(fn () => ['code' => 500, 'data' => []]);

        $this->expectException(GatewayIndisponivelException::class);
        try {
            $gateway->consultarPagamento('tok-1');
        } finally {
            $this->assertLogContem('error', 'gateway op=consultar_webhook gateway=GerencianetSdk');
            $this->assertLogContem('error', 'http=500');
        }
    }

    public function testAsaasDevolveOFormatoComumEMapeiaFalhas(): void
    {
        $gateway = $this->asaas(fn () => (object) ['id' => 'pay_9', 'status' => 'RECEIVED', 'value' => 150.9]);
        $this->assertSame(
            ['charge_id' => 'pay_9', 'status' => 'RECEIVED', 'valor_centavos' => 15090],
            $gateway->consultarPagamento('pay_9')
        );

        $recusa = $this->asaas(fn () => (object) ['errors' => [(object) ['description' => 'x']]]);
        try {
            $recusa->consultarPagamento('pay_9');
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertInstanceOf(GatewayRecusouException::class, $e);
        }

        $falso = $this->asaas(fn () => false);
        $this->expectException(GatewayIndisponivelException::class);
        $falso->consultarPagamento('pay_9');
    }

    public static function statusProvider(): array
    {
        return [
            ['MercadoPago', 'approved', true],
            ['GerencianetSdk', 'paid', true],
            ['GerencianetSdk', 'settled', true],
            ['Asaas', 'RECEIVED', true],
            ['Asaas', 'CONFIRMED', true],
            ['Asaas', 'RECEIVED_IN_CASH', true],
            ['Asaas', 'DUNNING_RECEIVED', true],
            ['MercadoPago', 'pending', false],
            ['Asaas', 'PENDING', false],
            ['Outro', 'paid', false],
            ['GerencianetSdk', 'PAID', false],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testStatusPago(string $gateway, string $status, bool $esperado): void
    {
        $this->assertSame($esperado, BasePaymentGateway::statusPago($gateway, $status));
    }

    public function testLogDaConsultaNaoTemTokenNemId(): void
    {
        [$efi] = $this->efi(fn () => ['code' => 500, 'data' => []]);
        $asaas = $this->asaas(fn () => false);
        $mp = $this->mp(fn (int $id) => throw $this->api(500));

        foreach ([[$efi, 'tok-secreto-1'], [$asaas, 'pay_secreto_9'], [$mp, '987654321']] as [$gateway, $id]) {
            try {
                $gateway->consultarPagamento($id);
            } catch (GatewayIndisponivelException $e) {
            }
        }

        $this->assertCount(3, $this->logs('error'));
        foreach (['tok-secreto-1', 'pay_secreto_9', '987654321'] as $segredo) {
            $this->assertLogNaoContem($segredo);
        }
    }
}
