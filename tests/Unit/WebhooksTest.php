<?php

use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;

require_once APPPATH . 'controllers/Webhooks.php';
require_once APPPATH . 'models/Webhooks_model.php';
require_once APPPATH . 'libraries/Limite_requisicoes.php';

/**
 * Webhooks de pagamento: autenticação do gateway, idempotência por evento,
 * reconsulta do pagamento e baixa transacional, contra SQLite em memória.
 */
final class WebhooksTest extends MaposTestCase
{
    private const VARIAVEIS = ['EFI_WEBHOOK_SECRET', 'MP_WEBHOOK_SECRET', 'ASAAS_WEBHOOK_TOKEN', 'WEBHOOK_RATE_LIMIT', 'API_ENABLED'];

    private int $agora = 1762200000;

    private int $consultas = 0;

    private object $webhooks;

    /** @var array<string, mixed> */
    private array $envSalvo = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARIAVEIS as $variavel) {
            $this->envSalvo[$variavel] = $_ENV[$variavel] ?? null;
            unset($_ENV[$variavel]);
        }

        $this->db->query('CREATE TABLE webhook_eventos (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway TEXT NOT NULL, evento_id TEXT NOT NULL, cobranca_id INTEGER NULL, payload_hash TEXT NOT NULL, resultado TEXT NULL, processado_em TEXT NULL, created_at TEXT NOT NULL, UNIQUE (gateway, evento_id))');
        $this->db->query('CREATE TABLE cobrancas (idCobranca INTEGER PRIMARY KEY AUTOINCREMENT, charge_id TEXT, status TEXT, total TEXT, payment_gateway TEXT, os_id INTEGER NULL, vendas_id INTEGER NULL)');
        $this->db->query('CREATE TABLE os (idOs INTEGER PRIMARY KEY, lancamento INTEGER NULL)');
        $this->db->query('CREATE TABLE vendas (idVendas INTEGER PRIMARY KEY, lancamentos_id INTEGER NULL)');
        $this->db->query('CREATE TABLE lancamentos (idLancamentos INTEGER PRIMARY KEY, baixado INTEGER NULL DEFAULT 0, data_pagamento TEXT NULL)');
        $this->db->query('CREATE TABLE logs (idLogs INTEGER PRIMARY KEY AUTOINCREMENT, usuario TEXT, ip TEXT, tarefa TEXT, data TEXT, hora TEXT)');
        $this->db->query('CREATE TABLE api_rate_limit (chave TEXT, janela INTEGER, total INTEGER DEFAULT 0, PRIMARY KEY (chave, janela))');

        $modelo = new Webhooks_model();
        $modelo->db = $this->db;

        $this->webhooks = $this->makeInstance(Webhooks::class);
        $this->setPrivateProperty($this->webhooks, 'modelo', $modelo);
        $this->setPrivateProperty($this->webhooks, 'limite', new Limite_requisicoes(['db' => $this->db, 'segredo' => 'x', 'relogio' => fn () => $this->agora]));
        $this->setPrivateProperty($this->webhooks, 'relogio', fn () => $this->agora);
        $this->consultaDevolve('123456', 'approved', 15090);
        $GLOBALS['__mapos_logs'] = [];
    }

    private bool $handlerAtivo = false;

    protected function tearDown(): void
    {
        if ($this->handlerAtivo) {
            restore_error_handler();
            $this->handlerAtivo = false;
        }

        foreach ($this->envSalvo as $variavel => $valor) {
            if ($valor === null) {
                unset($_ENV[$variavel]);
            } else {
                $_ENV[$variavel] = $valor;
            }
        }
    }

    // ---- helpers ----------------------------------------------------------

    private function consultaDevolve(string $chargeId, string $status, int $centavos): void
    {
        $this->consultas = 0;
        $this->setPrivateProperty($this->webhooks, 'consultor', function (string $gateway, string $id) use ($chargeId, $status, $centavos): array {
            $this->consultas++;

            return ['charge_id' => $chargeId, 'status' => $status, 'valor_centavos' => $centavos];
        });
    }

    private function consultaLanca(\Throwable $erro): void
    {
        $this->consultas = 0;
        $this->setPrivateProperty($this->webhooks, 'consultor', function () use ($erro): array {
            $this->consultas++;

            throw $erro;
        });
    }

    private function cobrancaDeOsComLancamento(string $chargeId, string $total, int $lancamento, string $gateway = 'MercadoPago', string $status = 'pending'): int
    {
        $this->db->insert('os', ['idOs' => 7, 'lancamento' => $lancamento]);
        $this->db->insert('lancamentos', ['idLancamentos' => $lancamento, 'baixado' => 0]);
        $this->db->insert('cobrancas', ['charge_id' => $chargeId, 'status' => $status, 'total' => $total, 'payment_gateway' => $gateway, 'os_id' => 7]);

        return (int) $this->db->insert_id();
    }

    private function requisicao(array $sobrescrever = []): array
    {
        return $sobrescrever + ['metodo' => 'POST', 'ip' => '10.0.0.1', 'corpo' => '{}', 'headers' => [], 'query' => [], 'segredo_path' => null];
    }

    private function requisicaoMp(string $dataId, string $requestId, string $corpo = '{"id":987,"type":"payment","data":{"id":"123456"}}', string $segredo = 'segredo-mp', ?string $ts = null, bool $soNaQuery = false): array
    {
        $ts ??= (string) ($this->agora * 1000);
        $assinatura = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", $segredo);

        return $this->requisicao([
            'corpo' => $corpo,
            'headers' => ['x-signature' => "ts={$ts},v1={$assinatura}", 'x-request-id' => $requestId],
            'query' => $soNaQuery ? ['data_id' => $dataId, 'type' => 'payment'] : [],
        ]);
    }

    private function mp(array $requisicao): array
    {
        return $this->invokeMethod($this->webhooks, 'atender', ['mercadopago', $requisicao]);
    }

    private function eventos(): array
    {
        return $this->db->get('webhook_eventos')->result_array();
    }

    private function lancamento(int $id): object
    {
        return $this->db->where('idLancamentos', $id)->get('lancamentos')->row();
    }

    private function statusDaCobranca(int $id): string
    {
        return (string) $this->db->where('idCobranca', $id)->get('cobrancas')->row('status');
    }

    // ---- entrada ----------------------------------------------------------

    public function testMetodoDiferenteDePostResponde405(): void
    {
        $_ENV['ASAAS_WEBHOOK_TOKEN'] = 'token-asaas';

        $resposta = $this->invokeMethod($this->webhooks, 'atender', ['asaas', $this->requisicao(['metodo' => 'GET'])]);

        $this->assertSame([405, ['Allow' => 'POST']], [$resposta['status'], $resposta['headers']]);
        $this->assertSame(0, $this->consultas);
        $this->assertSame(0, $this->db->count_all('webhook_eventos'));
    }

    public function testAcimaDoLimiteResponde429(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $_ENV['WEBHOOK_RATE_LIMIT'] = '2';
        $requisicao = $this->requisicaoMp('123456', 'req-1', '{"type":"outro","data":{"id":"123456"}}');

        $this->assertSame(200, $this->mp($requisicao)['status']);
        $this->assertSame(200, $this->mp($requisicao)['status']);
        $terceira = $this->mp($requisicao);

        $this->assertSame([429, ['Retry-After' => '60']], [$terceira['status'], $terceira['headers']]);
        $this->assertSame(0, $this->consultas);
    }

    public function testCorpoGrandeResponde413(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';

        $resposta = $this->mp($this->requisicao(['corpo' => null]));

        $this->assertSame([413, ['status' => 'recusado']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame(0, $this->consultas);
    }

    // ---- autenticação -----------------------------------------------------

    public function testAssinaturaMpInvalidaRecusaSemConsultarNemGravar(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';

        $outroSegredo = $this->mp($this->requisicaoMp('123456', 'req-1', segredo: 'outro'));
        $semAssinatura = $this->mp($this->requisicao(['corpo' => '{"type":"payment","data":{"id":"123456"}}', 'headers' => ['x-request-id' => 'req-1']]));

        $this->assertSame([401, ['status' => 'recusado']], [$outroSegredo['status'], $outroSegredo['corpo']]);
        $this->assertSame([401, ['status' => 'recusado']], [$semAssinatura['status'], $semAssinatura['corpo']]);
        $this->assertSame(0, $this->consultas);
        $this->assertSame(0, $this->db->count_all('webhook_eventos'));
    }

    public function testMpTimestampForaDaJanelaRecusa(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';

        $velho = $this->mp($this->requisicaoMp('123456', 'req-1', ts: (string) ($this->agora - 301)));
        $limite = $this->mp($this->requisicaoMp('123456', 'req-2', '{"type":"outro","data":{"id":"123456"}}', ts: (string) ($this->agora - 300)));

        $this->assertSame([401, ['status' => 'recusado']], [$velho['status'], $velho['corpo']]);
        $this->assertSame(200, $limite['status']);
        $this->assertSame(0, $this->consultas);
        $this->assertSame(0, $this->db->count_all('webhook_eventos'));
    }

    public function testSegredoEfiErradoRecusa(): void
    {
        $_ENV['EFI_WEBHOOK_SECRET'] = 's3cr3t';

        $resposta = $this->invokeMethod($this->webhooks, 'atender', ['efi', $this->requisicao(['corpo' => 'notification=abc', 'segredo_path' => 'errado'])]);

        $this->assertSame([401, ['status' => 'recusado']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame(0, $this->consultas);
        $this->assertSame(0, $this->db->count_all('webhook_eventos'));
    }

    public function testTokenAsaasErradoRecusa(): void
    {
        $_ENV['ASAAS_WEBHOOK_TOKEN'] = 'token-asaas';

        $resposta = $this->invokeMethod($this->webhooks, 'atender', ['asaas', $this->requisicao([
            'corpo' => '{"id":"evt_1","event":"PAYMENT_RECEIVED","payment":{"id":"pay_9"}}',
            'headers' => ['asaas-access-token' => 'errado'],
        ])]);

        $this->assertSame([401, ['status' => 'recusado']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame(0, $this->consultas);
        $this->assertSame(0, $this->db->count_all('webhook_eventos'));
    }

    public function testSegredoVazioRecusaTudo(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = '';

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1', segredo: ''));
        $asaas = $this->invokeMethod($this->webhooks, 'atender', ['asaas', $this->requisicao(['headers' => ['asaas-access-token' => '']])]);

        $this->assertSame([401, ['status' => 'recusado']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame(401, $asaas['status']);
        $this->assertLogContem('error', 'webhook desabilitado gateway=mercadopago');
        $this->assertLogContem('error', 'webhook desabilitado gateway=asaas');
        $this->assertSame(0, $this->consultas);
    }

    // ---- fluxo de pagamento -----------------------------------------------

    public function testPagamentoConfirmadoBaixaOLancamentoDaOs(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $cobranca = $this->cobrancaDeOsComLancamento('123456', '15090', 40);

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1', '{"id":987,"type":"payment"}', soNaQuery: true));

        $this->assertSame([200, ['status' => 'aplicado']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame('approved', $this->statusDaCobranca($cobranca));
        $this->assertSame(['1', date('Y-m-d')], [(string) $this->lancamento(40)->baixado, $this->lancamento(40)->data_pagamento]);
        $evento = $this->eventos()[0];
        $this->assertSame(['aplicado', (string) $cobranca, 'mercadopago', '987'], [$evento['resultado'], (string) $evento['cobranca_id'], $evento['gateway'], $evento['evento_id']]);
        $this->assertNotNull($evento['processado_em']);
        $logs = $this->db->get('logs')->result_array();
        $this->assertSame(1, count($logs));
        $this->assertSame(['webhook:mercadopago', "Pagamento confirmado por webhook. Cobrança {$cobranca}"], [$logs[0]['usuario'], $logs[0]['tarefa']]);
    }

    public function testMesmoEventoDuasVezesConsultaUmaVezEBaixaUmaVez(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $this->cobrancaDeOsComLancamento('123456', '15090', 40);
        $requisicao = $this->requisicaoMp('123456', 'req-1');

        $primeira = $this->mp($requisicao);
        $segunda = $this->mp($requisicao);

        $this->assertSame([200, ['status' => 'aplicado']], [$primeira['status'], $primeira['corpo']]);
        $this->assertSame([200, ['status' => 'repetido']], [$segunda['status'], $segunda['corpo']]);
        $this->assertSame(1, $this->consultas);
        $this->assertSame('1', (string) $this->lancamento(40)->baixado);
        $this->assertSame(1, $this->db->count_all('logs'));
        $this->assertSame(1, $this->db->count_all('webhook_eventos'));
    }

    public function testEventoSemProcessadoEmEReprocessado(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $this->cobrancaDeOsComLancamento('123456', '15090', 40);
        $this->db->insert('webhook_eventos', ['gateway' => 'mercadopago', 'evento_id' => '987', 'payload_hash' => 'x', 'created_at' => '2026-11-01 10:00:00']);

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1'));

        $this->assertSame([200, ['status' => 'aplicado']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame(1, $this->consultas);
        $this->assertNotNull($this->eventos()[0]['processado_em']);
        $this->assertSame(1, $this->db->count_all('webhook_eventos'));
    }

    public function testStatusDoCorpoEIgnoradoValeAConsulta(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $cobranca = $this->cobrancaDeOsComLancamento('123456', '15090', 40);
        $this->consultaDevolve('123456', 'pending', 15090);

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1', '{"id":987,"type":"payment","status":"approved","data":{"id":"123456"}}'));

        $this->assertSame([200, ['status' => 'sem_efeito']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame('pending', $this->statusDaCobranca($cobranca));
        $this->assertSame('0', (string) $this->lancamento(40)->baixado);
    }

    public function testValorDivergenteNaoBaixa(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $cobranca = $this->cobrancaDeOsComLancamento('123456', '15090', 40);
        $this->consultaDevolve('123456', 'approved', 15000);

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1'));

        $this->assertSame([200, ['status' => 'divergente']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame('pending', $this->statusDaCobranca($cobranca));
        $this->assertSame('0', (string) $this->lancamento(40)->baixado);
        $evento = $this->eventos()[0];
        $this->assertSame('divergente', $evento['resultado']);
        $this->assertNotNull($evento['processado_em']);
        $this->assertLogContem('error', "webhook alerta divergencia gateway=mercadopago cobranca={$cobranca}");
    }

    public function testPagoNaoRegride(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $cobranca = $this->cobrancaDeOsComLancamento('123456', '15090', 40, status: 'approved');
        $this->consultaDevolve('123456', 'pending', 15090);

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1'));

        $this->assertSame([200, ['status' => 'sem_efeito']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame('approved', $this->statusDaCobranca($cobranca));
    }

    public function testSegundoEventoNaoAlteraDataDePagamento(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $cobranca = $this->cobrancaDeOsComLancamento('123456', '15090', 40, status: 'approved');
        $this->db->where('idLancamentos', 40)->update('lancamentos', ['baixado' => 1, 'data_pagamento' => '2026-11-01']);

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-2', '{"id":988,"type":"payment","data":{"id":"123456"}}'));

        $this->assertSame([200, ['status' => 'sem_efeito']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame('2026-11-01', $this->lancamento(40)->data_pagamento);
        $this->assertSame('approved', $this->statusDaCobranca($cobranca));
        $this->assertSame(0, $this->db->count_all('logs'));
    }

    public function testFalhaNaBaixaDesfazTudoEResponde503(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $cobranca = $this->cobrancaDeOsComLancamento('123456', '15090', 40);
        // Como em produção: db_debug desligado e o warning do driver do SQLite silenciado.
        $this->db->db_debug = false;
        set_error_handler(static fn () => true, E_WARNING);
        $this->handlerAtivo = true;
        $this->db->query("CREATE TRIGGER falha_baixa BEFORE UPDATE ON lancamentos BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1'));

        $this->assertSame([503, ['status' => 'tente_novamente']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame('pending', $this->statusDaCobranca($cobranca));
        $this->assertSame(0, $this->db->count_all('webhook_eventos'));
        $this->assertSame('0', (string) $this->lancamento(40)->baixado);
    }

    public function testConsultaIndisponivelResponde503(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $this->consultaLanca(new GatewayIndisponivelException());

        $indisponivel = $this->mp($this->requisicaoMp('123456', 'req-1'));
        $this->consultaLanca(new \LogicException('qualquer'));
        $outra = $this->mp($this->requisicaoMp('123456', 'req-1'));

        $this->assertSame([503, ['status' => 'tente_novamente']], [$indisponivel['status'], $indisponivel['corpo']]);
        $this->assertSame([503, ['status' => 'tente_novamente']], [$outra['status'], $outra['corpo']]);
        $this->assertSame(0, $this->db->where('processado_em IS NOT NULL', null, false)->count_all_results('webhook_eventos'));
    }

    public function testConsultaRecusadaIgnora(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $this->consultaLanca(new GatewayRecusouException('Pagamento inexistente.'));

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1'));

        $this->assertSame([200, ['status' => 'ignorado']], [$resposta['status'], $resposta['corpo']]);
        $evento = $this->eventos()[0];
        $this->assertSame('ignorado', $evento['resultado']);
        $this->assertNotNull($evento['processado_em']);
    }

    public function testCobrancaDesconhecidaIgnora(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $this->cobrancaDeOsComLancamento('999', '15090', 40);

        $resposta = $this->mp($this->requisicaoMp('123456', 'req-1'));

        $this->assertSame([200, ['status' => 'ignorado']], [$resposta['status'], $resposta['corpo']]);
        $evento = $this->eventos()[0];
        $this->assertSame('ignorado', $evento['resultado']);
        $this->assertNull($evento['cobranca_id']);
        $this->assertNotNull($evento['processado_em']);
    }

    public function testPayloadIrrelevanteIgnoraSemConsultar(): void
    {
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $_ENV['ASAAS_WEBHOOK_TOKEN'] = 'token-asaas';
        $_ENV['EFI_WEBHOOK_SECRET'] = 's3cr3t';

        $mp = $this->mp($this->requisicaoMp('123456', 'req-1', '{"id":1,"type":"subscription","data":{"id":"123456"}}'));
        $asaas = $this->invokeMethod($this->webhooks, 'atender', ['asaas', $this->requisicao(['corpo' => '{"id":"e","event":"X"}', 'headers' => ['asaas-access-token' => 'token-asaas']])]);
        $efi = $this->invokeMethod($this->webhooks, 'atender', ['efi', $this->requisicao(['corpo' => 'outro=1', 'segredo_path' => 's3cr3t'])]);

        $this->assertSame([200, ['status' => 'ignorado']], [$mp['status'], $mp['corpo']]);
        $this->assertSame([200, ['status' => 'ignorado']], [$asaas['status'], $asaas['corpo']]);
        $this->assertSame([200, ['status' => 'ignorado']], [$efi['status'], $efi['corpo']]);
        $this->assertSame(0, $this->consultas);
        $this->assertSame(0, $this->db->count_all('webhook_eventos'));
    }

    public function testPagamentoDeVendaBaixaOLancamentoDaVenda(): void
    {
        $_ENV['ASAAS_WEBHOOK_TOKEN'] = 'token-asaas';
        $this->db->insert('vendas', ['idVendas' => 5, 'lancamentos_id' => 41]);
        $this->db->insert('lancamentos', ['idLancamentos' => 41, 'baixado' => 0]);
        $this->db->insert('cobrancas', ['charge_id' => 'pay_9', 'status' => 'PENDING', 'total' => '2500', 'payment_gateway' => 'Asaas', 'vendas_id' => 5]);
        $this->consultaDevolve('pay_9', 'RECEIVED', 2500);

        $resposta = $this->invokeMethod($this->webhooks, 'atender', ['asaas', $this->requisicao([
            'corpo' => '{"id":"evt_1","event":"PAYMENT_RECEIVED","payment":{"id":"pay_9"}}',
            'headers' => ['asaas-access-token' => 'token-asaas'],
        ])]);

        $this->assertSame([200, ['status' => 'aplicado']], [$resposta['status'], $resposta['corpo']]);
        $this->assertSame(['1', date('Y-m-d')], [(string) $this->lancamento(41)->baixado, $this->lancamento(41)->data_pagamento]);
        $this->assertSame(1, $this->consultas);
    }

    // ---- configuração e superfície ----------------------------------------

    public function testRotasExistemComApiDesligada(): void
    {
        $_ENV['API_ENABLED'] = 'false';
        $route = [];
        (function () use (&$route): void {
            include APPPATH . 'config/routes.php';
        })();

        $this->assertSame('webhooks/efi/$1', $route['webhooks/efi/(:any)'] ?? null);
        $this->assertSame('webhooks/mercadopago', $route['webhooks/mercadopago'] ?? null);
        $this->assertSame('webhooks/asaas', $route['webhooks/asaas'] ?? null);
    }

    public function testLogNaoTemSegredos(): void
    {
        $_ENV['EFI_WEBHOOK_SECRET'] = 's3cr3t-efi';
        $_ENV['ASAAS_WEBHOOK_TOKEN'] = 'token-asaas';
        $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
        $this->cobrancaDeOsComLancamento('123456', '15090', 40);

        $mp = $this->requisicaoMp('123456', 'req-1');
        $this->mp($mp);
        $this->mp($this->requisicaoMp('123456', 'req-2', segredo: 'outro'));
        $this->invokeMethod($this->webhooks, 'atender', ['efi', $this->requisicao(['corpo' => 'notification=abc', 'segredo_path' => 's3cr3t-efi'])]);
        $this->invokeMethod($this->webhooks, 'atender', ['efi', $this->requisicao(['corpo' => 'notification=abc', 'segredo_path' => 'errado'])]);
        $this->invokeMethod($this->webhooks, 'atender', ['asaas', $this->requisicao(['headers' => ['asaas-access-token' => 'token-asaas-errado']])]);

        $this->assertNotSame([], $this->logs());
        $this->assertLogNaoContem('s3cr3t-efi');
        $this->assertLogNaoContem('token-asaas');
        $this->assertLogNaoContem('segredo-mp');
        $this->assertLogNaoContem(explode('v1=', $mp['headers']['x-signature'])[1]);
    }
}
