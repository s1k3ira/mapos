<?php

require_once APPPATH . 'models/Clientes_model.php';
require_once APPPATH . 'controllers/Tools.php';

/**
 * Comando tools marcarSenhasLegadas: marca a troca obrigatória nos clientes
 * cuja senha ainda é o próprio documento sem pontuação.
 */
final class MarcarSenhasLegadasTest extends MaposTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, documento TEXT, senha TEXT NULL, senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)');
    }

    private function cliente(int $id, string $documento, ?string $senha, int $trocar = 0): void
    {
        $this->db->insert('clientes', [
            'idClientes' => $id,
            'nomeCliente' => 'Cliente ' . $id,
            'documento' => $documento,
            'senha' => $senha === null ? null : password_hash($senha, PASSWORD_BCRYPT, ['cost' => 4]),
            'senha_trocar' => $trocar,
        ]);
    }

    private function popular(): void
    {
        $this->cliente(1, '123.456.789-09', '12345678909');
        $this->cliente(2, '987.654.321-00', 'OutraSenha2026');
        $this->cliente(3, '11.222.333/0001-81', '11222333000181', 1);
        $this->cliente(4, '', 'qualquer');
        $this->cliente(5, '12.ABC.345/01DE-35', '12ABC34501DE35');
        $this->cliente(6, '555.666.777-88', null);
    }

    private function model(): Clientes_model
    {
        $model = new Clientes_model();
        $model->db = $this->db;

        return $model;
    }

    private function rodarComando(): string
    {
        $tools = $this->makeInstance(Tools::class);
        $tools->load = new class() {
            public function __call(string $nome, array $args)
            {
                return null;
            }
        };
        $tools->clientes_model = $this->model();

        ob_start();
        $tools->marcarSenhasLegadas();

        return (string) ob_get_clean();
    }

    private function marcados(): array
    {
        return array_map('intval', array_column($this->db->where('senha_trocar', 1)->order_by('idClientes')->get('clientes')->result_array(), 'idClientes'));
    }

    public function testMarcaSoQuemTemOProprioDocumentoComoSenha(): void
    {
        $this->popular();

        $this->assertSame(2, $this->model()->marcarSenhasLegadas());
        $this->assertSame([1, 3, 5], $this->marcados());
    }

    public function testSegundaExecucaoNaoMarcaDeNovo(): void
    {
        $this->popular();
        $model = $this->model();

        $this->assertSame(2, $model->marcarSenhasLegadas());
        $this->assertSame(0, $model->marcarSenhasLegadas());
    }

    public function testPaginaPorLotes(): void
    {
        $this->popular();

        $this->assertSame(2, $this->model()->marcarSenhasLegadas(2));
        $this->assertSame([1, 3, 5], $this->marcados());
    }

    public function testFalhaDeBancoDevolveFalseELoga(): void
    {
        $this->db->db_debug = false;
        $this->db->query('DROP TABLE clientes');
        set_error_handler(static fn () => true, E_WARNING);

        try {
            $this->assertFalse($this->model()->marcarSenhasLegadas());
            $this->assertLogContem('error', 'cliente marcar_senhas_legadas falha');

            $this->assertSame('Não foi possível marcar as senhas. Veja o log.' . PHP_EOL, $this->rodarComando());
        } finally {
            restore_error_handler();
        }
    }

    public function testComandoImprimeSoAQuantidade(): void
    {
        $this->cliente(1, '123.456.789-09', '12345678909');

        $saida = $this->rodarComando();

        $this->assertSame('1 cliente(s) marcado(s)' . PHP_EOL, $saida);
        $this->assertStringNotContainsString('12345678909', $saida);
        $this->assertStringNotContainsString('123.456.789-09', $saida);
        $this->assertStringNotContainsString('$2y$', $saida);
    }

    public function testAjudaListaOComando(): void
    {
        $tools = $this->makeInstance(Tools::class);

        ob_start();
        $tools->help();
        $saida = (string) ob_get_clean();

        $this->assertStringContainsString('php index.php tools marcarSenhasLegadas', $saida);
    }

    public function testMarcarNaoMexeNaSenhaNemNasVersoes(): void
    {
        $this->cliente(1, '123.456.789-09', '12345678909');
        $antes = $this->db->where('idClientes', 1)->get('clientes')->row_array();

        $this->assertSame(1, $this->model()->marcarSenhasLegadas());

        $depois = $this->db->where('idClientes', 1)->get('clientes')->row_array();
        $this->assertSame($antes['senha'], $depois['senha']);
        $this->assertSame($antes['sessao_versao'], $depois['sessao_versao']);
        $this->assertSame($antes['token_versao'], $depois['token_versao']);
        $this->assertSame(1, (int) $depois['senha_trocar']);
    }
}
