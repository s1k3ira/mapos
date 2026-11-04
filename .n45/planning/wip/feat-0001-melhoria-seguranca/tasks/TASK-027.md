---
checkpoint: null
complexity: G
created_at: "2026-11-04 01:16:54"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testLimiteDiarioLidoDoEnv tests/Unit/LimiteDeOsDoClienteTest.php
      text: clienteOsLimiteDiario() devolve 10 sem a variável, 3 com '3', 0 com '0' e 10 com '', 'abc', '-2' e '2.5'
    - done: true
      test: application/vendor/bin/phpunit --filter testSegundosAteAmanha tests/Unit/LimiteDeOsDoClienteTest.php
      text: segundosAteAmanha() devolve 60 às 23:59:00 e 86400 às 00:00:00
    - done: true
      test: application/vendor/bin/phpunit --filter 'testAberturasDeHojeContaSoAsDoClienteNoDia|testAberturasSemHistoricoDevolveZero' tests/Unit/LimiteDeOsDoClienteTest.php
      text: aberturasDeOsHoje(7) conta só as aberturas do próprio cliente 7 desde 00:00 de hoje (2), ignorando a de ontem, a OS aberta pelo operador e a do cliente 8; sem os_historico devolve 0
    - done: true
      test: application/vendor/bin/phpunit --filter testAbrirOsAbaixoDoLimiteCriaOsEHistorico tests/Unit/LimiteDeOsDoClienteTest.php
      text: abrirOsDoCliente abaixo do limite cria a OS com clientes_id e uma linha de os_historico (status_anterior NULL, status_novo Aberto, usuarios_id NULL) e devolve ['ok'=>true,'id'=>id]
    - done: true
      test: application/vendor/bin/phpunit --filter 'testDecimaPrimeiraAberturaNaoCriaAOs|testLimiteZeroDesligaOLimite' tests/Unit/LimiteDeOsDoClienteTest.php
      text: abrirOsDoCliente no limite devolve ['ok'=>false,'motivo'=>'limite'] sem nenhuma OS nem histórico novo; com limite 0 cria mesmo com 15 aberturas no dia
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNoHistoricoDesfazAOs tests/Unit/LimiteDeOsDoClienteTest.php
      text: Falha no insert do histórico desfaz a OS (rollback), devolve motivo 'erro' e loga 'transacao rollback operacao=abrir_os_cliente id=7'
    - done: true
      test: grep -qE 'FOR UPDATE' application/models/Conecte_model.php
      text: O lock da linha do cliente é feito com SELECT ... FOR UPDATE isolado em travarCliente()
    - done: true
      test: application/vendor/bin/phpunit --filter testTecnicoComMenosOsRecebeANova tests/Unit/LimiteDeOsDoClienteTest.php
      text: tecnicoParaNovaOs() do Conecte_model devolve o usuário com menos OS (4)
    - done: true
      test: application/vendor/bin/phpunit --filter testApiAcimaDoLimiteResponde429ComRetryAfter tests/Unit/LimiteDeOsDoClienteTest.php
      text: 'POST /api/v1/client/os acima do limite responde 429 com a mensagem de limite e Retry-After: 60 às 23:59:00, sem criar OS'
    - done: true
      test: application/vendor/bin/phpunit --filter testApiAbaixoDoLimiteResponde201EGravaHistorico tests/Unit/LimiteDeOsDoClienteTest.php
      text: POST /api/v1/client/os abaixo do limite responde 201 com os_id e grava os_historico
    - done: true
      test: application/vendor/bin/phpunit --filter testApiComDadosInvalidosResponde422 tests/Unit/LimiteDeOsDoClienteTest.php
      text: POST /api/v1/client/os com descricaoProduto em branco responde 422 erros.descricaoProduto 'Informe o equipamento ou serviço.' e com defeito de 2001 caracteres responde 422 erros.defeito 'Use no máximo 2000 caracteres.'
    - done: true
      test: application/vendor/bin/phpunit --filter testApiComFalhaAoGravarResponde500 tests/Unit/LimiteDeOsDoClienteTest.php
      text: POST /api/v1/client/os com falha do model responde 500 'Ocorreu um erro ao criar a ordem de serviço.'
    - done: true
      test: '! grep -nE ''\$\{osId\}|REMOTE_ADDR'' application/controllers/api/v1/client/ClientOsController.php'
      text: ClientOsController não usa mais a interpolação ${} deprecada nem $_SERVER['REMOTE_ADDR'] no os_post
    - done: true
      test: application/vendor/bin/phpunit --filter testAdicionarOsNoLimiteMostraAMensagem tests/Unit/AreaDoClienteSessaoTest.php
      text: POST /mine/adicionarOs acima do limite re-renderiza conecte/adicionarOs com _geral = mensagem de limite e os valores preservados, sem OS nova e sem e-mail
    - done: true
      test: application/vendor/bin/phpunit --filter testAdicionarOsAbaixoDoLimiteCriaENotifica tests/Unit/AreaDoClienteSessaoTest.php
      text: POST /mine/adicionarOs abaixo do limite cria a OS, grava o histórico uma única vez, notifica a equipe e vai para mine/visualizarOs/<id>
    - done: true
      test: 'grep -qx ''CLIENTE_OS_LIMITE_DIARIO=10'' application/.env.example && php -r ''exit(parse_ini_file("application/.env.example", false, INI_SCANNER_RAW) === false ? 1 : 0);'''
      text: .env.example lista CLIENTE_OS_LIMITE_DIARIO=10 e continua legível por parse_ini_file
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/LimiteDeOsDoClienteTest.php tests/Unit/AreaDoClienteSessaoTest.php tests/Unit/AreaDoClienteTest.php tests/Unit/ApiClienteTokensTest.php tests/Unit/CookieSeguroTest.php
      text: Suítes da área do cliente, da API do cliente e do .env passam
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/models/Conecte_model.php application/helpers/conecte_helper.php application/controllers/Mine.php application/controllers/api/v1/client/ClientOsController.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-023
    - TASK-025
