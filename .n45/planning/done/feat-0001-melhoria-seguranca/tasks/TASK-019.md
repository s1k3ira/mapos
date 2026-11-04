---
checkpoint: null
complexity: G
created_at: "2026-11-03 22:52:58"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testNovoSemSenhaUsaASenhaGeradaENuncaODocumento tests/Unit/ClientesSenhaInicialTest.php
      text: clienteDadosDoFormulario(novo, sem senha, senha gerada 'Abc23456789Defgh') grava o hash da senha gerada e password_verify('12345678909') é falso
    - done: true
      test: application/vendor/bin/phpunit --filter testNovoSemSenhaNemGeradaFicaComSenhaDesconhecida tests/Unit/ClientesSenhaInicialTest.php
      text: clienteDadosDoFormulario(novo, sem senha, sem senha gerada) não aceita o documento nem string vazia como senha
    - done: true
      test: application/vendor/bin/phpunit --filter testCadastroSemSenhaMostraASenhaUmaVezEMarcaTroca tests/Unit/ClientesSenhaInicialTest.php
      text: POST clientes/adicionar sem senha grava senha_trocar 1, renderiza clientes/senha_inicial com senha de 16 caracteres do alfabeto, emailEnviado true, Cache-Control no-store, sem flashdata e sem a senha em log
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNoEnvioMostraTextoDeFalha tests/Unit/ClientesSenhaInicialTest.php
      text: Falha no envio do e-mail mostra a tela com emailEnviado false
    - done: true
      test: application/vendor/bin/phpunit --filter testCadastroComSenhaDigitadaMarcaTrocaERedireciona tests/Unit/ClientesSenhaInicialTest.php
      text: Cadastro com senha digitada grava senha_trocar 1, hash da senha digitada e redireciona sem tela de senha nem envio
    - done: true
      test: application/vendor/bin/phpunit --filter testCadastroInvalidoNaoGeraNemEnviaSenha tests/Unit/ClientesSenhaInicialTest.php
      text: Cadastro com erro de validação não grava cliente nem chama o envio
    - done: true
      test: application/vendor/bin/phpunit --filter testCadastroRapidoGeraSenhaSemExibirNemEnviar tests/Unit/ClientesSenhaInicialTest.php
      text: Cadastro rápido grava senha_trocar 1, senha diferente do documento, 201 sem senha no corpo e sem chamar o envio
    - done: true
      test: application/vendor/bin/phpunit --filter testSenhaInicialNaoPassaPelaFila tests/Unit/ClientesSenhaInicialTest.php
      text: Nenhuma linha é criada em email_queue no cadastro com senha gerada
    - done: true
      test: application/vendor/bin/phpunit --filter testEdicaoComSenhaDerrubaSessoesEExigeTroca tests/Unit/ClientesSenhaInicialTest.php
      text: 'Edição pelo operador com senha passa por registrarTrocaDeSenha: senha_trocar 1, sessao_versao e token_versao +1 e refresh tipo cliente revogado'
    - done: true
      test: application/vendor/bin/phpunit --filter testEdicaoSemSenhaNaoMexeNasVersoes tests/Unit/ClientesSenhaInicialTest.php
      text: Edição sem senha mantém sessao_versao e token_versao
    - done: true
      test: application/vendor/bin/phpunit --filter testEnvioSemEmailOuSemEmitenteNaoChamaSend tests/Unit/ClientesSenhaInicialTest.php
      text: clienteEnviarSenhaInicial devolve false sem chamar send quando o cliente não tem e-mail válido ou não há emitente
    - done: true
      test: application/vendor/bin/phpunit --filter testEnvioComExcecaoLogaSemASenha tests/Unit/ClientesSenhaInicialTest.php
      text: clienteEnviarSenhaInicial com exceção no send devolve false e loga 'email senha_inicial falha cliente=' sem o HTML
    - done: true
      test: grep -qE 'send\(true\)' application/helpers/clientes_helper.php
      text: clienteEnviarSenhaInicial envia pelo SMTP direto (send(true)), fora da fila
    - done: true
      test: application/vendor/bin/phpunit --filter testExclusaoDoClienteApagaTudoDeleERevogaRefresh tests/Unit/ClientesSenhaInicialTest.php
      text: Clientes_model::excluirComDependentes(3) apaga cliente, OS, itens, vendas e itens de venda do cliente 3, mantém o cliente 4 e revoga só os refresh tipo cliente do 3
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNaExclusaoDoClienteMantemTudo tests/Unit/ClientesSenhaInicialTest.php
      text: Falha no DELETE de clientes devolve false, mantém tudo, não revoga refresh e loga 'transacao rollback operacao=excluir_cliente id=3'
    - done: true
      test: application/vendor/bin/phpunit --filter testRemoverOsDoClienteComFalhaLogaEDevolveFalse tests/Unit/ClientesSenhaInicialTest.php
      text: removeClientOs com falha no DELETE de os devolve false e loga 'cliente remover_os falha os='
    - done: true
      test: '! grep -nE ''catch \(Exception'' application/models/Clientes_model.php'
      text: Clientes_model não engole mais erro em catch (Exception)
    - done: true
      test: grep -q 'clientes_model->excluirComDependentes(' application/controllers/Clientes.php && grep -q 'Não foi possível excluir. Nada foi alterado.' application/controllers/Clientes.php
      text: Clientes::excluir usa excluirComDependentes e mostra 'Não foi possível excluir. Nada foi alterado.' na falha
    - done: true
      test: '! grep -nE ''password_hash\(\$documento'' application/helpers/clientes_helper.php'
      text: O documento não é mais usado como senha no helper
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/ClientesFormularioTest.php tests/Unit/ClientesCadastroRapidoTest.php tests/Unit/ClientesSenhaInicialTest.php tests/Unit/SenhaInicialViewTest.php tests/Unit/AreaDoClienteTest.php
      text: Testes antigos de cliente atualizados passam (sem senha-documento)
    - done: true
      test: php scripts/check-escape.php && php scripts/check-inline-script.php
      text: View do e-mail passa nos guards sem subir baseline
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/helpers/clientes_helper.php application/controllers/Clientes.php application/models/Clientes_model.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-009
    - TASK-008
