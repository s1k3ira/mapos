---
checkpoint: null
complexity: M
created_at: "2026-11-03 18:41:20"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testSenhaAleatoria tests/Unit/SenhaClienteTest.php
      text: clienteSenhaAleatoria() devolve 16 caracteres só do alfabeto sem I, O, l, o, 0 e 1, e tamanho < 12 lança InvalidArgumentException
    - done: false
      test: application/vendor/bin/phpunit --filter testDuasSenhasSeguidasSaoDiferentes tests/Unit/SenhaClienteTest.php
      text: Duas chamadas seguidas de clienteSenhaAleatoria() devolvem valores diferentes
    - done: false
      test: grep -q 'random_int' application/helpers/clientes_helper.php && ! grep -nE '\b(mt_rand|str_shuffle)\(|[^_]rand\(' application/helpers/clientes_helper.php
      text: clienteSenhaAleatoria usa random_int (CSPRNG), nunca rand, mt_rand ou str_shuffle
    - done: false
      test: application/vendor/bin/phpunit --filter testEmitirGravaOTipo tests/Unit/SenhaClienteTest.php
      text: emitir(7, null, null, null, 'cliente') grava tipo = 'cliente'; sem tipo grava 'usuario'; tipo desconhecido lança InvalidArgumentException
    - done: false
      test: application/vendor/bin/phpunit --filter 'testRefreshDeClienteNaoGiraNaRotaDoOperador|testRefreshDeOperadorNaoGiraComoCliente' tests/Unit/SenhaClienteTest.php
      text: Refresh de cliente em girar() com tipo padrão devolve motivo 'invalido' sem marcar usado_em; refresh de operador com tipo 'cliente' idem
    - done: false
      test: application/vendor/bin/phpunit --filter testRefreshDeClienteGiraComTipoCliente tests/Unit/SenhaClienteTest.php
      text: girar() de cliente com tipo 'cliente' devolve ok, usuario_id 7 e o token novo nasce com tipo 'cliente' na mesma familia
    - done: false
      test: application/vendor/bin/phpunit --filter testReusoDoRefreshDoClienteRevogaAFamiliaELoga tests/Unit/SenhaClienteTest.php
      text: Reuso do refresh de cliente após a tolerância revoga a família e loga 'refresh reuso tipo=cliente dono=7'
    - done: false
      test: application/vendor/bin/phpunit --filter testRevogarDoUsuarioSoRevogaOTipoPedido tests/Unit/SenhaClienteTest.php
      text: revogarDoUsuario(7, 'cliente') revoga só o refresh tipo cliente do id 7 e mantém o do operador 7 com revogado_em NULL (e vice-versa)
    - done: false
      test: application/vendor/bin/phpunit --filter testSemColunaTipo tests/Unit/SenhaClienteTest.php
      text: Sem a coluna tipo, emitir(..., 'cliente') devolve null e loga a mensagem de migration; o refresh do operador segue funcionando
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaDeSenhaGravaHashIncrementaVersoesERevoga tests/Unit/SenhaClienteTest.php
      text: registrarTrocaDeSenha(5, hash, true) grava o hash, senha_trocar = 1, sessao_versao 2→3, token_versao 4→5 e revoga só o refresh tipo cliente do 5
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaDeSenhaSemExigirZeraSenhaTrocar tests/Unit/SenhaClienteTest.php
      text: registrarTrocaDeSenha(5, hash, false) zera senha_trocar
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaDeSenhaDeClienteInexistenteDevolveFalse tests/Unit/SenhaClienteTest.php
      text: registrarTrocaDeSenha de cliente inexistente devolve false sem revogar refresh
    - done: false
      test: application/vendor/bin/phpunit --filter testTrocaDeSenhaFalhaNoMeioDesfazTudo tests/Unit/SenhaClienteTest.php
      text: Falha de banco no meio da troca desfaz senha, versões e senha_trocar e loga 'transacao rollback operacao=troca_senha_cliente id=5'
    - done: false
      test: application/vendor/bin/phpunit tests/Unit/ApiAutenticacaoTest.php
      text: A suíte de autenticação do operador continua verde (tabela sem coluna tipo)
    - done: false
      test: grep -qE 'assertSame\(.*(sessao_versao|token_versao|\[.antigo., 0, 2, 4\])' tests/Unit/SenhaClienteTest.php
      text: Os testes da troca conferem os valores exatos das versões, não só que mudaram
