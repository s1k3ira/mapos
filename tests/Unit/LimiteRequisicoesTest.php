<?php

require_once APPPATH . 'libraries/Limite_requisicoes.php';

/**
 * Limite de requisições por IP e por minuto (#2869), contra um SQLite em
 * memória com a mesma tabela da migration de api_rate_limit.
 */
final class LimiteRequisicoesTest extends MaposTestCase
{
    private int $agora = 120;

    private function criarTabela(): void
    {
        $this->db->query('CREATE TABLE api_rate_limit (chave TEXT, janela INTEGER, total INTEGER DEFAULT 0, PRIMARY KEY (chave, janela))');
    }

    private function limite(): Limite_requisicoes
    {
        return new Limite_requisicoes([
            'db' => $this->db,
            'relogio' => function () {
                return $this->agora;
            },
            'segredo' => 'teste',
        ]);
    }

    public function testDuasChamadasLiberamEATerceiraDevolveOsSegundosAteAProximaJanela(): void
    {
        $this->criarTabela();
        $limite = $this->limite();

        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
        $this->assertSame(60, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
    }

    public function testTerceiraChamadaNoSegundo125DevolveCinquentaECinco(): void
    {
        $this->criarTabela();
        $limite = $this->limite();

        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
        $this->agora = 125;
        $this->assertSame(55, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
    }

    public function testLiberaNaJanelaSeguinte(): void
    {
        $this->criarTabela();
        $limite = $this->limite();

        $limite->segundosParaLiberar('api', '10.0.0.1', 1);
        $this->assertSame(60, $limite->segundosParaLiberar('api', '10.0.0.1', 1));
        $this->agora = 180;
        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 1));
    }

    public function testEscoposDiferentesNaoSomam(): void
    {
        $this->criarTabela();
        $limite = $this->limite();

        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
        $this->assertSame(0, $limite->segundosParaLiberar('webhook', '10.0.0.1', 2));
    }

    public function testIpsDiferentesNaoSomam(): void
    {
        $this->criarTabela();
        $limite = $this->limite();

        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 1));
        $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.2', 1));
    }

    public function testLimiteZeroDesligaENaoGravaNada(): void
    {
        $this->criarTabela();

        $this->assertSame(0, $this->limite()->segundosParaLiberar('api', '10.0.0.1', 0));
        $this->assertSame(0, $this->db->count_all('api_rate_limit'));
    }

    public function testSemATabelaDevolveZero(): void
    {
        $this->assertSame(0, $this->limite()->segundosParaLiberar('api', '10.0.0.1', 1));
    }

    public function testIpNuncaFicaEmClaroNaChave(): void
    {
        $this->criarTabela();
        $this->limite()->segundosParaLiberar('api', '10.0.0.1', 5);

        $chave = $this->db->get('api_rate_limit')->row('chave');

        $this->assertSame(hash_hmac('sha256', 'api|10.0.0.1', 'teste'), $chave);
    }
}
