<?php

require_once APPPATH . 'libraries/Refresh_token.php';
require_once APPPATH . 'models/Clientes_model.php';

/**
 * Peças de base da senha do cliente: gerador, refresh por tipo e troca
 * transacional de senha.
 */
final class SenhaClienteTest extends MaposTestCase
{
    private int $agora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agora = strtotime('2026-10-14 10:00:00');
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL DEFAULT \'usuario\', usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)');
        $this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, senha TEXT, senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)');
    }

    private function refresh(): Refresh_token
    {
        return new Refresh_token(['db' => $this->db, 'relogio' => fn () => $this->agora]);
    }

    private function model(): Clientes_model
    {
        $model = new Clientes_model();
        $model->db = $this->db;

        return $model;
    }

    private function cliente(int $id, int $trocar, int $sessao, int $token): void
    {
        $this->db->query("INSERT INTO clientes (idClientes, senha, senha_trocar, sessao_versao, token_versao) VALUES ({$id}, 'antigo', {$trocar}, {$sessao}, {$token})");
    }

    private function revogados(): array
    {
        $r = [];
        foreach ($this->db->order_by('id')->get('api_refresh_tokens')->result() as $linha) {
            $r[$linha->tipo . ':' . $linha->usuario_id] = $linha->revogado_em !== null;
        }

        return $r;
    }

    public function testSenhaAleatoria(): void
    {
        $senha = clienteSenhaAleatoria();

        $this->assertSame(16, strlen($senha));
        $this->assertSame(1, preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789]{16}$/', $senha));
        $this->assertSame(24, strlen(clienteSenhaAleatoria(24)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A senha aleatória tem pelo menos 12 caracteres.');
        clienteSenhaAleatoria(8);
    }

    public function testDuasSenhasSeguidasSaoDiferentes(): void
    {
        $this->assertNotSame(clienteSenhaAleatoria(), clienteSenhaAleatoria());
    }

    public function testEmitirGravaOTipo(): void
    {
        $refresh = $this->refresh();
        $refresh->emitir(7, null, null, null, 'cliente');
        $refresh->emitir(7);

        $tipos = array_map(static fn ($l) => $l->tipo, $this->db->order_by('id')->get('api_refresh_tokens')->result());
        $this->assertSame(['cliente', 'usuario'], $tipos);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tipo de refresh token inválido.');
        $refresh->emitir(7, null, null, null, 'admin');
    }

    public function testRefreshDeClienteNaoGiraNaRotaDoOperador(): void
    {
        $refresh = $this->refresh();
        $token = $refresh->emitir(7, null, null, null, 'cliente');

        $this->assertSame(['ok' => false, 'motivo' => 'invalido'], $refresh->girar($token));
        $this->assertNull($this->db->get('api_refresh_tokens')->row()->usado_em, 'Recusa sem consumir o token do cliente.');
    }

    public function testRefreshDeOperadorNaoGiraComoCliente(): void
    {
        $refresh = $this->refresh();
        $token = $refresh->emitir(7);

        $this->assertSame(['ok' => false, 'motivo' => 'invalido'], $refresh->girar($token, null, null, 'cliente'));
        $this->assertNull($this->db->get('api_refresh_tokens')->row()->usado_em);
    }

    public function testRefreshDeClienteGiraComTipoCliente(): void
    {
        $refresh = $this->refresh();
        $token = $refresh->emitir(7, null, null, null, 'cliente');
        $familia = $this->db->get('api_refresh_tokens')->row()->familia;

        $r = $refresh->girar($token, null, null, 'cliente');

        $this->assertTrue($r['ok']);
        $this->assertSame(7, $r['usuario_id']);
        $this->assertSame($familia, $r['familia']);
        $linhas = $this->db->order_by('id')->get('api_refresh_tokens')->result();
        $this->assertSame(2, count($linhas));
        $this->assertSame(['cliente', $familia], [$linhas[1]->tipo, $linhas[1]->familia]);
    }

    public function testReusoDoRefreshDoClienteRevogaAFamiliaELoga(): void
    {
        $refresh = $this->refresh();
        $token = $refresh->emitir(7, null, null, null, 'cliente');
        $this->assertTrue($refresh->girar($token, null, null, 'cliente')['ok']);

        $this->agora += Refresh_token::TOLERANCIA_SEGUNDOS + 5;

        $this->assertSame(['ok' => false, 'motivo' => 'reutilizado'], $refresh->girar($token, null, null, 'cliente'));
        $this->assertSame([true, true], array_map(static fn ($l) => $l->revogado_em !== null, $this->db->order_by('id')->get('api_refresh_tokens')->result()));
        $this->assertLogContem('error', 'refresh reuso tipo=cliente dono=7');
    }

    public function testRevogarDoUsuarioSoRevogaOTipoPedido(): void
    {
        $refresh = $this->refresh();
        $refresh->emitir(7);
        $refresh->emitir(7, null, null, null, 'cliente');

        $refresh->revogarDoUsuario(7, 'cliente');
        $this->assertSame(['usuario:7' => false, 'cliente:7' => true], $this->revogados());

        $this->db->query('UPDATE api_refresh_tokens SET revogado_em = NULL');
        $refresh->revogarDoUsuario(7);
        $this->assertSame(['usuario:7' => true, 'cliente:7' => false], $this->revogados());
    }

    public function testSemColunaTipo(): void
    {
        $this->db->query('DROP TABLE api_refresh_tokens');
        $this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)');
        $refresh = $this->refresh();

        $this->assertNull($refresh->emitir(7, null, null, null, 'cliente'));
        $this->assertSame(0, $this->db->count_all_results('api_refresh_tokens'));
        $this->assertLogContem('error', 'Refresh token do cliente desligado: rode as migrations (coluna api_refresh_tokens.tipo não existe).');

        $token = $refresh->emitir(7);
        $this->assertNotNull($token);
        $r = $refresh->girar($token);
        $this->assertTrue($r['ok']);
        $this->assertSame(7, $r['usuario_id']);
    }

    public function testTrocaDeSenhaGravaHashIncrementaVersoesERevoga(): void
    {
        $this->cliente(5, 0, 2, 4);
        $refresh = $this->refresh();
        $refresh->emitir(5, null, null, null, 'cliente');
        $refresh->emitir(5);

        $this->assertTrue($this->model()->registrarTrocaDeSenha(5, 'novo-hash', true));

        $c = $this->db->where('idClientes', 5)->get('clientes')->row();
        $this->assertSame(['novo-hash', 1, 3, 5], [$c->senha, (int) $c->senha_trocar, (int) $c->sessao_versao, (int) $c->token_versao]);
        $this->assertSame(['cliente:5' => true, 'usuario:5' => false], $this->revogados());
    }

    public function testTrocaDeSenhaSemExigirZeraSenhaTrocar(): void
    {
        $this->cliente(5, 1, 0, 0);

        $this->assertTrue($this->model()->registrarTrocaDeSenha(5, 'h', false));

        $this->assertSame(0, (int) $this->db->where('idClientes', 5)->get('clientes')->row()->senha_trocar);
    }

    public function testTrocaDeSenhaDeClienteInexistenteDevolveFalse(): void
    {
        $this->cliente(5, 0, 0, 0);
        $this->refresh()->emitir(999, null, null, null, 'cliente');

        $this->assertFalse($this->model()->registrarTrocaDeSenha(999, 'h', true));

        $this->assertSame(['cliente:999' => false], $this->revogados());
    }

    public function testTrocaDeSenhaFalhaNoMeioDesfazTudo(): void
    {
        $this->db->query("INSERT INTO clientes (idClientes, senha, senha_trocar, sessao_versao, token_versao) VALUES (5, 'antigo', 0, 2, 4)");
        $this->refresh()->emitir(5, null, null, null, 'cliente');
        $this->db->query("CREATE TRIGGER falha_revogacao BEFORE UPDATE ON api_refresh_tokens BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

        $this->db->db_debug = false;
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $this->assertFalse($this->model()->registrarTrocaDeSenha(5, 'novo-hash', true));
        } finally {
            restore_error_handler();
        }

        $cliente = $this->db->where('idClientes', 5)->get('clientes')->row();
        $this->assertSame(['antigo', 0, 2, 4], [$cliente->senha, (int) $cliente->senha_trocar, (int) $cliente->sessao_versao, (int) $cliente->token_versao]);
        $this->assertLogContem('error', 'transacao rollback operacao=troca_senha_cliente id=5');
    }
}