deps:
    - TASK-001
    - TASK-002
files:
    - application/helpers/clientes_helper.php
    - application/libraries/Refresh_token.php
    - application/models/Clientes_model.php
    - tests/Unit/SenhaClienteTest.php
id: TASK-009
linter: composer analyse
n45_version: 1.1.0
outcome: ""
persona: backend
phase: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/SenhaClienteTest.php tests/Unit/ApiAutenticacaoTest.php
title: 'Senha do cliente: gerador, troca transacional e refresh por tipo'
updated_at: "2026-11-03 18:41:20"
---
## Contexto

A melhoria de segurança acaba com a senha inicial do cliente igual ao CPF/CNPJ e passa a revogar a sessão do cliente quando a senha muda. Esta task entrega as **três peças de base** que as tasks seguintes (cadastro pelo operador, API do cliente, área do cliente) vão consumir **sem alterar a assinatura**:

1. `clienteSenhaAleatoria()` em `application/helpers/clientes_helper.php` — senha forte por CSPRNG.
2. `Refresh_token` (`application/libraries/Refresh_token.php`) com o parâmetro `tipo` (`'usuario'` = operador, `'cliente'` = área do cliente), usando a coluna `api_refresh_tokens.tipo` que já existe.
3. `Clientes_model::registrarTrocaDeSenha()` (`application/models/Clientes_model.php`) — troca de senha transacional que derruba sessão web, access tokens e refresh tokens do cliente.

Estado atual relevante:

- Schema entregue pela migration (já aplicado): `api_refresh_tokens.tipo ENUM('usuario','cliente') NOT NULL DEFAULT 'usuario'` (linhas antigas = `'usuario'`), índice `api_refresh_tokens_dono (tipo, usuario_id)`; `clientes.senha_trocar TINYINT(1) NOT NULL DEFAULT 0`; `clientes.sessao_versao INT UNSIGNED NOT NULL DEFAULT 0`; `clientes.token_versao` já existia. A coluna `api_refresh_tokens.usuario_id` guarda o id do **dono** (operador ou cliente, conforme `tipo`).
- `Refresh_token` hoje não conhece `tipo`: `emitir(int $usuarioId, ?string $ip, ?string $userAgent, ?string $familia)`, `girar(string $token, ?string $ip, ?string $userAgent)` (retorna `['ok'=>true,'usuario_id'=>int,'familia'=>string,'refresh_token'=>string]` ou `['ok'=>false,'motivo'=>'invalido'|'revogado'|'reutilizado'|'expirado']`), `revogarDoUsuario(int $usuarioId)`. O construtor aceita `['db' => ..., 'relogio' => callable, 'duracao' => int]` para teste.
- Chamadores atuais (operador, `application/controllers/api/v1/UsuariosController.php`) chamam sem `tipo` e **não mudam**: o padrão `'usuario'` mantém o comportamento. Com o padrão, um refresh de cliente apresentado em `POST /api/v1/refresh` passa a ser recusado como `invalido` — isso já cumpre "refresh de cliente nunca é aceito na rota do operador".
- `tests/Unit/ApiAutenticacaoTest.php` cria `api_refresh_tokens` **sem** a coluna `tipo` e tem que continuar verde: por isso a biblioteca confere se a coluna existe (ver Detalhamento).
- `clientes_helper.php` já é carregado pelo `tests/bootstrap.php`. `Clientes_model::edit()` atual usa `$this->load` (não existe sob teste): o método novo **não** usa `$this->load`.

