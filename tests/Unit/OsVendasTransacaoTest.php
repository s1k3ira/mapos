<?php

require_once APPPATH . 'models/Os_model.php';
require_once APPPATH . 'models/Vendas_model.php';

/**
 * Exclusão e faturamento de OS e vendas em transação: tudo ou nada, com log
 * e retorno que o controller consegue conferir.
 */
final class OsVendasTransacaoTest extends MaposTestCase
{
    private function osModel(): Os_model
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS os (idOs INTEGER PRIMARY KEY, status TEXT, faturado INTEGER DEFAULT 0, valorTotal REAL, lancamento INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS servicos_os (idServicos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS produtos_os (idProdutos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS anexos (idAnexos INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS anotacoes_os (idAnotacoes INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS os_historico (id INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, status_anterior TEXT, status_novo TEXT, usuarios_id INTEGER, data_hora TEXT)');
        $this->criarTabelasComuns();

        $model = $this->makeInstance(Os_model::class);
        $this->setPrivateProperty($model, 'db', $this->db);

        return $model;
    }

    private function vendasModel(): Vendas_model
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS vendas (idVendas INTEGER PRIMARY KEY, status TEXT, faturado INTEGER DEFAULT 0, valorTotal REAL, lancamentos_id INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS itens_de_vendas (idItens INTEGER PRIMARY KEY AUTOINCREMENT, vendas_id INTEGER)');
        $this->criarTabelasComuns();

        $model = $this->makeInstance(Vendas_model::class);
        $this->setPrivateProperty($model, 'db', $this->db);

        return $model;
    }

    private function criarTabelasComuns(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS cobrancas (idCobranca INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, vendas_id INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS lancamentos (idLancamentos INTEGER PRIMARY KEY AUTOINCREMENT, descricao TEXT, valor REAL, data_vencimento TEXT, usuarios_id INTEGER, baixado INTEGER DEFAULT 0, vendas_id INTEGER)');
    }

    private function osCompleta(int $id): void
    {
        $this->osModel();
        $this->db->query('INSERT INTO os (idOs, status, faturado) VALUES (?, ?, 0)', [$id, 'Aberto']);
        $this->db->query('INSERT INTO servicos_os (os_id) VALUES (?), (?)', [$id, $id]);
        $this->db->query('INSERT INTO produtos_os (os_id) VALUES (?)', [$id]);
        $this->db->query('INSERT INTO anexos (os_id) VALUES (?)', [$id]);
        $this->db->query('INSERT INTO anotacoes_os (os_id) VALUES (?)', [$id]);
        $this->db->query("INSERT INTO os_historico (os_id, status_anterior, status_novo) VALUES (?, NULL, 'Aberto'), (?, 'Aberto', 'Em andamento')", [$id, $id]);
    }

    /** @return array<string, int> */
    private function contagensDaOs(int $id): array
    {
        $contagens = [];
        foreach (['servicos_os', 'produtos_os', 'anexos', 'anotacoes_os', 'os_historico'] as $tabela) {
            $contagens[$tabela] = $this->db->where('os_id', $id)->count_all_results($tabela);
        }
        $contagens['os'] = $this->db->where('idOs', $id)->count_all_results('os');

        return $contagens;
    }

    private bool $handlerAtivo = false;

    protected function tearDown(): void
    {
        if ($this->handlerAtivo) {
            restore_error_handler();
            $this->handlerAtivo = false;
        }

        parent::tearDown();
    }

    /**
     * Faz o próximo $evento em $tabela falhar. Como em produção, db_debug fica
     * desligado (a query devolve false) e o driver do SQLite avisa a falha com
     * um warning do PHP, silenciado aqui.
     */
    private function falhaAntesDe(string $evento, string $tabela): void
    {
        $this->db->db_debug = false;
        if (! $this->handlerAtivo) {
            set_error_handler(static fn () => true, E_WARNING);
            $this->handlerAtivo = true;
        }
        $this->db->query("CREATE TRIGGER falha BEFORE {$evento} ON {$tabela} BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");
    }

    private function lancamentoDeFatura(): array
    {
        return ['descricao' => 'Fatura de OS Nº: 3', 'valor' => 150.9, 'data_vencimento' => '2026-11-03', 'usuarios_id' => 1];
    }

    public function testExclusaoDaOsApagaTudoQueEDela(): void
    {
        $this->osCompleta(3);
        $this->osCompleta(4);

        $this->assertTrue($this->osModel()->excluirComDependentes(3, false, null, false));

        $this->assertSame(['servicos_os' => 0, 'produtos_os' => 0, 'anexos' => 0, 'anotacoes_os' => 0, 'os_historico' => 0, 'os' => 0], $this->contagensDaOs(3));
        $this->assertSame(['servicos_os' => 2, 'produtos_os' => 1, 'anexos' => 1, 'anotacoes_os' => 1, 'os_historico' => 2, 'os' => 1], $this->contagensDaOs(4));
    }

    public function testFalhaNoMeioDaExclusaoDaOsMantemTudo(): void
    {
        $this->osCompleta(3);
        $model = $this->osModel();
        $this->falhaAntesDe('DELETE', 'os');

        $this->assertFalse($model->excluirComDependentes(3, false, null, false));

        $this->assertSame(
            ['servicos_os' => 2, 'produtos_os' => 1, 'anexos' => 1, 'anotacoes_os' => 1, 'os_historico' => 2, 'os' => 1],
            $this->contagensDaOs(3)
        );
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_os id=3');
    }

    public function testDevolucaoDeEstoqueRodaDentroDaTransacao(): void
    {
        $this->osCompleta(3);
        $model = $this->osModel();
        $this->db->query('CREATE TABLE IF NOT EXISTS estoque (id INTEGER PRIMARY KEY, qtd INTEGER)');
        $this->db->query('INSERT INTO estoque VALUES (1, 10)');

        $chamadas = 0;
        $produtosNaChamada = null;
        $devolver = function () use (&$chamadas, &$produtosNaChamada): void {
            ++$chamadas;
            $produtosNaChamada = $this->db->where('os_id', 3)->count_all_results('produtos_os');
            $this->db->query('UPDATE estoque SET qtd = qtd + 1 WHERE id = 1');
        };

        $this->assertTrue($model->excluirComDependentes(3, false, null, false, $devolver));
        $this->assertSame(1, $chamadas);
        $this->assertSame(1, $produtosNaChamada, 'Os itens ainda existem quando o estoque volta.');
        $this->assertSame('11', (string) $this->db->get('estoque')->row()->qtd);

        // Falha depois da devolução: o que ela gravou é desfeito.
        $this->osCompleta(5);
        $this->falhaAntesDe('DELETE', 'os');
        $this->assertFalse($model->excluirComDependentes(5, false, null, false, $devolver));
        $this->assertSame(2, $chamadas);
        $this->assertSame('11', (string) $this->db->get('estoque')->row()->qtd, 'O rollback desfaz a devolução.');
    }

    public function testExclusaoDaOsFaturadaApagaALancamento(): void
    {
        $this->osCompleta(3);
        $model = $this->osModel();
        $this->db->query("INSERT INTO lancamentos (idLancamentos, descricao, baixado) VALUES (40, 'Fatura de OS Nº: 3', 0)");
        $this->db->query('UPDATE os SET faturado = 1, lancamento = NULL WHERE idOs = 3');

        $this->assertTrue($model->excluirComDependentes(3, false, 40, true));
        $this->assertSame(0, $this->db->count_all('lancamentos'));
    }

    public function testExclusaoDaOsApagaACobrancaCancelada(): void
    {
        $this->osCompleta(3);
        $model = $this->osModel();
        $this->db->query('INSERT INTO cobrancas (os_id) VALUES (3), (4)');

        $this->assertTrue($model->excluirComDependentes(3, true, null, false));
        $this->assertSame(1, $this->db->count_all('cobrancas'));
        $this->assertSame('4', (string) $this->db->get('cobrancas')->row()->os_id);

        // Com falha, a cobrança continua.
        $this->osCompleta(6);
        $this->db->query('INSERT INTO cobrancas (os_id) VALUES (6)');
        $this->falhaAntesDe('DELETE', 'os');
        $this->assertFalse($model->excluirComDependentes(6, true, null, false));
        $this->assertSame(1, $this->db->where('os_id', 6)->count_all_results('cobrancas'));
    }

    public function testExclusaoDeOsInexistenteDevolveFalse(): void
    {
        $this->osCompleta(3);

        $this->assertFalse($this->osModel()->excluirComDependentes(99, false, null, false));
        $this->assertSame(1, $this->db->where('idOs', 3)->count_all_results('os'));
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_os id=99');
    }

    public function testExclusaoDaVenda(): void
    {
        $model = $this->vendasModel();
        $this->db->query('INSERT INTO vendas (idVendas, status, faturado, lancamentos_id) VALUES (9, ?, 1, 41)', ['Faturado']);
        $this->db->query('INSERT INTO itens_de_vendas (vendas_id) VALUES (9), (9)');
        $this->db->query("INSERT INTO lancamentos (idLancamentos, descricao, baixado, vendas_id) VALUES (41, 'Fatura de venda', 0, 9)");

        $this->assertTrue($model->excluirComDependentes(9, false, 41, true));
        $this->assertSame(0, $this->db->count_all('itens_de_vendas'));
        $this->assertSame(0, $this->db->count_all('vendas'));
        $this->assertSame(0, $this->db->count_all('lancamentos'));

        // Falha no DELETE da venda: os itens continuam.
        $this->db->query('INSERT INTO vendas (idVendas, status, faturado) VALUES (9, ?, 0)', ['Aberto']);
        $this->db->query('INSERT INTO itens_de_vendas (vendas_id) VALUES (9), (9)');
        $this->falhaAntesDe('DELETE', 'vendas');

        $this->assertFalse($model->excluirComDependentes(9, false, null, false));
        $this->assertSame(2, $this->db->where('vendas_id', 9)->count_all_results('itens_de_vendas'));
        $this->assertSame(1, $this->db->where('idVendas', 9)->count_all_results('vendas'));
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_venda id=9');
    }

    public function testFaturarOsGravaTudo(): void
    {
        $this->osCompleta(3);
        $model = $this->osModel();
        $this->db->query("UPDATE os SET status = 'Aberto'");
        $this->db->query('DELETE FROM os_historico');

        $id = $model->faturar(3, $this->lancamentoDeFatura(), ['faturado' => 1, 'status' => 'Faturado', 'valorTotal' => 150.9], 'Aberto', 1);

        $this->assertSame(1, $id);
        $os = $this->db->where('idOs', 3)->get('os')->row();
        $this->assertSame('1', (string) $os->faturado);
        $this->assertSame('1', (string) $os->lancamento);
        $this->assertSame('Faturado', $os->status);
        $historico = $this->db->where('os_id', 3)->get('os_historico')->result();
        $this->assertCount(1, $historico);
        $this->assertSame('Aberto', $historico[0]->status_anterior);
        $this->assertSame('Faturado', $historico[0]->status_novo);
        $this->assertSame('1', (string) $historico[0]->usuarios_id);
        $this->assertSame('150.9', (string) $this->db->get('lancamentos')->row()->valor);
    }

    public function testFaturarComFalhaNaOsNaoCriaLancamento(): void
    {
        $model = $this->osModel();
        $this->db->insert('os', ['idOs' => 3, 'status' => 'Aberto', 'faturado' => 0]);
        $this->falhaAntesDe('UPDATE', 'os');

        $this->assertNull($model->faturar(3, $this->lancamentoDeFatura(), ['faturado' => 1, 'status' => 'Faturado'], 'Aberto', 1));
        $this->assertSame(0, $this->db->count_all('lancamentos'));
        $this->assertSame('0', (string) $this->db->where('idOs', 3)->get('os')->row()->faturado);
        $this->assertLogContem('error', 'transacao rollback operacao=faturar_os id=3');
    }

    public function testFaturarComFalhaNoHistoricoReverte(): void
    {
        $model = $this->osModel();
        $this->db->insert('os', ['idOs' => 3, 'status' => 'Aberto', 'faturado' => 0]);
        $this->falhaAntesDe('INSERT', 'os_historico');

        $this->assertNull($model->faturar(3, $this->lancamentoDeFatura(), ['faturado' => 1, 'status' => 'Faturado'], 'Aberto', 1));
        $this->assertSame(0, $this->db->count_all('lancamentos'));
        $os = $this->db->where('idOs', 3)->get('os')->row();
        $this->assertSame('0', (string) $os->faturado);
        $this->assertSame('Aberto', $os->status);
        $this->assertSame(0, $this->db->count_all('os_historico'));
    }

    public function testFaturarVenda(): void
    {
        $model = $this->vendasModel();
        $this->db->query('INSERT INTO vendas (idVendas, status, faturado) VALUES (9, ?, 0)', ['Aberto']);

        $id = $model->faturar(9, ['descricao' => 'Fatura de venda', 'valor' => 80, 'vendas_id' => 9], ['faturado' => 1, 'status' => 'Faturado']);

        $this->assertSame(1, $id);
        $venda = $this->db->where('idVendas', 9)->get('vendas')->row();
        $this->assertSame('1', (string) $venda->lancamentos_id);
        $this->assertSame('1', (string) $venda->faturado);
        $this->assertSame('Faturado', $venda->status);

        // Falha no UPDATE: nada de lançamento.
        $this->db->query('INSERT INTO vendas (idVendas, status, faturado) VALUES (10, ?, 0)', ['Aberto']);
        $this->falhaAntesDe('UPDATE', 'vendas');

        $this->assertNull($model->faturar(10, ['descricao' => 'Fatura de venda', 'valor' => 80, 'vendas_id' => 10], ['faturado' => 1, 'status' => 'Faturado']));
        $this->assertSame(1, $this->db->count_all('lancamentos'));
        $this->assertSame('0', (string) $this->db->where('idVendas', 10)->get('vendas')->row()->faturado);
        $this->assertLogContem('error', 'transacao rollback operacao=faturar_venda id=10');
    }

    public function testRegistrarStatus(): void
    {
        $model = $this->osModel();

        $this->assertTrue($model->registrarStatus(3, 'Aberto', 'Faturado', 1));
        $this->assertSame(1, $this->db->count_all('os_historico'));

        $this->assertTrue($model->registrarStatus(3, 'Aberto', 'Aberto', 1));
        $this->assertSame(1, $this->db->count_all('os_historico'), 'Status igual não grava.');

        $this->falhaAntesDe('INSERT', 'os_historico');

        $this->assertFalse($model->registrarStatus(3, 'Aberto', 'Em andamento', 1));
        $this->assertSame(1, $this->db->count_all('os_historico'));
        $this->assertLogContem('error', 'os historico falha os=3');
    }

    public function testRegistrarStatusSemATabelaNaoGrava(): void
    {
        $model = $this->makeInstance(Os_model::class);
        $this->setPrivateProperty($model, 'db', $this->db);

        $this->assertTrue($model->registrarStatus(3, 'Aberto', 'Faturado', 1));
    }
}