diff_base: c9d04791c0207cc7869f0f81b253d3aedfea22c0
diff_tip: c4ece49e9721056fa0a1699ba4e2fa1accfe1cc3
files:
    - application/helpers/conecte_helper.php
    - application/models/Conecte_model.php
    - application/controllers/Mine.php
    - application/controllers/api/v1/client/ClientOsController.php
    - application/.env.example
    - tests/Unit/LimiteDeOsDoClienteTest.php
    - tests/Unit/AreaDoClienteSessaoTest.php
id: TASK-027
linter: composer analyse
n45_version: 1.1.0
outcome: |
    - `POST /api/v1/client/os` (Bearer de cliente, JSON `descricaoProduto` obrigatório, `defeito`/`observacoes` opcionais, até 2000 chars): 201 `{status:true,message,os_id}` com histórico gravado; 422 `{status:false,message:'Dados inválidos.',erros:{campo:msg}}`; 429 com mensagem de limite + `Retry-After` (segundos até 00:00); 401/403 do `logged_client()`; 500 em falha de atribuição de técnico ou de gravação.
    - Tela `POST /mine/adicionarOs`: acima do limite re-renderiza `conecte/adicionarOs` com `_geral` = mensagem de limite e valores preservados; abaixo, cria OS, notifica equipe (`notificarEquipeDaNovaOs`) e redireciona para `mine/visualizarOs/<id>`.
    - `Conecte_model`: `LIMITE_OS_MENSAGEM`, `aberturasDeOsHoje(int): int`, `abrirOsDoCliente(int $idCliente, array $dados, int $limite): array` (`['ok'=>true,'id'=>int]` | `['ok'=>false,'motivo'=>'limite'|'erro']`, atômico com lock `travarCliente()` + OS + histórico), `tecnicoParaNovaOs(): ?int`.
    - `conecte_helper`: `clienteOsLimiteDiario(): int` (env `CLIENTE_OS_LIMITE_DIARIO`, padrão 10, 0 desliga), `segundosAteAmanha(?int): int`, `conecteErrosDaNovaOs(array): array`.
    - Config: `CLIENTE_OS_LIMITE_DIARIO` em `application/.env.example`.