Fora de escopo aqui (tasks seguintes): trocar `clienteDadosDoFormulario()` para não usar mais o documento como senha, `excluirComDependentes()`, os controllers que chamam `registrarTrocaDeSenha()`, as rotas `client/refresh|logout|password`.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `clienteSenhaAleatoria()` | string de **16** caracteres, todos de `ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789` (sem `I`, `O`, `l`, `o`, `0`, `1`) |
| `clienteSenhaAleatoria()` chamado duas vezes seguidas | dois valores diferentes |
| `clienteSenhaAleatoria(24)` | 24 caracteres do mesmo alfabeto |
| `clienteSenhaAleatoria(8)` | `InvalidArgumentException('A senha aleatória tem pelo menos 12 caracteres.')` |
| `emitir(7, null, null, null, 'cliente')` | linha nova com `tipo = 'cliente'`, `usuario_id = 7`; devolve o token em claro |
| `emitir(7)` (sem tipo) | linha com `tipo = 'usuario'` (comportamento de hoje) |
| `emitir(7, null, null, null, 'admin')` | `InvalidArgumentException('Tipo de refresh token inválido.')` |
| `girar($tokenDeCliente)` (tipo padrão `'usuario'`) | `['ok' => false, 'motivo' => 'invalido']`; a linha **não** é marcada como usada (`usado_em` continua `NULL`) |
| `girar($tokenDeUsuario, null, null, 'cliente')` | `['ok' => false, 'motivo' => 'invalido']`, sem efeito |
| `girar($tokenDeCliente, null, null, 'cliente')` | `ok = true`, `usuario_id = 7`; o token novo nasce com `tipo = 'cliente'` e a mesma `familia` |
| Token de cliente já girado, apresentado de novo depois de `TOLERANCIA_SEGUNDOS` com tipo `'cliente'` | `motivo = 'reutilizado'`, família inteira revogada e log `error` contendo `refresh reuso tipo=cliente dono=7` |
| `revogarDoUsuario(7, 'cliente')` com refresh do usuário 7 **e** do cliente 7 | só a linha `tipo='cliente'` ganha `revogado_em`; a do operador 7 continua `NULL` |
| `revogarDoUsuario(7)` | só a linha `tipo='usuario'` é revogada |
| Tabela **sem** a coluna `tipo` (migration não rodada): `emitir(7, ..., 'cliente')` | `null`, sem inserir; log `error` `Refresh token do cliente desligado: rode as migrations (coluna api_refresh_tokens.tipo não existe).` |
| Tabela sem `tipo`: `emitir(7)` + `girar($t)` | funcionam como hoje (operador) |
| `registrarTrocaDeSenha(5, $hash, true)` com cliente 5 (`senha_trocar=0, sessao_versao=2, token_versao=4`) e refresh `tipo='cliente'` do 5 | `true`; `senha = $hash`, `senha_trocar = 1`, `sessao_versao = 3`, `token_versao = 5`; refresh do cliente 5 com `revogado_em` preenchido |
| `registrarTrocaDeSenha(5, $hash, false)` com `senha_trocar = 1` | `true`; `senha_trocar = 0` |
| `registrarTrocaDeSenha(5, ...)` com refresh `tipo='usuario'` e `usuario_id = 5` | o refresh do **operador** 5 continua com `revogado_em = NULL` |
| `registrarTrocaDeSenha(999, $hash, true)` (cliente inexistente) | `false`; nenhum refresh revogado |
| Falha de banco no meio (ex.: o UPDATE da revogação falha) | `false`; `clientes` volta ao estado anterior (senha, versões e `senha_trocar` intactos); log `error` contendo `transacao rollback operacao=troca_senha_cliente id=5` |

## TDD

Arquivo novo `tests/Unit/SenhaClienteTest.php` (`final class SenhaClienteTest extends MaposTestCase`). No `setUp()`, criar no SQLite:

