<?php

require_once APPPATH . 'models/Produtos_model.php';
require_once APPPATH . 'models/Servicos_model.php';

/**
 * Exclusão de produtos e serviços em transação: tudo ou nada, com log e
 * retorno que o controller consegue conferir.
 */
final class ProdutosServicosExclusaoTest extends MaposTestCase
{
    private bool $handlerAtivo = false;

    protected function tearDown(): void
    {
        if ($this->handlerAtivo) {
            restore_error_handler();
            $this->handlerAtivo = false;
        }

        parent::tearDown();
    }

    private function produtosModel(): Produtos_model
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS produtos (idProdutos INTEGER PRIMARY KEY, descricao TEXT)');
        $this->db->query('CREATE TABLE IF NOT EXISTS produtos_os (idProdutos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, produtos_id INTEGER)');
        $this->db->query('CREATE TABLE IF NOT EXISTS itens_de_vendas (idItens INTEGER PRIMARY KEY AUTOINCREMENT, vendas_id INTEGER, produtos_id INTEGER)');

        $model = $this->makeInstance(Produtos_model::class);
        $this->setPrivateProperty($model, 'db', $this->db);

        return $model;
    }

    private function servicosModel(): Servicos_model
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS servicos (idServicos INTEGER PRIMARY KEY, nome TEXT)');
        $this->db->query('CREATE TABLE IF NOT EXISTS servicos_os (idServicos_os INTEGER PRIMARY KEY AUTOINCREMENT, os_id INTEGER, servicos_id INTEGER)');

        $model = $this->makeInstance(Servicos_model::class);
        $this->setPrivateProperty($model, 'db', $this->db);

        return $model;
    }

    /** Dois produtos: o 7 (2 em produtos_os, 1 em itens_de_vendas) e o 8 (1 em cada). */
    private function produtosPopulados(): Produtos_model
    {
        $model = $this->produtosModel();
        $this->db->query("INSERT INTO produtos (idProdutos, descricao) VALUES (7, 'Tela'), (8, 'Bateria')");
        $this->db->query('INSERT INTO produtos_os (os_id, produtos_id) VALUES (1, 7), (2, 7), (1, 8)');
        $this->db->query('INSERT INTO itens_de_vendas (vendas_id, produtos_id) VALUES (1, 7), (2, 8)');

        return $model;
    }

    /** Dois serviços: o 4 (3 em servicos_os) e o 5 (1). */
    private function servicosPopulados(): Servicos_model
    {
        $model = $this->servicosModel();
        $this->db->query("INSERT INTO servicos (idServicos, nome) VALUES (4, 'Formatação'), (5, 'Limpeza')");
        $this->db->query('INSERT INTO servicos_os (os_id, servicos_id) VALUES (1, 4), (2, 4), (3, 4), (1, 5)');

        return $model;
    }

    /** @return array<string, int> */
    private function contagensDoProduto(int $id): array
    {
        return [
            'produtos_os' => $this->db->where('produtos_id', $id)->count_all_results('produtos_os'),
            'itens_de_vendas' => $this->db->where('produtos_id', $id)->count_all_results('itens_de_vendas'),
            'produtos' => $this->db->where('idProdutos', $id)->count_all_results('produtos'),
        ];
    }

    /** @return array<string, int> */
    private function contagensDoServico(int $id): array
    {
        return [
            'servicos_os' => $this->db->where('servicos_id', $id)->count_all_results('servicos_os'),
            'servicos' => $this->db->where('idServicos', $id)->count_all_results('servicos'),
        ];
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

    public function testExclusaoDoProdutoApagaSoOQueEDele(): void
    {
        $model = $this->produtosPopulados();

        $this->assertTrue($model->excluirComDependentes(7));

        $this->assertSame(['produtos_os' => 0, 'itens_de_vendas' => 0, 'produtos' => 0], $this->contagensDoProduto(7));
        $this->assertSame(['produtos_os' => 1, 'itens_de_vendas' => 1, 'produtos' => 1], $this->contagensDoProduto(8));
    }

    public function testFalhaNaExclusaoDoProdutoMantemTudo(): void
    {
        $model = $this->produtosPopulados();
        $this->falhaAntesDe('DELETE', 'produtos');

        $this->assertFalse($model->excluirComDependentes(7));

        $this->assertSame(['produtos_os' => 2, 'itens_de_vendas' => 1, 'produtos' => 1], $this->contagensDoProduto(7));
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_produto id=7');
    }

    public function testExclusaoDeProdutoInexistenteDevolveFalse(): void
    {
        $model = $this->produtosPopulados();

        $this->assertFalse($model->excluirComDependentes(99));

        $this->assertSame(['produtos_os' => 2, 'itens_de_vendas' => 1, 'produtos' => 1], $this->contagensDoProduto(7));
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_produto id=99');
    }

    public function testExclusaoDoServicoApagaSoOQueEDele(): void
    {
        $model = $this->servicosPopulados();

        $this->assertTrue($model->excluirComDependentes(4));

        $this->assertSame(['servicos_os' => 0, 'servicos' => 0], $this->contagensDoServico(4));
        $this->assertSame(['servicos_os' => 1, 'servicos' => 1], $this->contagensDoServico(5));
    }

    public function testFalhaNaExclusaoDoServicoMantemTudo(): void
    {
        $model = $this->servicosPopulados();
        $this->falhaAntesDe('DELETE', 'servicos');

        $this->assertFalse($model->excluirComDependentes(4));

        $this->assertSame(['servicos_os' => 3, 'servicos' => 1], $this->contagensDoServico(4));
        $this->assertLogContem('error', 'transacao rollback operacao=excluir_servico id=4');
    }
}
