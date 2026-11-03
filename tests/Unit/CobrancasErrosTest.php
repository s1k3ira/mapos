<?php

use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;

require_once APPPATH . 'models/Cobrancas_model.php';

/**
 * Respostas de falha das cobranças (422/502/500 com texto nosso) e a exclusão
 * da cobrança em transação.
 */
final class CobrancasErrosTest extends MaposTestCase
{
    private const GENERICA = 'O provedor de pagamento não respondeu. Tente novamente em instantes.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('CREATE TABLE cobrancas (idCobranca INTEGER PRIMARY KEY AUTOINCREMENT, charge_id TEXT, status TEXT, total TEXT, payment_gateway TEXT, os_id INTEGER, vendas_id INTEGER)');
    }

    private function model(): Cobrancas_model
    {
        $model = new Cobrancas_model();
        $model->db = $this->db;

        return $model;
    }

    public function testRecusouViraQuatroVinteDoisComAMensagem(): void
    {
        $mensagem = 'O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.';

        $this->assertSame(
            [422, $mensagem],
            cobrancaRespostaDeFalha(new GatewayRecusouException($mensagem), 'padrão')
        );
    }

    public function testIndisponivelSempreUsaAMensagemGenerica(): void
    {
        $this->assertSame([502, self::GENERICA], cobrancaRespostaDeFalha(new GatewayIndisponivelException(), 'padrão'));
        $this->assertSame(
            [502, self::GENERICA],
            cobrancaRespostaDeFalha(new GatewayIndisponivelException('texto do provedor'), 'padrão')
        );
    }

    public function testValidacaoLocalViraQuatroVinteDois(): void
    {
        $this->assertSame([422, 'Valor inválido!'], cobrancaRespostaDeFalha(new \Exception('Valor inválido!'), 'padrão'));
    }

    public function testFalhaTecnicaViraQuinhentosComTextoPadrao(): void
    {
        $padrao = 'Não foi possível gerar a cobrança. Tente novamente.';

        $this->assertSame(
            [500, $padrao],
            cobrancaRespostaDeFalha(new \TypeError('Argument #1 ... /var/www/x.php'), $padrao)
        );
        $this->assertLogContem('error', 'cobranca falha erro=TypeError');

        $this->assertSame(
            [500, 'padrão'],
            cobrancaRespostaDeFalha(new \RuntimeException('SQLSTATE[HY000] segredo'), 'padrão')
        );
        $this->assertLogContem('error', 'cobranca falha erro=RuntimeException');
        $this->assertLogNaoContem('SQLSTATE');
        $this->assertLogNaoContem('/var/www/x.php');
    }

    public function testExclusaoComSucesso(): void
    {
        $this->db->insert('cobrancas', ['idCobranca' => 7, 'status' => 'canceled']);

        $this->assertTrue($this->model()->excluirEmTransacao(7));
        $this->assertSame(0, $this->db->where('idCobranca', 7)->count_all_results('cobrancas'));
    }

    public function testExclusaoDeCobrancaInexistente(): void
    {
        $this->db->insert('cobrancas', ['idCobranca' => 7, 'status' => 'canceled']);

        $this->assertFalse($this->model()->excluirEmTransacao(999));
        $this->assertSame(1, $this->db->where('idCobranca', 7)->count_all_results('cobrancas'));
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_cobranca id=999');
    }

    public function testExclusaoComFalhaDoBancoMantemACobranca(): void
    {
        $this->db->insert('cobrancas', ['idCobranca' => 7, 'status' => 'canceled']);
        // Com db_debug desligado a query devolve false e o driver do SQLite avisa a falha com E_WARNING.
        $this->db->db_debug = false;
        set_error_handler(static fn () => true, E_WARNING);
        $this->db->query("CREATE TRIGGER falha_exclusao BEFORE DELETE ON cobrancas BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

        try {
            $this->assertFalse($this->model()->excluirEmTransacao(7));
        } finally {
            restore_error_handler();
        }
        $this->assertSame(1, $this->db->where('idCobranca', 7)->count_all_results('cobrancas'));
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_cobranca id=7');
    }
}