```php
$this->db->query('CREATE TABLE api_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL DEFAULT \'usuario\', usuario_id INTEGER NOT NULL, familia CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, criado_em DATETIME NOT NULL, expira_em DATETIME NOT NULL, usado_em DATETIME NULL, revogado_em DATETIME NULL, ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL)');
$this->db->query('CREATE TABLE clientes (idClientes INTEGER PRIMARY KEY AUTOINCREMENT, nomeCliente TEXT, senha TEXT, senha_trocar INTEGER NOT NULL DEFAULT 0, sessao_versao INTEGER NOT NULL DEFAULT 0, token_versao INTEGER NOT NULL DEFAULT 0)');
```

`Refresh_token` construído com `new Refresh_token(['db' => $this->db, 'relogio' => fn () => $this->agora])` (ver `tests/Unit/ApiAutenticacaoTest.php`). Model: `$model = new Clientes_model(); $model->db = $this->db;` (o stub de `CI_Model` do bootstrap expõe `$db`).

Testes exemplares (estilo de asserção — valor exato, precondição assertada):

```php
public function testRefreshDeClienteNaoGiraNaRotaDoOperador(): void
{
    $refresh = $this->refresh();
    $token = $refresh->emitir(7, null, null, null, 'cliente');

    $this->assertSame(['ok' => false, 'motivo' => 'invalido'], $refresh->girar($token));
    $this->assertNull($this->db->get('api_refresh_tokens')->row()->usado_em, 'Recusa sem consumir o token do cliente.');
}

public function testTrocaDeSenhaFalhaNoMeioDesfazTudo(): void
{
    $this->db->query("INSERT INTO clientes (idClientes, senha, senha_trocar, sessao_versao, token_versao) VALUES (5, 'antigo', 0, 2, 4)");
    $this->refresh()->emitir(5, null, null, null, 'cliente');
    $this->db->query("CREATE TRIGGER falha_revogacao BEFORE UPDATE ON api_refresh_tokens BEGIN SELECT RAISE(ABORT, 'falha simulada'); END");

    $this->assertFalse($this->model()->registrarTrocaDeSenha(5, 'novo-hash', true));

    $cliente = $this->db->where('idClientes', 5)->get('clientes')->row();
    $this->assertSame(['antigo', 0, 2, 4], [$cliente->senha, (int) $cliente->senha_trocar, (int) $cliente->sessao_versao, (int) $cliente->token_versao]);
    $this->assertLogContem('error', 'transacao rollback operacao=troca_senha_cliente id=5');
}
```

Cobrir os demais casos da tabela com os nomes fixados nos critérios. Para o caso "sem coluna `tipo`", recriar a tabela sem a coluna dentro do próprio teste (`DROP TABLE` + `CREATE TABLE` igual à de `ApiAutenticacaoTest`).

**Refatoração:** Nenhuma além de manter o docblock do topo de `Refresh_token` atualizado com o `tipo`.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/helpers/clientes_helper.php` | Modificar | `clienteSenhaAleatoria(int $tamanho = 16): string` (guardada por `function_exists`, como as vizinhas) |
| `application/libraries/Refresh_token.php` | Modificar | parâmetro `tipo` em `emitir`, `girar`, `revogarDoUsuario`; detecção da coluna; log de reuso |
| `application/models/Clientes_model.php` | Modificar | `registrarTrocaDeSenha()` + `protected function refreshTokens(): Refresh_token` |
| `tests/Unit/SenhaClienteTest.php` | Criar | testes da tabela acima |

### Detalhamento Técnico

1. **`clienteSenhaAleatoria`** — `random_int(0, strlen($alfabeto) - 1)` por caractere (nunca `rand`/`mt_rand`/`str_shuffle`). Alfabeto em constante `CLIENTE_SENHA_ALFABETO` definida com `if (! defined(...)) define(...)`, como `CLIENTE_CAMPOS_TEXTO`. `$tamanho < 12` → `InvalidArgumentException`.

2. **Assinaturas fixadas (consumidas por outras tasks — não renomear):**

```php
// application/libraries/Refresh_token.php
public const TIPOS = ['usuario', 'cliente'];
public function emitir(int $donoId, ?string $ip = null, ?string $userAgent = null, ?string $familia = null, string $tipo = 'usuario'): ?string;
public function girar(string $token, ?string $ip = null, ?string $userAgent = null, string $tipo = 'usuario'): array; // chaves de retorno inalteradas: ok, motivo | usuario_id, familia, refresh_token
public function revogarDoUsuario(int $donoId, string $tipo = 'usuario'): void;
// inalterados: familiaDoToken(), familiaAtiva(), revogar(), hash(), duracao()