persona: backend
phase: Phase 4 — Login por status, logout seguro e limite de OS do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/LimiteDeOsDoClienteTest.php tests/Unit/AreaDoClienteSessaoTest.php
title: Limite diário de abertura de OS pelo cliente na tela e na API
updated_at: "2026-11-04 06:12:50"
---
## Contexto

O cliente abre OS sem limite nenhum, pela tela (`POST /mine/adicionarOs`) e pela API (`POST /api/v1/client/os`): um script com uma conta de cliente enche a fila da assistência e dispara um e-mail para cada usuário da equipe por OS. Esta task impõe o limite diário `CLIENTE_OS_LIMITE_DIARIO` (padrão 10; `0` desliga), contado de forma **atômica** no dia-calendário de `APP_TIMEZONE` (RF-25): acima do limite, a API responde 429 com `Retry-After` (segundos até 00:00) e a tela mostra a mensagem no topo do formulário, sem criar a OS. A notificação por e-mail da equipe na tela é mantida, e a API passa a gravar o histórico de status como a tela já grava.

Estado atual relevante:

- `Mine::adicionarOs()` (`application/controllers/Mine.php`): valida com `validarRegras()` (`descricaoProduto` `required|trim|max_length[2000]`, `defeito` e `observacoes` `trim|max_length[2000]`), escolhe o técnico por `private function tecnicoParaNovaOs(): ?int` (o usuário com menos OS; sem OS, o primeiro com `situacao = 1`), grava por `$this->Conecte_model->add('os', $dados, true)`, chama `$this->os_model->registrarStatus((int) $idOs, null, 'Aberto', null)` (histórico com `usuarios_id = NULL` = aberta pelo cliente), envia `enviarOsPorEmail(...)` e redireciona com flash. Erro geral vai em `$erros['_geral']`, que a view `conecte/adicionarOs` já mostra num `alert` danger no topo do formulário, com os valores preservados.
- `ClientOsController::os_post()` (`application/controllers/api/v1/client/ClientOsController.php`): lê `$_POST` de `json_decode(file_get_contents('php://input'))`, valida com `form_validation` (400 em erro), duplica a escolha do técnico (com bug: `$usuario->idUsuarios` em `null`), grava por `Conecte_model::add()`, **não** grava histórico, audita com `"... OS Nº ${osId} pelo APP"` (interpolação `${}` deprecada no PHP 8.2+) e responde 201 `{status:true, message:'Ordem de serviço criada com sucesso.', os_id}`. Autenticação por `$this->logged_client()` (contrato entregue: 401 em toda falha de token, 403 `{code:'troca_de_senha_obrigatoria'}` com troca pendente; devolve objeto com `->usuario` = linha de `clientes`).
- `os_historico(idHistorico, os_id, status_anterior NULL, status_novo, usuarios_id NULL, data_hora DATETIME)` indexada por `os_id`. Abertura pelo cliente = `usuarios_id IS NULL AND status_anterior IS NULL AND status_novo = 'Aberto'`. OS aberta pelo operador grava `usuarios_id` do operador e não conta.
- `application/config/config.php` faz `date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo')`: `date()` do PHP já está no fuso da instalação, e `data_hora` é gravada por `date('Y-m-d H:i:s')`.
- Padrão de cabeçalho na API do cliente: `$this->output->set_header('Retry-After: ' . $segundos)` antes do `response()` (como `ClientLoginController::password_put`).

## Comportamento Esperado

Helpers (`application/helpers/conecte_helper.php`):

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `clienteOsLimiteDiario()` sem `CLIENTE_OS_LIMITE_DIARIO` no `$_ENV` | `10` |
| `$_ENV['CLIENTE_OS_LIMITE_DIARIO'] = '3'` | `3` |
| `'0'` | `0` (limite desligado) |
| `''`, `'abc'`, `'-2'`, `'2.5'` | `10` |
| `segundosAteAmanha(strtotime('2026-11-04 23:59:00'))` | `60` |
| `segundosAteAmanha(strtotime('2026-11-04 00:00:00'))` | `86400` |