diff_base: 1c5a547b582c703e9f0a4a8250c9f6950a33359f
diff_tip: b59833a0af0d31639ab12939859239dd9492718b
files:
    - application/helpers/clientes_helper.php
    - application/controllers/Clientes.php
    - application/models/Clientes_model.php
    - application/views/clientes/emails/senha_inicial.php
    - tests/Unit/ClientesSenhaInicialTest.php
    - tests/Unit/ClientesFormularioTest.php
    - tests/Unit/ClientesCadastroRapidoTest.php
id: TASK-019
linter: composer analyse
n45_version: 1.1.0
outcome: |
    # TASK-019 — Outcome

    **Helper `application/helpers/clientes_helper.php`**
    - `clienteDadosDoFormulario($post, bool $novo, ?string $senhaGerada = null): array` — cadastro novo sem senha grava o hash de `$senhaGerada` (ou de valor aleatório desconhecido se ausente); o documento nunca é a senha. Não grava `senha_trocar` (quem marca é o controller).
    - `clienteEnviarSenhaInicial(object $email, ?object $emitente, object $cliente, string $html): bool` — envio síncrono via `send(true)` (fora de `email_queue`). `false` sem e-mail válido, sem emitente ou em falha/exceção; log de falha `email senha_inicial falha cliente=<id> erro=<Classe|envio>`, sem HTML, senha ou e-mail.

    **Model `Clientes_model`**
    - `excluirComDependentes(int $idCliente): bool` — transação: apaga OS (servicos_os, produtos_os, anotacoes_os e os_historico quando existem), vendas, itens_de_vendas e o cliente; revoga refresh tokens `tipo='cliente'` do cliente. `false` + log `transacao rollback operacao=excluir_cliente id=<id>` em falha ou cliente inexistente (nada muda).
    - `removeClientOs()` / `removeClientVendas()` devolvem `false` com log (`cliente remover_os falha os=<id>` / `cliente remover_venda falha venda=<id>`); sem `catch (Exception)`.

    **Controller `Clientes`**
    - `POST clientes/adicionar` sem senha: grava `senha_trocar=1`, renderiza `clientes/senha_inicial` (`cliente`, `senhaInicial` 16 chars, `emailEnviado`) com `Cache-Control: no-store`, sem redirect/flashdata, e-mail enviado síncrono. Com senha digitada: `senha_trocar=1`, redirect com flashdata `Cliente cadastrado com sucesso!`.
    - `POST clientes/cadastroRapido`: 201 inalterado, senha aleatória, `senha_trocar=1`, sem exibir nem enviar.
    - `POST clientes/editar/{id}` com senha: `registrarTrocaDeSenha($id, $hash, true)`; falha → erro `_geral` `Não foi possível salvar a senha. Tente de novo.`
    - `POST clientes/excluir`: usa `excluirComDependentes`; falha → flashdata `Não foi possível excluir. Nada foi alterado.`

    **View** `application/views/clientes/emails/senha_inicial.php` (`$cliente`, `$emitente`, `$senha`, `$urlAcesso`, escapada com `e()`).