// application/models/Clientes_model.php
public function registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool;

// application/helpers/clientes_helper.php
function clienteSenhaAleatoria(int $tamanho = 16): string;
```

3. **Coluna `tipo` opcional** — propriedade `private ?bool $comTipo = null;` e `private function comTipo(): bool` que faz `$this->db->field_exists('tipo', self::TABELA)` uma vez (só depois de `ativo()`). Regras:
   - `emitir`: tipo fora de `TIPOS` → `InvalidArgumentException('Tipo de refresh token inválido.')`. Sem a coluna: `'cliente'` → log + `null`; `'usuario'` → insere sem a chave `tipo`. Com a coluna: insere `'tipo' => $tipo`.
   - `girar`: depois de achar a linha e **antes** de qualquer outra regra (revogado/usado/expirado), `$tipoDaLinha = $this->comTipo() ? (string) $linha->tipo : 'usuario'`; diferente do pedido → `['ok' => false, 'motivo' => 'invalido']` sem tocar no banco. O token novo é emitido com o mesmo `$tipo`.
   - No reuso depois da tolerância (onde hoje chama `revogarFamilia`): `log_message('error', sprintf('refresh reuso tipo=%s dono=%d', $tipo, (int) $linha->usuario_id));`. Nunca logar o token nem o hash.
   - `revogarDoUsuario`: com a coluna, `->where('tipo', $tipo)`; sem a coluna, `'cliente'` não faz nada e `'usuario'` age como hoje.

4. **`registrarTrocaDeSenha`** — no topo do arquivo do model, `require_once APPPATH . 'libraries/Refresh_token.php';`. O trecho crítico (ordem e rollback):

```php
public function registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool
{
    $this->db->trans_begin();

    try {
        $this->db->set('senha', $hash)
            ->set('senha_trocar', $exigirTroca ? 1 : 0)
            ->set('sessao_versao', 'sessao_versao + 1', false)
            ->set('token_versao', 'token_versao + 1', false)
            ->where('idClientes', $idCliente)
            ->update('clientes');
        $alterou = $this->db->affected_rows() === 1;

        if ($alterou) {
            $this->refreshTokens()->revogarDoUsuario($idCliente, 'cliente');
        }

        if ($alterou && $this->db->trans_status() !== false) {
            $this->db->trans_commit();

            return true;
        }
    } catch (\Throwable $e) {
        // Com db_debug ligado o CI já desfez a transação antes de lançar; o rollback abaixo vira no-op.
    }

    $this->db->trans_rollback();
    log_message('error', sprintf('transacao rollback operacao=troca_senha_cliente id=%d', $idCliente));

    return false;
}

protected function refreshTokens(): Refresh_token
{
    return new Refresh_token(['db' => $this->db]);
}
```

   Cliente inexistente também cai no rollback e loga a mesma linha (é o comportamento esperado: nada mudou). Nunca logar o hash.

**Contrato com camadas adjacentes:**

```
Produz para: controllers (Clientes, Mine, api/v1/ClientesController, api/v1/client/*)
  - registrarTrocaDeSenha: true = senha trocada, sessão web (sessao_versao), access tokens (token_versao) e refresh tipo cliente invalidados numa transação
  - false = nada mudou (cliente inexistente ou falha de banco já logada); o chamador mostra erro ao usuário
  - Refresh_token::girar(..., 'cliente'): mesmo formato de retorno do operador; motivo 'invalido' também para token de outro tipo
Consome de: banco (clientes, api_refresh_tokens) — mesma conexão, mesma transação
```
