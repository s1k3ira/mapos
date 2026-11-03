<?php

use Efi\Exception\EfiException;
use Libraries\Gateways\Contracts\PaymentGateway;
use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;

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

require_once APPPATH . 'libraries/Gateways/Asaas.php';
require_once APPPATH . 'libraries/Gateways/GerencianetSdk.php';

/**
 * Asaas e Efí: falhas viram exceções tipadas com mensagem nossa, log sem dado
 * do provedor, notification_url própria, timeout e descrição da cobrança.
 */
final class GatewayAsaasEfiTest extends MaposTestCase
{
    private const CADASTRO_ASAAS = 'O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.';

    private array $envOriginal = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['EFI_WEBHOOK_SECRET', 'PAYMENT_GATEWAYS_EFI_TIMEOUT', 'PAYMENT_GATEWAYS_ASAAS_TIMEOUT'] as $chave) {
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

    private function entidade(): object
    {
        return (object) [
            'idClientes' => 3, 'clientes_id' => 3, 'nomeCliente' => 'Ana Maria Souza', 'email' => 'ana@example.com',
            'documento' => '123.456.789-09', 'telefone' => '11999998888', 'cep' => '01001-000', 'rua' => 'Rua A',
            'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'SP', 'estado' => 'SP', 'complemento' => '',
        ];
    }

    private function ci(): object
    {
        $entidade = $this->entidade();
        $modelo = new class($entidade) {
            public function __construct(private object $entidade)
            {
            }

            public function getById($id)
            {
                return $this->entidade;
            }

            public function totais($entity): array
            {
                return ['total' => 50.0];
            }
        };
        $cobrancas = new class() {
            public array $adicionadas = [];

            public function getById($id)
            {
                return (object) ['charge_id' => 'CH-' . $id, 'payment_method' => 'boleto', 'total' => 5000];
            }

            public function add($tabela, $dados, $retornaId = false)
            {
                $this->adicionadas[] = $dados;

                return 77;
            }

            public function edit($tabela, $dados, $chave, $id)
            {
                return true;
            }
        };
        $clientes = new class() {
            public function getById($id)
            {
                return (object) ['asaas_id' => 'cus_1'];
            }
        };
        $sessao = new class() {
            public function set_flashdata($chave, $valor)
            {
            }
        };

        return (object) ['Os_model' => $modelo, 'vendas_model' => $modelo, 'cobrancas_model' => $cobrancas, 'clientes_model' => $clientes, 'session' => $sessao];
    }

    /** Dublê do SDK: cada chamada "Recurso.metodo" cai no $tratador, que devolve ou lança. */
    private function sdk(callable $tratador): object
    {
        return new class($tratador) {
            public array $corpos = [];

            public function __construct(private $tratador)
            {
            }

            public function Cobranca()
            {
                return $this->recurso('Cobranca');
            }

            public function LinkPagamento()
            {
                return $this->recurso('LinkPagamento');
            }

            public function Cliente()
            {
                return $this->recurso('Cliente');
            }

            public function __call($metodo, $args)
            {
                $this->corpos[$metodo] = $args[1] ?? ($args[0] ?? null);

                return ($this->tratador)($metodo, $args);
            }

            private function recurso(string $nome): object
            {
                $dono = $this;

                return new class($dono, $nome) {
                    public function __construct(private object $dono, private string $nome)
                    {
                    }

                    public function __call($metodo, $args)
                    {
                        $this->dono->corpos[$this->nome . '.' . $metodo] = $args[0] ?? null;

                        return ($this->dono->tratador())($this->nome . '.' . $metodo, $args);
                    }
                };
            }

            public function tratador(): callable
            {
                return $this->tratador;
            }
        };
    }

    private function asaas(callable $tratador): array
    {
        $gateway = $this->makeInstance(Asaas::class);
        $sdk = $this->sdk($tratador);
        $this->setPrivateProperty($gateway, 'ci', $this->ci());
        $this->setPrivateProperty($gateway, 'asaasApi', $sdk);
        $this->setPrivateProperty($gateway, 'asaasConfig', ['boleto_expiration' => 'P3D', 'notify' => false, 'timeout' => 10]);

        return [$gateway, $sdk];
    }

    private function efi(callable $tratador): array
    {
        $gateway = $this->makeInstance(GerencianetSdk::class);
        $sdk = $this->sdk($tratador);
        $this->setPrivateProperty($gateway, 'ci', $this->ci());
        $this->setPrivateProperty($gateway, 'gerenciaNetApi', $sdk);
        $this->setPrivateProperty($gateway, 'gerenciaNetConfig', ['boleto_expiration' => 'P3D', 'timeout' => 10]);

        return [$gateway, $sdk];
    }

    private function respostaEfi(array $dados = []): array
    {
        return ['code' => 200, 'data' => $dados + [
            'barcode' => 'b', 'link' => 'l', 'pdf' => ['charge' => 'p'], 'expire_at' => '2026-01-01', 'charge_id' => 9,
            'status' => 'new', 'payment' => 'banking_billet', 'payment_url' => 'u', 'message' => 'm',
        ]];
    }

    private function efiException(int $codigo): EfiException
    {
        return new EfiException('CHARGES', ['code' => $codigo, 'error' => 'x', 'error_description' => 'descricao do provedor'], $codigo, []);
    }

    private function asaasQueRecusa(string $descricao): array
    {
        return $this->asaas(static fn () => (object) ['errors' => [(object) ['description' => $descricao]]]);
    }

    private function asaasCobrancaCriada(): object
    {
        return (object) ['id' => 'pay_1', 'invoiceUrl' => 'i', 'bankSlipUrl' => 'b', 'dueDate' => '2026-01-01', 'status' => 'PENDING', 'billingType' => 'BOLETO', 'url' => 'u', 'endDate' => '2026-01-01', 'description' => 'd'];
    }

    // Asaas

    public function testRecusaDoAsaasNaoRepassaOTextoDoProvedor(): void
    {
        [$gateway] = $this->asaasQueRecusa('O CPF/CNPJ informado é inválido.');

        try {
            $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=criar_boleto gateway=Asaas cobranca=- http=-');
        $this->assertLogNaoContem('CPF/CNPJ informado');
    }

    public function testRecusaDoAsaasNoLinkUsaAMensagemDeCadastro(): void
    {
        [$gateway] = $this->asaasQueRecusa('Valor inválido');

        try {
            $this->invokeMethod($gateway, 'gerarCobrancaLink', [12, PaymentGateway::PAYMENT_TYPE_OS]);
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame(self::CADASTRO_ASAAS, $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=criar_link gateway=Asaas');
    }

    public function testRecusaDoAsaasNaConfirmacaoUsaMensagemGenerica(): void
    {
        [$gateway] = $this->asaasQueRecusa('Pagamento já confirmado');

        try {
            $this->invokeMethod($gateway, 'confirmarPagamento', [9]);
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('O Asaas recusou a operação. Confira os dados e tente novamente.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=confirmar gateway=Asaas cobranca=9 ');
        $this->assertLogNaoContem('Pagamento já confirmado');
    }

    public function testAsaasIndisponivelQuandoARetornoEFalso(): void
    {
        [$gateway] = $this->asaas(static fn () => false);

        try {
            $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);
            $this->fail('Deveria ficar indisponível.');
        } catch (GatewayIndisponivelException $e) {
            $this->assertSame('O provedor de pagamento não respondeu. Tente novamente em instantes.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=criar_boleto gateway=Asaas cobranca=- ');
    }

    public function testAsaasIndisponivelQuandoOSdkLancaExcecao(): void
    {
        [$gateway] = $this->asaas(static fn () => throw new \Exception('cURL error 28: timeout'));

        try {
            $this->invokeMethod($gateway, 'atualizarDados', [9]);
            $this->fail('Deveria ficar indisponível.');
        } catch (GatewayIndisponivelException $e) {
            $this->assertSame('O provedor de pagamento não respondeu. Tente novamente em instantes.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=atualizar gateway=Asaas cobranca=9 ');
        $this->assertLogNaoContem('cURL error 28');
    }

    public function testAsaasIndisponivelQuandoOSdkDevolveErroDeTransporte(): void
    {
        [$gateway] = $this->asaas(static fn () => (object) ['error' => [(object) ['description' => 'Tivemos um problema ao processar a requisição.']]]);

        $this->expectException(GatewayIndisponivelException::class);
        $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);
    }

    public function testCriarClienteDoAsaasRecusadoUsaAMensagemDeCadastro(): void
    {
        [$gateway] = $this->asaasQueRecusa('Cliente inválido');
        $ci = $this->ci();
        $ci->clientes_model = new class() {
            public function getById($id)
            {
                return (object) ['nomeCliente' => 'Ana', 'email' => 'a@b.c', 'telefone' => '1', 'celular' => '2', 'documento' => '3', 'cep' => '4', 'rua' => 'r',
                    'numero' => '1', 'complemento' => '', 'bairro' => 'b', 'cidade' => 'c', 'estado' => 'SP'];
            }
        };
        $this->setPrivateProperty($gateway, 'ci', $ci);

        try {
            $this->invokeMethod($gateway, 'criarOuRetornarClienteAsaasId', [3]);
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame(self::CADASTRO_ASAAS, $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=criar_cliente gateway=Asaas');
    }

    public function testAsaasNaoMontaMensagemComADescricaoDoProvedor(): void
    {
        $this->assertSame(0, preg_match('/\$error->description/', file_get_contents(APPPATH . 'libraries/Gateways/Asaas.php')));
    }

    public function testNenhumDominioFixoDeTerceiroNosGateways(): void
    {
        foreach (['Asaas.php', 'GerencianetSdk.php'] as $arquivo) {
            $this->assertSame(0, preg_match('/mapos\.com\.br|seusite\.com\.br/', file_get_contents(APPPATH . 'libraries/Gateways/' . $arquivo)), $arquivo);
        }
    }

    // Efí

    public function testEfiRecusaDadosComExcecao400(): void
    {
        [$gateway] = $this->efi(fn () => throw $this->efiException(400));

        try {
            $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('A Efí recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=criar_boleto gateway=GerencianetSdk cobranca=- http=400 ');
        $this->assertLogNaoContem('descricao do provedor');
    }

    public function testEfiRecusaOutraOperacaoComMensagemGenerica(): void
    {
        [$gateway] = $this->efi(fn () => throw $this->efiException(404));

        try {
            $this->invokeMethod($gateway, 'cancelar', [5]);
            $this->fail('Deveria recusar.');
        } catch (GatewayRecusouException $e) {
            $this->assertSame('A Efí recusou a operação. Confira os dados e tente novamente.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=cancelar gateway=GerencianetSdk cobranca=5 http=404');
    }

    public static function falhasTecnicasDaEfi(): array
    {
        return ['401' => [401], '403' => [403], '429' => [429], '500' => [500], '503' => [503]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('falhasTecnicasDaEfi')]
    public function testEfiFalhaTecnicaViraIndisponivel(int $codigo): void
    {
        [$gateway] = $this->efi(fn () => throw $this->efiException($codigo));

        try {
            $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);
            $this->fail('Deveria ficar indisponível.');
        } catch (GatewayIndisponivelException $e) {
            $this->assertSame('O provedor de pagamento não respondeu. Tente novamente em instantes.', $e->getMessage());
        }

        $this->assertLogContem('error', 'http=' . $codigo . ' ');
    }

    public function testEfiExcecaoDeRedeViraIndisponivelSemHttp(): void
    {
        [$gateway] = $this->efi(fn () => throw new \Exception('cURL error 28', 28));

        $this->expectException(GatewayIndisponivelException::class);
        try {
            $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);
        } finally {
            $this->assertLogContem('error', 'cobranca=- http=- ');
        }
    }

    public function testEfiCodigoDiferenteDe200SemLancarViraIndisponivelComHttp(): void
    {
        [$gateway] = $this->efi(static fn () => ['code' => 500, 'data' => []]);

        try {
            $this->invokeMethod($gateway, 'atualizarDados', [4]);
            $this->fail('Deveria ficar indisponível.');
        } catch (GatewayIndisponivelException $e) {
            $this->assertSame('O provedor de pagamento não respondeu. Tente novamente em instantes.', $e->getMessage());
        }

        $this->assertLogContem('error', 'gateway op=atualizar gateway=GerencianetSdk cobranca=4 http=500 ');
    }

    public function testEfiBoletoComSucessoGravaACobrancaSemLog(): void
    {
        [$gateway] = $this->efi(fn () => $this->respostaEfi());

        $dados = $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);

        $this->assertSame(9, $dados['charge_id']);
        $this->assertSame([], $this->logs('error'));
    }

    // notification_url

    private function corpoDaEfi(string $metodo, string $tipoDeCobranca, ?string $segredo): array
    {
        if ($segredo === null) {
            unset($_ENV['EFI_WEBHOOK_SECRET']);
        } else {
            $_ENV['EFI_WEBHOOK_SECRET'] = $segredo;
        }
        [$gateway, $sdk] = $this->efi(fn () => $this->respostaEfi());
        $this->invokeMethod($gateway, $tipoDeCobranca, [12, PaymentGateway::PAYMENT_TYPE_OS]);

        return $sdk->corpos[$metodo];
    }

    public function testNotificationUrlDoBoletoDaEfiApontaParaOProprioSite(): void
    {
        $corpo = $this->corpoDaEfi('createOneStepCharge', 'gerarCobrancaBoleto', 's3cr3t');

        $this->assertSame('http://mapos.test/index.php/webhooks/efi/s3cr3t', $corpo['metadata']['notification_url']);
    }

    public function testNotificationUrlDoLinkDaEfiApontaParaOProprioSite(): void
    {
        $corpo = $this->corpoDaEfi('createOneStepLink', 'gerarCobrancaLink', 's3cr3t');

        $this->assertSame('http://mapos.test/index.php/webhooks/efi/s3cr3t', $corpo['metadata']['notification_url']);
    }

    public function testNotificationUrlAusenteSemSegredoNoBoletoENoLink(): void
    {
        foreach ([null, '', '   '] as $segredo) {
            $boleto = $this->corpoDaEfi('createOneStepCharge', 'gerarCobrancaBoleto', $segredo);
            $link = $this->corpoDaEfi('createOneStepLink', 'gerarCobrancaLink', $segredo);

            $this->assertArrayNotHasKey('metadata', $boleto, var_export($segredo, true));
            $this->assertArrayNotHasKey('metadata', $link, var_export($segredo, true));
        }
    }

    public function testNotificationUrlNaoVazaOSegredoNoLog(): void
    {
        $_ENV['EFI_WEBHOOK_SECRET'] = 's3cr3t';
        [$gateway] = $this->efi(fn () => throw $this->efiException(400));

        try {
            $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);
        } catch (GatewayRecusouException) {
        }

        $this->assertLogNaoContem('s3cr3t');
    }

    // timeout

    private function configCarregada(string $variavel, ?string $valor): array
    {
        if ($valor === null) {
            unset($_ENV[$variavel]);
        } else {
            $_ENV[$variavel] = $valor;
        }
        $config = [];
        require APPPATH . 'config/payment_gateways.php';

        return $config['payment_gateways'];
    }

    public function testTimeoutDoConfigDaEfiUsaPadraoDezParaValorInvalido(): void
    {
        foreach ([null, '', '0', 'abc'] as $valor) {
            $config = $this->configCarregada('PAYMENT_GATEWAYS_EFI_TIMEOUT', $valor);
            $this->assertSame(10, $config['GerencianetSdk']['timeout'], 'Valor: ' . var_export($valor, true));
        }
        $this->assertSame(25, $this->configCarregada('PAYMENT_GATEWAYS_EFI_TIMEOUT', '25')['GerencianetSdk']['timeout']);
    }

    public function testTimeoutDoConfigDoAsaasUsaPadraoDezParaValorInvalido(): void
    {
        foreach ([null, '', '0', 'abc'] as $valor) {
            $config = $this->configCarregada('PAYMENT_GATEWAYS_ASAAS_TIMEOUT', $valor);
            $this->assertSame(10, $config['Asaas']['timeout'], 'Valor: ' . var_export($valor, true));
        }
        $this->assertSame(20, $this->configCarregada('PAYMENT_GATEWAYS_ASAAS_TIMEOUT', '20')['Asaas']['timeout']);
    }

    public function testTimeoutDoClienteDoAsaasFicaConfiguradoComVinteSegundos(): void
    {
        $gateway = $this->makeInstance(Asaas::class);
        $this->setPrivateProperty($gateway, 'asaasApi', new \CodePhix\Asaas\Asaas('token', 'homologacao'));

        $this->invokeMethod($gateway, 'configurarTimeout', [20]);

        $conexao = $this->readPrivateProperty($this->readPrivateProperty($gateway, 'asaasApi'), 'connection');
        $this->assertInstanceOf(\Libraries\Gateways\AsaasConexao::class, $conexao);
        $this->assertSame(20, $conexao->getTimeout());
        $this->assertSame($conexao, $this->readPrivateProperty($gateway, 'asaasApi')->Cobranca()->http);
    }

    public function testTimeoutDoClienteDoAsaasAplicaConexaoEResposta(): void
    {
        $conexao = new \Libraries\Gateways\AsaasConexao('token', 'homologacao', 20);

        $opcoes = $this->invokeMethod($conexao, 'opcoesDeTempo', []);

        $this->assertSame(20, $opcoes[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(20, $opcoes[CURLOPT_TIMEOUT]);
    }

    // descrição

    public function testDescricaoDaOsNoAsaasEOsComNumero(): void
    {
        [$gateway, $sdk] = $this->asaas(fn () => $this->asaasCobrancaCriada());

        $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);

        $this->assertSame('OS #12', $sdk->corpos['Cobranca.create']['description']);
    }

    public function testDescricaoDaVendaNoAsaasEVendaComNumero(): void
    {
        [$gateway, $sdk] = $this->asaas(fn () => $this->asaasCobrancaCriada());

        $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [31, PaymentGateway::PAYMENT_TYPE_VENDAS]);

        $this->assertSame('Venda #31', $sdk->corpos['Cobranca.create']['description']);
    }

    public function testDescricaoDoLinkNoAsaasUsaOMesmoTexto(): void
    {
        [$gateway, $sdk] = $this->asaas(fn () => $this->asaasCobrancaCriada());

        $this->invokeMethod($gateway, 'gerarCobrancaLink', [31, PaymentGateway::PAYMENT_TYPE_VENDAS]);

        $this->assertSame('Venda #31', $sdk->corpos['LinkPagamento.create']['description']);
    }

    public function testDescricaoDaOsNaEfiEOsComNumero(): void
    {
        [$gateway, $sdk] = $this->efi(fn () => $this->respostaEfi());

        $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, PaymentGateway::PAYMENT_TYPE_OS]);

        $this->assertSame('OS #12', $sdk->corpos['createOneStepCharge']['items'][0]['name']);
    }

    public function testDescricaoDaVendaNaEfiEVendaComNumero(): void
    {
        [$gateway, $sdk] = $this->efi(fn () => $this->respostaEfi());

        $this->invokeMethod($gateway, 'gerarCobrancaLink', [31, PaymentGateway::PAYMENT_TYPE_VENDAS]);

        $this->assertSame('Venda #31', $sdk->corpos['createOneStepLink']['items'][0]['name']);
    }
}