persona: backend
phase: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/ClientesSenhaInicialTest.php tests/Unit/ClientesFormularioTest.php tests/Unit/ClientesCadastroRapidoTest.php tests/Unit/SenhaInicialViewTest.php tests/Unit/AreaDoClienteTest.php tests/Unit/SenhaClienteTest.php
title: Cadastro de cliente pelo operador com senha inicial e exclusão em transação
updated_at: "2026-11-03 23:46:27"
---
## Contexto

Hoje o cliente cadastrado pelo operador sem senha recebe como senha da área do cliente o próprio CPF/CNPJ sem pontuação (`clienteDadosDoFormulario()` em `application/helpers/clientes_helper.php`), o que qualquer pessoa com o documento adivinha. A auditoria também achou a exclusão de cliente no painel sem transação (`Clientes::excluir()` chama `removeClientOs()`, `removeClientVendas()` e `delete()` soltos e mostra sucesso sempre) e dois `catch (Exception)` em `Clientes_model` (`removeClientOs`, `removeClientVendas`) que nunca disparam com `db_debug` desligado.

Esta task troca, no **painel** (formulário `clientes/adicionar` e cadastro rápido do modal da OS/venda), a senha-documento por uma senha aleatória exibida uma única vez ao operador e enviada por e-mail **síncrono** ao cliente, marca `senha_trocar = 1` em todo cliente criado pelo operador, passa a troca de senha feita pelo operador na edição por `Clientes_model::registrarTrocaDeSenha()` e faz a exclusão em transação. A API de clientes (TASK-022) consome o helper de envio e o `excluirComDependentes()` criados aqui — as assinaturas abaixo são contrato.

Contratos já entregues que esta task consome (tasks done):

- `clienteSenhaAleatoria(int $tamanho = 16): string` (`clientes_helper.php`, TASK-009): CSPRNG, alfabeto `CLIENTE_SENHA_ALFABETO` = `ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789`.
- `Clientes_model::registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool` (TASK-009): transação que grava `senha`, `senha_trocar`, incrementa `sessao_versao` e `token_versao` e revoga os refresh tokens `tipo = 'cliente'`; `false` = nada mudou (log `transacao rollback operacao=troca_senha_cliente id=N`).
- `Clientes_model::refreshTokens(): Refresh_token` (protected) e `Refresh_token::revogarDoUsuario(int $donoId, string $tipo = 'usuario'): void` (TASK-009).
- View `application/views/clientes/senha_inicial.php` (TASK-008): variáveis `$cliente` (objeto com `idClientes`, `nomeCliente`), `$senhaInicial` (string), `$emailEnviado` (bool). É só o conteúdo (`.v5-conteudo`); o controller usa o layout (`$this->data['view'] = 'clientes/senha_inicial'; return $this->layout();`) e responde com `Cache-Control: no-store`. O texto de envio e o Copiar já estão na view.
- Colunas `clientes.senha_trocar TINYINT(1) DEFAULT 0` e `clientes.sessao_versao INT UNSIGNED DEFAULT 0` (TASK-002).

Regras da feature que valem aqui:

- A senha em claro **nunca** vai para log (`log_message`/`log_info`), sessão, flashdata, `email_queue` ou banco. Por isso o e-mail é síncrono: `MY_Email::send(true)` envia direto pelo SMTP, sem passar pela fila.
- A tela da senha é renderizada **na resposta do POST** (sem redirect, sem flashdata): flashdata é gravado em `ci_sessions` no banco. Recarregar a página reenvia o POST, que o CSRF regenerado recusa.
- Cadastro rápido (modal) gera a senha aleatória e `senha_trocar = 1`, mas **não exibe nem envia** (o cliente usa "Esqueci a senha").
- O cadastro do próprio cliente na área do cliente (`conecteDadosDoCliente()` em `conecte_helper.php`, que reaproveita `clienteDadosDoFormulario()`) **não** muda: senha digitada pelo cliente, sem `senha_trocar`.
- `Clientes_model::edit()` continua chamando `incrementarVersao` (há teste de código que procura essa chamada em `ApiAutenticacaoTest`).
- Formato de log da feature: `transacao rollback operacao=<op> id=<id>`. Nunca logar e-mail completo, CPF/CNPJ ou senha.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `clienteDadosDoFormulario(['documento' => '123.456.789-09', 'senha' => ''] + POST, true, 'Abc23456789Defgh')` | `password_verify('Abc23456789Defgh', $dados['senha'])` = `true`; `password_verify('12345678909', $dados['senha'])` = `false` |
| `clienteDadosDoFormulario(['senha' => ''] + POST, true)` (sem senha gerada) | `senha` = hash de valor aleatório: `password_verify('12345678909', ...)` = `false` e `password_verify('', ...)` = `false` |
| `clienteDadosDoFormulario(['senha' => 'digitada123'] + POST, true)` | `password_verify('digitada123', $dados['senha'])` = `true`; sem chave `senha_trocar` (quem marca é o controller) |
| POST `clientes/adicionar` sem senha, e-mail `ana@x.com`, envio OK | 1 linha em `clientes` com `senha_trocar = 1` e `password_verify(<documento>, senha) = false`; resposta renderiza `clientes/senha_inicial` com `senhaInicial` de 16 caracteres do alfabeto e `emailEnviado = true`; header `Cache-Control: no-store`; nenhuma linha em `email_queue`; nenhum flashdata `success` |
| POST `clientes/adicionar` sem senha e envio falha (SMTP) | mesma tela com `emailEnviado = false`; log `error` contém `email senha_inicial falha cliente=<id>`; a senha não aparece em nenhum log |
| POST `clientes/adicionar` **com** senha `digitada123` | `senha_trocar = 1`; `password_verify('digitada123', senha) = true`; redirect para `clientes` com flashdata `success` `Cliente cadastrado com sucesso!` (sem tela de senha, sem e-mail) |
| POST `clientes/adicionar` com erro de validação | formulário volta com valores e erros; **nenhum** cliente gravado; nenhuma senha gerada/enviada |
| POST `clientes/cadastroRapido` (sem campo senha) | 201 com o mesmo corpo de hoje (sem senha nem hash); `senha_trocar = 1`; `password_verify('12345678909', senha) = false`; envio de e-mail **não** chamado |
| POST `clientes/editar/5` com senha `outra12345` | `registrarTrocaDeSenha(5, <hash de outra12345>, true)`: `senha_trocar = 1`, `sessao_versao` e `token_versao` +1, refresh tokens `tipo='cliente'` do 5 revogados |
| POST `clientes/editar/5` sem senha | `sessao_versao` e `token_versao` inalterados; `registrarTrocaDeSenha` não é chamado |
| POST `clientes/editar/5` com senha e `registrarTrocaDeSenha` devolvendo `false` | formulário volta com erro `_geral` = `Não foi possível salvar a senha. Tente de novo.` |
| `clienteEnviarSenhaInicial($mailer, $emitente, $cliente, '<html>')` com `$cliente->email = ''` ou inválido | `false`; `send` não é chamado |
| `clienteEnviarSenhaInicial(...)` com `$emitente = null` | `false`; `send` não é chamado |
| `clienteEnviarSenhaInicial(...)` com `send(true)` lançando `RuntimeException` | `false`; log `error` `email senha_inicial falha cliente=<id> erro=RuntimeException`; o HTML/senha não aparece no log |
| `Clientes_model::excluirComDependentes(3)` com cliente 3 dono de 2 OS (com `servicos_os`/`produtos_os`) e 1 venda (com `itens_de_vendas`), cliente 4 com 1 OS | `true`; nada do cliente 3 sobra em `clientes`, `os`, `servicos_os`, `produtos_os`, `vendas`, `itens_de_vendas`; dados do cliente 4 intactos; refresh tokens `tipo='cliente'` do 3 revogados (`revogado_em` preenchido) e os `tipo='usuario'` com `usuario_id = 3` intactos |
| `excluirComDependentes(3)` com falha simulada no `DELETE` de `clientes` | `false`; OS, itens, vendas e o cliente 3 continuam; refresh tokens dele **não** revogados; log `error` contém `transacao rollback operacao=excluir_cliente id=3` |
| `excluirComDependentes(99)` (inexistente) | `false`; log `transacao rollback operacao=excluir_cliente id=99` |
| POST `clientes/excluir` com model devolvendo `false` | flashdata `error` = `Não foi possível excluir. Nada foi alterado.`; sem `log_info` |
| `removeClientOs([...])` com falha simulada no `DELETE` de `os` | `false` e log `error` contém `cliente remover_os falha os=<id>` (sem `catch (Exception)` engolindo) |