Model (`Conecte_model`), cliente 7 com `CLIENTE_OS_LIMITE_DIARIO` = 2:

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `aberturasDeOsHoje(7)` com 2 aberturas do cliente hoje, 1 ontem (`data_hora` = ontem 23:59:59), 1 OS do cliente 7 aberta hoje pelo operador (`usuarios_id = 3`) e 1 abertura hoje do cliente 8 | `2` |
| `aberturasDeOsHoje(7)` sem a tabela `os_historico` | `0` |
| `abrirOsDoCliente(7, $dados, 2)` com 1 abertura hoje | `['ok' => true, 'id' => <idOs novo>]`; linha em `os` com `clientes_id = 7`; linha em `os_historico` (`status_anterior` NULL, `status_novo` `'Aberto'`, `usuarios_id` NULL) |
| `abrirOsDoCliente(7, $dados, 2)` com 2 aberturas hoje | `['ok' => false, 'motivo' => 'limite']`; nenhuma linha nova em `os` nem em `os_historico` |
| `abrirOsDoCliente(7, $dados, 0)` com 15 aberturas hoje | `['ok' => true, 'id' => ...]` (limite desligado) |
| insert do histórico falha (tabela `os_historico` sem a coluna `status_novo`, ou dublê) | `['ok' => false, 'motivo' => 'erro']`; rollback: nenhuma OS nova; log `error` `transacao rollback operacao=abrir_os_cliente id=7` |
| `tecnicoParaNovaOs()` com OS dos usuários 3 (2 OS) e 4 (1 OS) | `4` |

Tela — `POST /mine/adicionarOs` (cliente 7, limite 2):

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| 3ª abertura do dia com `descricaoProduto` = `Notebook não liga` | view `conecte/adicionarOs` com `erros['_geral']` = `"Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência."` e `valores['descricaoProduto']` = `Notebook não liga`; nenhuma OS nova; nenhum e-mail enviado |
| 1ª abertura do dia | OS criada, histórico gravado **uma** vez (pelo model), e-mail da equipe disparado, flash `success` `"OS #<id> aberta. A assistência vai entrar em contato."`, redirect `mine/visualizarOs/<id>` |

API — `POST /api/v1/client/os` (Bearer de cliente; corpo JSON):

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `{"descricaoProduto":"Impressora","defeito":"Não puxa papel"}`, 1ª do dia | 201 `{"status":true,"message":"Ordem de serviço criada com sucesso.","os_id":<int>}`; histórico gravado |
| mesma chamada acima do limite, às `23:59:00` | 429 `{"status":false,"message":"Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência."}` + `Retry-After: 60`; nenhuma OS |
| `{"descricaoProduto":"   "}` | 422 `{"status":false,"message":"Dados inválidos.","erros":{"descricaoProduto":"Informe o equipamento ou serviço."}}` |
| `defeito` com 2001 caracteres | 422 com `erros.defeito` = `"Use no máximo 2000 caracteres."` |
| sem técnico disponível (sem OS e sem usuário ativo) | 500 `{"status":false,"message":"Ocorreu um erro ao atribuir a ordem de serviço. Por favor, contate o administrador do sistema."}` |
| `abrirOsDoCliente` devolve `motivo = 'erro'` | 500 `{"status":false,"message":"Ocorreu um erro ao criar a ordem de serviço."}` |

## TDD

Arquivo novo `tests/Unit/LimiteDeOsDoClienteTest.php` (`final class LimiteDeOsDoClienteTest extends MaposTestCase`) para helpers, model e API; os casos da tela entram em `tests/Unit/AreaDoClienteSessaoTest.php`, que já tem o harness do `Mine` (`MineTestavel` com `irPara()`/`encerrar()` lançando `ParadaDoTeste`, `CarregadorDeTeste` capturando `view`/`dados`, `ValidacaoDeTeste`). Dublês novos sempre como **classe anônima** (nome repetido entre arquivos derruba a suíte com `Cannot redeclare class`). Restaurar `$_ENV['CLIENTE_OS_LIMITE_DIARIO']` no `tearDown()`.

