<?php

use Libraries\Gateways\Contracts\PaymentGateway;
use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;
use Libraries\Gateways\MercadoPagoHttpClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPResponse;
use PHPUnit\Framework\Attributes\DataProvider;

if (! function_exists('site_url')) {
    function site_url($uri = '')
    {
        return 'http://mapos.test/index.php/' . ltrim((string) $uri, '/');
    }
}

require_once APPPATH . 'libraries/Gateways/MercadoPago.php';

/**
 * Mercado Pago: falhas viram exceções tipadas com mensagem nossa, log sem dado
 * pessoal, descrição correta da cobrança, notification_url própria e timeout.
 */
final class GatewayMercadoPagoTest extends MaposTestCase
{
    private const GENERICA = 'O provedor de pagamento não respondeu. Tente novamente em instantes.';

    private array $envOriginal = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['MP_WEBHOOK_SECRET', 'PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT'] as $chave) {
            $this->envOriginal[$chave] = $_ENV[$chave] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envOriginal as $chave => $valor) {
            if ($valor === null) {
                unset($_ENV[$chave]);
            } else {
                $_ENV[$chave] = $valor;
            }
        }
        parent::tearDown();
    }

    private function gateway(): MercadoPago
    {
        $gateway = $this->makeInstance(MercadoPago::class);
        $this->setPrivateProperty($gateway, 'ci', (object) []);
        $this->setPrivateProperty($gateway, 'mercadoPagoConfig', ['boleto_expiration' => 'P3D', 'timeout' => 10]);

        return $gateway;
    }

    private function falhar(string $operacao, $id, \Throwable $erro): void
    {
        $this->invokeMethod($this->gateway(), 'chamarGateway', [$operacao, $id, static fn () => throw $erro]);
    }

    private function api(int $status, array $conteudo = ['message' => 'invalid payer']): MPApiException
    {
        return new MPApiException('Api error', new MPResponse($status, $conteudo));
    }

    public function testRecusaComConteudoArrayViraMensagemStringSemTypeError(): void
    {
        try {
            $this->falhar('criar_boleto', null, $this->api(400, ['message' => 'invalid payer', 'cause' => [['code' => 2067]]]));
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('O Mercado Pago recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=criar_boleto gateway=MercadoPago cobranca=- http=400');
        $this->assertLogNaoContem('invalid payer');
    }

    public function testRecusaDeOutraOperacaoUsaMensagemGenerica(): void
    {
        try {
            $this->falhar('cancelar', 5, $this->api(422));
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('O Mercado Pago recusou a operação. Confira os dados e tente novamente.', $e->getMessage());
        }
    }

    public function testCobrancaInexistenteViraRecusaDeNaoEncontrada(): void
    {
        try {
            $this->falhar('atualizar', 7, $this->api(404));
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('Cobrança não encontrada no Mercado Pago.', $e->getMessage());
        }
    }

    public static function falhasTecnicas(): array
    {
        return [
            '500' => [500], '502' => [502], '503' => [503],
            '429' => [429], '401' => [401], '403' => [403],
        ];
    }

    #[DataProvider('falhasTecnicas')]
    public function testFalhaTecnicaDaApiViraIndisponivel(int $status): void
    {
        try {
            $this->falhar('criar_boleto', null, $this->api($status));
            $this->fail('Deveria ficar indisponível.');
        } catch (GatewayIndisponivelException $e) {
            $this->assertSame(self::GENERICA, $e->getMessage());
        }
    }

    public function testFalhaDeRedeSemStatusViraIndisponivel(): void
    {
        try {
            $this->falhar('atualizar', 3, new \Exception('cURL error 28'));
            $this->fail('Deveria ficar indisponível.');
        } catch (GatewayIndisponivelException $e) {
            $this->assertSame(self::GENERICA, $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=atualizar gateway=MercadoPago cobranca=3 http=- ');
        $this->assertLogNaoContem('cURL error 28');
    }

    public function testLogDaFalhaNaoTemDadoPessoalNemCorpoDoProvedor(): void
    {
        try {
            $this->falhar('criar_boleto', null, $this->api(400, ['message' => 'cpf 123.456.789-09 joao@example.com TEST-token']));
        } catch (GatewayRecusouException) {
        }

        $logs = $this->logs('error');
        $this->assertCount(1, $logs);
        $this->assertMatchesRegularExpression(
            '/^gateway op=criar_boleto gateway=MercadoPago cobranca=- http=400 duracao_ms=\d+ erro=MercadoPago\\\\Exceptions\\\\MPApiException$/',
            $logs[0]
        );
    }

    public function testChamadaComSucessoDevolveORetornoSemLog(): void
    {
        $retorno = $this->invokeMethod($this->gateway(), 'chamarGateway', ['atualizar', 1, static fn () => 'ok']);

        $this->assertSame('ok', $retorno);
        $this->assertSame([], $this->logs('error'));
    }

    public function testNaoExisteCatchQueLanceExceptionComConteudoCruDaApi(): void
    {
        $fonte = file_get_contents(APPPATH . 'libraries/Gateways/MercadoPago.php');

        $this->assertSame(0, preg_match('/new \\\\Exception\(\$e->getApiResponse\(\)/', $fonte));
    }

    private function entidade(): object
    {
        return (object) [
            'nomeCliente' => 'Ana Maria Souza', 'email' => 'ana@example.com', 'documento' => '123.456.789-09',
            'cep' => '01001-000', 'rua' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'SP', 'estado' => 'SP',
        ];
    }

    private function dadosDoBoleto(string $tipo, int $id): array
    {
        return $this->invokeMethod($this->gateway(), 'dadosDoBoleto', [$this->entidade(), $id, $tipo, 50.0, '123.456.789-09', '2026-01-01T00:00:00.000-03:00']);
    }

    public function testDescricaoDaVendaEVenda(): void
    {
        $this->assertSame('Venda #31', $this->dadosDoBoleto(PaymentGateway::PAYMENT_TYPE_VENDAS, 31)['description']);
    }

    public function testDescricaoDaOsEOs(): void
    {
        $this->assertSame('OS #12', $this->dadosDoBoleto(PaymentGateway::PAYMENT_TYPE_OS, 12)['description']);
    }

    public function testDadosPreservamOsDemaisCampos(): void
    {
        $dados = $this->dadosDoBoleto(PaymentGateway::PAYMENT_TYPE_OS, 12);

        $this->assertSame('CPF', $dados['payer']['identification']['type']);
        $this->assertSame('12345678909', $dados['payer']['identification']['number']);
        $this->assertSame('01001000', $dados['payer']['address']['zip_code']);
        $this->assertSame('Souza', $dados['payer']['last_name']);
    }

    public function testNotificationUrlAusenteSemSegredo(): void
    {
        unset($_ENV['MP_WEBHOOK_SECRET']);
        $this->assertArrayNotHasKey('notification_url', $this->dadosDoBoleto(PaymentGateway::PAYMENT_TYPE_OS, 1));

        $_ENV['MP_WEBHOOK_SECRET'] = '';
        $this->assertArrayNotHasKey('notification_url', $this->dadosDoBoleto(PaymentGateway::PAYMENT_TYPE_OS, 1));
    }

    public function testNotificationUrlComSegredo(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'abc';

        $this->assertSame(site_url('webhooks/mercadopago'), $this->dadosDoBoleto(PaymentGateway::PAYMENT_TYPE_OS, 1)['notification_url']);
    }

    private function timeoutDoConfig(?string $valor): int
    {
        if ($valor === null) {
            unset($_ENV['PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT']);
        } else {
            $_ENV['PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT'] = $valor;
        }
        $config = [];
        require APPPATH . 'config/payment_gateways.php';

        return $config['payment_gateways']['MercadoPago']['timeout'];
    }

    public function testTimeoutDoConfigUsaPadraoDezParaValorInvalido(): void
    {
        foreach ([null, '', '0', 'abc'] as $valor) {
            $this->assertSame(10, $this->timeoutDoConfig($valor), 'Valor: ' . var_export($valor, true));
        }
    }

    public function testTimeoutDoConfigLeOValorInformado(): void
    {
        $this->assertSame(25, $this->timeoutDoConfig('25'));
    }

    public function testTimeoutDoClienteFicaConfiguradoComConexaoEResposta(): void
    {
        $this->invokeMethod($this->gateway(), 'configurarTimeout', [25]);

        $cliente = MercadoPagoConfig::getHttpClient();
        $this->assertInstanceOf(MercadoPagoHttpClient::class, $cliente);
        $this->assertSame(25, $cliente->getTimeout());
        $this->assertSame(25000, MercadoPagoConfig::getConnectionTimeout());

        $curl = (new ReflectionProperty(MercadoPago\Net\MPDefaultHttpClient::class, 'httpRequest'))->getValue($cliente);
        $this->assertSame(25, $this->readPrivateProperty($curl, 'segundos'));
    }
}