## TDD

Suíte nova `tests/Unit/ClientesSenhaInicialTest.php` (classe `final`, `MaposTestCase`, SQLite em memória). Controller exercitado como em `tests/Unit/ClientesCadastroRapidoTest.php` (subclasse anônima de `Clientes` com `input`, `output`, `session`, `clientes_model` reais/stub). O envio é isolado no método `protected function enviarSenhaInicial(object $cliente, string $senha): bool` do controller, sobrescrito no teste para registrar a chamada e devolver `true`/`false`; o `layout()` é sobrescrito para capturar `$this->data`.

```php
public function testCadastroSemSenhaMostraASenhaUmaVezEMarcaTroca(): void
{
    $controller = $this->controllerDeCadastro(['documento' => '123.456.789-09', 'senha' => ''] + self::POST, enviou: true);

    $controller->adicionar();

    $gravado = $this->db->query('SELECT senha, senha_trocar FROM clientes')->row();
    $this->assertSame(1, (int) $gravado->senha_trocar);
    $this->assertFalse(password_verify('12345678909', $gravado->senha));
    $this->assertSame('clientes/senha_inicial', $controller->data['view']);
    $this->assertSame(16, strlen($controller->data['senhaInicial']));
    $this->assertMatchesRegularExpression('/^[' . CLIENTE_SENHA_ALFABETO . ']{16}$/', $controller->data['senhaInicial']);
    $this->assertTrue(password_verify($controller->data['senhaInicial'], $gravado->senha));
    $this->assertTrue($controller->data['emailEnviado']);
    $this->assertSame('no-store', $controller->output->headers['Cache-Control'] ?? null);
    $this->assertLogNaoContem($controller->data['senhaInicial']);
}
```

Cobrir os demais casos da tabela (nomes nos critérios). Falha de banco simulada como em `tests/Unit/OsVendasTransacaoTest.php::falhaAntesDe()` (trigger `RAISE(ABORT)`, `db_debug = false`, `E_WARNING` silenciado e restaurado no `tearDown`). A tabela `api_refresh_tokens` do teste segue as colunas usadas por `Refresh_token` (veja `tests/Unit/SenhaClienteTest.php`).

Testes existentes que fixavam o comportamento antigo **devem ser atualizados** (não apagados): `ClientesFormularioTest` (linha ~37: "sem senha usa o CPF/CNPJ") passa a assertar que o documento **não** é a senha; `ClientesCadastroRapidoTest` (linhas ~190–204) passa a assertar `password_verify('12345678909', senha) === false` e `senha_trocar = 1` (acrescentar `senha_trocar INTEGER DEFAULT 0` e `sessao_versao`/`token_versao` ao schema do teste), mantendo a assertion de que a senha do POST é ignorada.