SQLite não tem `SELECT ... FOR UPDATE`: o model isola o lock em `protected function travarCliente(int $idCliente): bool` e o teste usa uma subclasse anônima de `Conecte_model` que sobrescreve só esse método com um `SELECT` simples. Para a API, subclasse anônima de `ClientOsController` sobrescrevendo `response()` (lança `RuntimeException` com `json_encode(['code' => ..., 'data' => ...])`, como `ApiClienteTokensTest::controller()`), `logged_client()` (devolve `(object) ['usuario' => (object) ['idClientes' => 7, 'nomeCliente' => 'Ana']]`), `corpoJson()` (devolve o array do teste), `agora()` (relógio fixo) e `__destruct()` vazio; `output` dublê com `set_header()` acumulando os cabeçalhos.

```php
public function testDecimaPrimeiraAberturaNaoCriaAOs(): void
{
    $_ENV['CLIENTE_OS_LIMITE_DIARIO'] = '2';
    $this->aberturaHoje(7);
    $this->aberturaHoje(7);
    $antes = $this->db->count_all('os');

    $resultado = $this->model()->abrirOsDoCliente(7, $this->dadosDaOs(), clienteOsLimiteDiario());

    $this->assertSame(['ok' => false, 'motivo' => 'limite'], $resultado);
    $this->assertSame($antes, $this->db->count_all('os'));
}

public function testApiAcimaDoLimiteResponde429ComRetryAfter(): void
{
    $_ENV['CLIENTE_OS_LIMITE_DIARIO'] = '1';
    $this->aberturaHoje(7);
    $api = $this->api(['descricaoProduto' => 'Impressora'], strtotime(date('Y-m-d') . ' 23:59:00'));

    $resposta = $this->chamar($api, 'os_post');

    $this->assertSame(429, $resposta['code']);
    $this->assertSame('Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência.', $resposta['data']['message']);
    $this->assertContains('Retry-After: 60', $api->output->cabecalhos);
}
```

Cobrir os demais casos das tabelas com os nomes dos critérios. No `AreaDoClienteSessaoTest.php`, o `ConecteModelTestavel` já existente ganha o `travarCliente()` sobrescrito (SELECT sem `FOR UPDATE`) e o `MineTestavel` ganha o `notificarEquipeDaNovaOs()` sobrescrito com um contador público; o `setUp()` cria `os` e `os_historico` só nos testes novos (ou no `setUp`, se não quebrar os existentes).

**Refatoração:** depois do green, a escolha do técnico existe num lugar só (`Conecte_model::tecnicoParaNovaOs()`), usada pela tela e pela API; o `private tecnicoParaNovaOs()` do `Mine` e o bloco duplicado do `os_post()` saem.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/helpers/conecte_helper.php` | Modificar | `clienteOsLimiteDiario(): int`, `segundosAteAmanha(?int $agora = null): int`, `conecteErrosDaNovaOs(array $dados): array` |
| `application/models/Conecte_model.php` | Modificar | `LIMITE_OS_MENSAGEM`, `aberturasDeOsHoje()`, `abrirOsDoCliente()`, `travarCliente()`, `tecnicoParaNovaOs()` |
| `application/controllers/Mine.php` | Modificar | `adicionarOs()` por `abrirOsDoCliente()`; mensagem de limite; remove o `tecnicoParaNovaOs()` privado |
| `application/controllers/api/v1/client/ClientOsController.php` | Modificar | `os_post()` com validação 422, limite 429 + `Retry-After`, histórico, seams `corpoJson()`/`agora()` |
| `application/.env.example` | Modificar | `CLIENTE_OS_LIMITE_DIARIO=10` com comentário |
| `tests/Unit/LimiteDeOsDoClienteTest.php` | Criar | Helpers, model e API |
| `tests/Unit/AreaDoClienteSessaoTest.php` | Modificar | Casos da tela (`adicionarOs` no limite e abaixo dele) |

### Detalhamento Técnico

1. **`conecte_helper.php`** (cada função dentro de `if (! function_exists(...))`, como as vizinhas):
   - `clienteOsLimiteDiario(): int` — `$valor = trim((string) ($_ENV['CLIENTE_OS_LIMITE_DIARIO'] ?? ''));` `ctype_digit($valor) ? (int) $valor : 10`.
   - `segundosAteAmanha(?int $agora = null): int` — `$agora ??= time(); return max(1, strtotime('tomorrow', $agora) - $agora);` (fuso = o default do PHP, que é o `APP_TIMEZONE`).
   - `conecteErrosDaNovaOs(array $dados): array` — erros por campo da abertura pela API: `descricaoProduto` vazio depois de `trim` → `'Informe o equipamento ou serviço.'`; `descricaoProduto`, `defeito` ou `observacoes` com `mb_strlen(trim(...)) > 2000` → `'Use no máximo 2000 caracteres.'`. Valor não string conta como vazio. Sem erro → `[]`.
2. **`Conecte_model`**:

   ```php
   public const LIMITE_OS_MENSAGEM = 'Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência.';

   /** Aberturas de OS pelo próprio cliente desde 00:00 de hoje (fuso APP_TIMEZONE). */
   public function aberturasDeOsHoje(int $idCliente): int
   {
       if (! $this->db->table_exists('os_historico')) {
           return 0;
       }

       $linha = $this->db->query(
           'SELECT COUNT(*) AS total FROM os_historico h JOIN os ON os.idOs = h.os_id'
           . ' WHERE os.clientes_id = ? AND h.usuarios_id IS NULL AND h.status_anterior IS NULL'
           . " AND h.status_novo = 'Aberto' AND h.data_hora >= ?",
           [$idCliente, date('Y-m-d 00:00:00')]
       );

       return $linha ? (int) ($linha->row()->total ?? 0) : 0;
   }

   /** Trava a linha do cliente até o fim da transação: serializa aberturas simultâneas. */
   protected function travarCliente(int $idCliente): bool
   {
       $consulta = $this->db->query('SELECT idClientes FROM clientes WHERE idClientes = ? FOR UPDATE', [$idCliente]);

       return $consulta !== false && $consulta->row() !== null;
   }
   ```

   `abrirOsDoCliente(int $idCliente, array $dados, int $limite): array` — `trans_begin()`; dentro de `try`:
   1. `travarCliente()` falso → rollback, log, `['ok' => false, 'motivo' => 'erro']`;
   2. `$limite > 0 && aberturasDeOsHoje($idCliente) >= $limite` → rollback, `['ok' => false, 'motivo' => 'limite']` (sem log de erro: é regra, não falha);
   3. `$dados['clientes_id'] = $idCliente;` `insert('os', $dados)` falso → rollback + erro; `$id = (int) $this->db->insert_id();`
   4. se `table_exists('os_historico')`: `insert('os_historico', ['os_id' => $id, 'status_anterior' => null, 'status_novo' => 'Aberto', 'usuarios_id' => null, 'data_hora' => date('Y-m-d H:i:s')])` falso → rollback + erro;
   5. `trans_status() === false` → rollback + erro; senão `trans_commit()` e `['ok' => true, 'id' => $id]`.

   `catch (\Throwable)` → rollback + erro. Log de erro sempre `log_message('error', sprintf('transacao rollback operacao=abrir_os_cliente id=%d', $idCliente))` (o `trans_status()` não pega exceção com `db_debug` ligado em development, por isso as duas conferências).

   `public function tecnicoParaNovaOs(): ?int` — o corpo do método privado atual do `Mine` (mesmas queries), movido para cá.
3. **`Mine::adicionarOs()`**: com `$erros === []`, `$tecnico = $this->Conecte_model->tecnicoParaNovaOs()` (null → mesma mensagem de hoje); montar `$dados` como hoje (sem `clientes_id`, que o model põe) e `$resultado = $this->Conecte_model->abrirOsDoCliente($idCliente, $dados, clienteOsLimiteDiario())`.
   - `ok` → `$idOs = $resultado['id']`, e-mail da equipe e flash/redirect como hoje. **Não** chamar mais `registrarStatus` aqui (o model já gravou o histórico na transação).
   - `motivo === 'limite'` → `$erros['_geral'] = Conecte_model::LIMITE_OS_MENSAGEM;`
   - `motivo === 'erro'` → `$erros['_geral'] = 'Não foi possível abrir a OS. Tente de novo.';`
   - Para o teste da tela não mandar e-mail de verdade, extrair o envio para `protected function notificarEquipeDaNovaOs(int $idOs): void` (corpo = as linhas atuais que carregam `usuarios_model` e chamam `enviarOsPorEmail`); o `MineTestavel` do teste sobrescreve contando chamadas.
4. **`ClientOsController::os_post()`**: `$this->load->helper('conecte')` no construtor. Fluxo:
   1. `$clientLogged = $this->logged_client();` (uma vez; reutilizar o objeto, sem chamar de novo);
   2. `$corpo = $this->corpoJson();` → `protected function corpoJson(): array { $dados = json_decode((string) file_get_contents('php://input'), true); return is_array($dados) ? $dados : []; }` (remover o `header('Content-Type...')` manual, o `$_POST = ...` e o `form_validation`);
   3. `$erros = conecteErrosDaNovaOs($corpo)` não vazio → `response(['status' => false, 'message' => 'Dados inválidos.', 'erros' => $erros], HTTP_UNPROCESSABLE_ENTITY)`;
   4. técnico por `Conecte_model::tecnicoParaNovaOs()`; null → 500 com a mensagem de atribuição atual;
   5. `$dados` como hoje com `osTextoParaGravar((string) ($corpo['descricaoProduto'] ?? ''))` etc.; `abrirOsDoCliente($idCliente, $dados, clienteOsLimiteDiario())`;
   6. `limite` → `$this->output->set_header('Retry-After: ' . segundosAteAmanha($this->agora()));` + `response(['status' => false, 'message' => Conecte_model::LIMITE_OS_MENSAGEM], 429)`; `protected function agora(): int { return time(); }`;
   7. `erro` → 500 `'Ocorreu um erro ao criar a ordem de serviço.'`;
   8. `ok` → auditoria com `'Cliente ' . $clientLogged->usuario->nomeCliente . ' Adicionou uma OS Nº ' . $osId . ' pelo APP'` (concatenação, sem `${}`) e `$this->input->ip_address()` no lugar de `$_SERVER['REMOTE_ADDR']`; 201 como hoje.
5. **`.env.example`**: logo depois de `API_RATE_LIMIT=300`, acrescentar

   ```
   # Aberturas de OS por dia que cada cliente pode fazer na area do cliente e na API. 0 desliga.
   CLIENTE_OS_LIMITE_DIARIO=10
   ```

   O `CookieSeguroTest` lê o arquivo com `parse_ini_file(..., INI_SCANNER_RAW)`: no comentário, nada de parênteses, aspas, `!`, `{}`, `[]`, `&`, `|`, `~`, `^` (já quebrou a suíte uma vez nesta feature). Rodar o `CookieSeguroTest` depois.

**Contrato com camadas adjacentes:**

```
Conecte_model::abrirOsDoCliente(int $idCliente, array $dados, int $limite): array
  - Sucesso: ['ok' => true, 'id' => int]
  - Limite:  ['ok' => false, 'motivo' => 'limite']   → tela: _geral LIMITE_OS_MENSAGEM; API: 429 + Retry-After
  - Falha:   ['ok' => false, 'motivo' => 'erro']     (rollback + log) → tela: _geral genérico; API: 500
```

**Contrato HTTP:**

```
POST /api/v1/client/os
Authorization: Bearer <access token de cliente>
Content-Type: application/json

{
  "descricaoProduto": "Impressora",        // obrigatório, até 2000 chars
  "defeito": "Não puxa papel",             // opcional, até 2000 chars
  "observacoes": "Deixo na sexta"          // opcional, até 2000 chars
}

Response 201: {"status": true, "message": "Ordem de serviço criada com sucesso.", "os_id": 42}
Response 422: {"status": false, "message": "Dados inválidos.", "erros": {"descricaoProduto": "Informe o equipamento ou serviço."}}
Response 429: {"status": false, "message": "Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência."}   + Retry-After: <segundos até 00:00>
Response 401: {"status": false, "message": "Faça o login novamente!"}
Response 403: {"status": false, "code": "troca_de_senha_obrigatoria", "message": "Defina uma nova senha antes de continuar."}
Response 500: {"status": false, "message": "Ocorreu um erro ao criar a ordem de serviço."}
```