**Refatoração:** `salvarCliente()` passa a devolver também a senha gerada; manter um único caminho de gravação para formulário e cadastro rápido.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/helpers/clientes_helper.php` | Modificar | `clienteDadosDoFormulario()` com `$senhaGerada`; novo `clienteEnviarSenhaInicial()` |
| `application/controllers/Clientes.php` | Modificar | adicionar/cadastro rápido com senha gerada, tela única, edição de senha por `registrarTrocaDeSenha`, exclusão em transação, seam `enviarSenhaInicial()` |
| `application/models/Clientes_model.php` | Modificar | `excluirComDependentes()`; `removeClientOs`/`removeClientVendas` sem `catch` engolindo |
| `application/views/clientes/emails/senha_inicial.php` | Criar | HTML do e-mail com a senha inicial |
| `tests/Unit/ClientesSenhaInicialTest.php` | Criar | Suíte do fluxo novo |
| `tests/Unit/ClientesFormularioTest.php` | Modificar | Senha nova não é mais o documento |
| `tests/Unit/ClientesCadastroRapidoTest.php` | Modificar | Senha aleatória + `senha_trocar = 1` |

### Detalhamento Técnico

1. **`clienteDadosDoFormulario($post, bool $novo, ?string $senhaGerada = null): array`** — o resto da função não muda. Regra da senha:
   - senha digitada não vazia → `senha` = `password_hash(digitada)`;
   - cadastro novo sem senha digitada → `senha` = `password_hash($senhaGerada)` quando `$senhaGerada` é string não vazia; senão `password_hash(bin2hex(random_bytes(16)))` (ninguém conhece). **O documento nunca é usado como senha.**
   - edição sem senha → sem chave `senha`.
   - A função **não** grava `senha_trocar` (o cadastro do próprio cliente na área do cliente também a usa).
   Atualizar o docblock (tira a frase sobre CPF/CNPJ).
2. **`clienteEnviarSenhaInicial(object $email, ?object $emitente, object $cliente, string $html): bool`** (helper, contrato consumido pela TASK-022):
   - `$emitente === null` ou `! filter_var(trim((string) ($cliente->email ?? '')), FILTER_VALIDATE_EMAIL)` → `false` sem chamar o mailer;
   - `$email->clear(true)`, `from((string) $emitente->email, (string) $emitente->nome)`, `to(<e-mail do cliente>)`, `subject('Sua senha de acesso à área do cliente')`, `message($html)`, `$enviado = (bool) $email->send(true)` (o `true` pula a fila);
   - `\Throwable` → `log_message('error', sprintf('email senha_inicial falha cliente=%d erro=%s', (int) $cliente->idClientes, $e::class))`, `false`; `send` falso → mesmo log com `erro=envio`, `false`. Nunca logar o HTML, a senha nem o e-mail.
3. **View `application/views/clientes/emails/senha_inicial.php`** — variáveis `$cliente`, `$emitente`, `$senha`, `$urlAcesso`. E-mail HTML no estilo dos vizinhos (`application/views/conecte/emails/clientenovasenha.php`): saudação com o nome, a senha num bloco destacado, o link `$urlAcesso` ("Acessar a área do cliente") e a frase "No primeiro acesso você vai definir uma senha só sua.". Todo valor por `e()`; sem `<script>`.
4. **`Clientes::enviarSenhaInicial(object $cliente, string $senha): bool`** (protected): carrega `mapos_model` e a library `email`; `$html = $this->load->view('clientes/emails/senha_inicial', ['cliente' => $cliente, 'emitente' => $emitente, 'senha' => $senha, 'urlAcesso' => site_url('mine')], true)`; devolve `clienteEnviarSenhaInicial($this->email, $emitente, $cliente, $html)`.
5. **`Clientes::salvarCliente(?object $cliente, bool $aceitaSenha = true): array{0: array, 1: ?int, 2: ?string}`** — terceiro item = senha gerada (só em cadastro novo sem senha digitada).
   - Cadastro novo: `$senhaGerada = trim(<senha do POST considerado>) === '' ? clienteSenhaAleatoria() : null`; `$dados = clienteDadosDoFormulario($post, true, $senhaGerada) + ['senha_trocar' => 1, 'dataCadastro' => date('Y-m-d')]`.
   - Edição: `$dados = clienteDadosDoFormulario($post, false)`; se houver `$dados['senha']`, tira do array, grava o resto com `edit()` e depois chama `registrarTrocaDeSenha($id, $hash, true)`; `false` → `[['_geral' => 'Não foi possível salvar a senha. Tente de novo.'], null, null]`.
6. **`Clientes::formulario()`** — cadastro novo com sucesso e `$senhaGerada !== null`: `$emailEnviado = $this->enviarSenhaInicial($clienteGravado, $senhaGerada)`; `$this->output->set_header('Cache-Control: no-store')`; `$this->data['cliente'] = $clienteGravado; $this->data['senhaInicial'] = $senhaGerada; $this->data['emailEnviado'] = $emailEnviado; $this->data['view'] = 'clientes/senha_inicial'; return $this->layout();` — sem flashdata. Demais sucessos: redirect com flash como hoje.
7. **`Clientes::cadastroRapido()`** — usa `salvarCliente(null, false)` (gera senha e marca troca); ignora o terceiro item; nunca chama `enviarSenhaInicial`.
8. **`Clientes_model::excluirComDependentes(int $idCliente): bool`** — `trans_begin`; dentro de `try`:
   - ids das OS do cliente (`SELECT idOs FROM os WHERE clientes_id = ?`); com ids: `DELETE` em `servicos_os`, `produtos_os` (`where_in('os_id', $ids)`), em `anotacoes_os` e `os_historico` só se `$this->db->table_exists(...)`, depois `DELETE os WHERE clientes_id`;
   - ids das vendas; com ids: `DELETE itens_de_vendas where_in vendas_id`, depois `DELETE vendas WHERE clientes_id`;
   - `DELETE clientes WHERE idClientes`; `affected_rows() === 1` obrigatório;
   - com o cliente apagado: `$this->refreshTokens()->revogarDoUsuario($idCliente, 'cliente')`;
   - `trans_status() !== false` → commit e `true`. Senão / `\Throwable` → rollback, `log_message('error', sprintf('transacao rollback operacao=excluir_cliente id=%d', $idCliente))`, `false`.
   Anexos, equipamentos, cobranças e lançamentos do cliente **não** são apagados aqui: no MySQL as FKs fazem o `DELETE` falhar, a transação desfaz tudo e o operador vê a mensagem de erro (antes ficava pela metade).
9. **`removeClientOs()`/`removeClientVendas()`** (ainda usados pela API até a TASK-022): sem `try/catch`; cada `delete()` conferido (`=== false`) → `log_message('error', sprintf('cliente remover_os falha os=%d', $id))` / `cliente remover_venda falha venda=%d` e `return false`.
10. **`Clientes::excluir()`** — `$id = (int) $this->input->post('id')`; `<= 0` → erro atual; `excluirComDependentes($id)` falso → flashdata `error` `Não foi possível excluir. Nada foi alterado.` e redirect para a listagem com filtros; `log_info('Removeu um cliente. ID ' . $id)` e flash de sucesso só com `true`.

**Contrato com camadas adjacentes:**

```
Produz para: Clientes (painel) e ClientesController (API, TASK-022)
  - clienteEnviarSenhaInicial(object $email, ?object $emitente, object $cliente, string $html): bool
      true = SMTP aceitou; false = sem emitente/e-mail inválido/falha (log 'email senha_inicial falha cliente=<id> erro=<classe|envio>')
  - clienteDadosDoFormulario($post, bool $novo, ?string $senhaGerada = null): array (sem senha_trocar)
  - Clientes_model::excluirComDependentes(int $idCliente): bool
      true = cliente, OS, vendas e itens apagados + refresh 'cliente' revogados
      false = nada mudou (log 'transacao rollback operacao=excluir_cliente id=<id>'), inclusive cliente inexistente

Consome de: Clientes_model::registrarTrocaDeSenha(int, string, bool): bool (TASK-009)
  - false → erro '_geral' na tela, nada alterado na senha
```

**Exemplo de implementação** (o ponto que mais erra: a senha gerada sai do controller e nunca toca sessão/flashdata):

```php
[$erros, $id, $senhaGerada] = $this->salvarCliente($cliente);
if ($erros === [] && $cliente === null && $senhaGerada !== null) {
    $gravado = $this->clientes_model->getById($id);
    $this->output->set_header('Cache-Control: no-store');
    $this->data['cliente'] = $gravado;
    $this->data['senhaInicial'] = $senhaGerada;
    $this->data['emailEnviado'] = $this->enviarSenhaInicial($gravado, $senhaGerada);
    $this->data['view'] = 'clientes/senha_inicial';

    return $this->layout();
}
```
