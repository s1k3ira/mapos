---
checkpoint: null
complexity: M
created_at: "2026-11-03 22:51:24"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testAtivacaoComSenhaErradaContaFalhaENaoConfereCodigo tests/Unit/DoisFatoresConfirmacaoTest.php
      text: ativarDoisFatores com senha errada devolve senha_ativar 'A senha não confere.', registra 1 falha no escopo confirmacao e não chama Totp::verificar
    - done: true
      test: application/vendor/bin/phpunit --filter testSextaTentativaBloqueadaNemConfereOCodigo tests/Unit/DoisFatoresConfirmacaoTest.php
      text: Após 5 senhas erradas, a 6ª tentativa (senha certa) devolve senha_ativar = Limite_login::MENSAGEM e Totp::verificar é chamado 0 vezes
    - done: true
      test: application/vendor/bin/phpunit --filter testCodigoErradoNaAtivacaoContaFalhaDeConfirmacao tests/Unit/DoisFatoresConfirmacaoTest.php
      text: Senha certa com código TOTP errado devolve o erro codigo e registra 1 falha no escopo confirmacao para o e-mail do usuário
    - done: true
      test: '! grep -n ''password_verify'' application/controllers/Mapos.php'
      text: ativarDoisFatores não confere mais a senha com password_verify direto
    - done: true
      test: application/vendor/bin/phpunit --filter testDesativarTotpDevolveTrueEApagaTudo tests/Unit/DoisFatoresConfirmacaoTest.php
      text: Mapos_model::desativarTotp(1) devolve true e zera totp_secreto, totp_ativado_em e os códigos de recuperação
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaAoDesativarTotpReverteELoga tests/Unit/DoisFatoresConfirmacaoTest.php
      text: desativarTotp com falha no DELETE dos códigos devolve false, mantém totp_secreto preenchido e loga 'transacao rollback operacao=desativar_totp id=1'
    - done: true
      test: application/vendor/bin/phpunit --filter testDesativarDoisFatoresComFalhaMostraErro tests/Unit/DoisFatoresConfirmacaoTest.php
      text: desativarDoisFatores com desativarTotp falso devolve _geral 'Não foi possível desativar. Tente de novo.' sem flash de sucesso
    - done: true
      test: grep -qE 'if \(! \$this->mapos_model->desativarTotp\(' application/controllers/Usuarios.php && grep -q 'Não foi possível redefinir o 2FA. Tente de novo.' application/controllers/Usuarios.php
      text: Usuarios confere o retorno de desativarTotp e mostra 'Não foi possível redefinir o 2FA. Tente de novo.'
    - done: true
      test: application/vendor/bin/phpunit --filter testSalvarConfiguracaoComFalhaReverteTudo tests/Unit/DoisFatoresConfirmacaoTest.php
      text: saveConfiguracao com falha no UPDATE de configuracoes devolve false, não altera nenhuma das duas chaves e loga 'transacao rollback operacao=salvar_configuracao'
    - done: true
      test: application/vendor/bin/phpunit --filter testSalvarConfiguracaoInsereChaveNova tests/Unit/DoisFatoresConfirmacaoTest.php
      text: saveConfiguracao insere a chave inexistente e devolve true (upsert mantido)
    - done: true
      test: '! grep -nE ''catch \(Exception \$e\)'' application/models/Mapos_model.php'
      text: saveConfiguracao não engole mais erro em catch (Exception)
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/DoisFatoresConfirmacaoTest.php tests/Unit/DoisFatoresTest.php
      text: Suítes de 2FA passam inteiras
    - done: true
      test: application/vendor/bin/phpstan analyse --memory-limit=1G --no-progress application/controllers/Mapos.php application/models/Mapos_model.php application/controllers/Usuarios.php
      text: PHPStan não sobe a baseline nos arquivos da task
deps:
    - TASK-001
diff_base: f8091ee6c62d7ff8832497b1587af87cf4eb239a
diff_tip: 396259c855f89ac5431be6606b767d2a7a114bf2
files:
    - application/controllers/Mapos.php
    - application/models/Mapos_model.php
    - application/controllers/Usuarios.php
    - tests/Unit/DoisFatoresConfirmacaoTest.php
    - tests/Unit/DoisFatoresTest.php
id: TASK-018
linter: composer analyse
n45_version: 1.1.0
outcome: |
    # TASK-018 — Outcome

    - `Mapos::ativarDoisFatores` (POST) confere a senha via `confirmarSenhaDoUsuario()` (escopo `confirmacao`): senha errada -> `senha_ativar` = `A senha não confere.`; bloqueado -> `Limite_login::MENSAGEM` sem chamar `Totp::verificar`; código TOTP errado -> erro `codigo` + `registrarFalha('confirmacao', email, ip)`.
    - `Mapos_model::desativarTotp(int $idUsuario): bool` — transacional; `false` + log `error` `transacao rollback operacao=desativar_totp id=<id>` em falha.
    - `Mapos_model::saveConfiguracao(array): bool` — transacional com upsert mantido; `false` + log `transacao rollback operacao=salvar_configuracao chave=<chave>` em falha.
    - `Mapos::desativarDoisFatores` mostra `_geral` = `Não foi possível desativar. Tente de novo.`; `Usuarios` mostra flash `Não foi possível redefinir o 2FA. Tente de novo.` e redireciona para `usuarios/editar/<id>`.
    - `Mapos::usuarioDoDoisFatores` e `telaDoisFatores` passaram a `protected`.
persona: backend
phase: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/DoisFatoresConfirmacaoTest.php tests/Unit/DoisFatoresTest.php tests/Unit/ConfiguracoesTest.php tests/Unit/UsuariosPermissoesTest.php
title: 2FA com confirmação de senha limitada e retornos do Mapos_model
updated_at: "2026-11-04 00:56:21"
---
## Contexto

Duas lacunas no 2FA e nos retornos do `Mapos_model`, apontadas pela auditoria:

1. **Ativação do 2FA sem limite de tentativas.** `Mapos::ativarDoisFatores()` confere a senha com `password_verify()` direto e não conta código TOTP errado. Quem tem uma sessão roubada pode adivinhar a senha sem limite nessa tela. As outras ações sensíveis da mesma tela (`novosCodigosDoisFatores`, `desativarDoisFatores`) já usam `confirmarSenhaDoUsuario()`, que passa por `Limite_login::confirmarSenha()` (escopo `confirmacao`: bloqueado → nem confere; senha errada → conta falha; certa → zera).
2. **Retornos ignorados no `Mapos_model`.** `desativarTotp()` é `void` (usa `trans_start/trans_complete` e ninguém confere o resultado): o operador vê "2FA desativado/redefinido" mesmo quando nada mudou. `saveConfiguracao()` envolve os `UPDATE/INSERT` num `try/catch (Exception)` que nunca dispara com `db_debug` desligado (a query só devolve `false`), então devolve `true` com a configuração não gravada.

Estado atual relevante (lido no código):

- `Mapos::confirmarSenhaDoUsuario(object $usuario, string $senha, string $mensagemErro = 'A senha não confere.'): ?string` (privado) devolve `null` (ok), `Limite_login::MENSAGEM` (bloqueado) ou `$mensagemErro`.
- `Limite_login::confirmarSenha(string $email, string $ip, string $senha, string $hash): string` devolve `Limite_login::SENHA_OK | SENHA_ERRADA | SENHA_BLOQUEADA`; `registrarFalha(string $escopo, ?string $email, string $ip)`; `LIMITE_EMAIL = 5`; `MENSAGEM = 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.'`.
- `Mapos.php:706` já trata `saveConfiguracao()` falso; `Mapos::desativarDoisFatores()` (linha ~239) e `Usuarios.php` (linha ~188, redefinir o 2FA de outro usuário) chamam `desativarTotp()` sem conferir.

Padrões do projeto: erro técnico por `log_message('error', ...)`; formato de log de rollback da feature `transacao rollback operacao=<op> id=<id>`; `trans_status()` não pega exceção com `db_debug` ligado — conferir os dois sinais; nunca logar senha, código TOTP ou segredo.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `ativarDoisFatores` (POST) com senha errada | tela com erro `senha_ativar` = `A senha não confere.`; 1 falha registrada no escopo `confirmacao` para o e-mail do usuário; `Totp::verificar` não é chamado |
| 5 POSTs seguidos com senha errada e, no 6º, senha **certa** e código certo | 6º devolve erro `senha_ativar` = `Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.`; `Totp::verificar` não é chamado no 6º; `ativarTotp` não é chamado |
| senha certa e código TOTP errado | erro `codigo` = `Código incorreto. Confira o aplicativo e tente de novo.`; `registrarFalha('confirmacao', <email do usuário>, <ip>)` chamado 1 vez |
| senha certa e código certo | `ativarTotp` chamado; tela com os códigos de recuperação (fluxo atual inalterado) |
| `Mapos_model::desativarTotp(1)` com sucesso | `true`; `totp_secreto`, `totp_ativado_em`, `totp_ultimo_passo` nulos; 0 códigos de recuperação restantes |
| `Mapos_model::desativarTotp(1)` com falha simulada no `DELETE` de `usuarios_codigos_recuperacao` | `false`; `totp_secreto` do usuário 1 continua preenchido (rollback); log `error` contém `transacao rollback operacao=desativar_totp id=1` |
| `Mapos::desativarDoisFatores` com `desativarTotp` devolvendo `false` | tela com erro `_geral` = `Não foi possível desativar. Tente de novo.`; sem `log_info` e sem flash de sucesso |
| `Usuarios` (redefinir 2FA) com `desativarTotp` devolvendo `false` | flashdata `error` = `Não foi possível redefinir o 2FA. Tente de novo.`; sem `log_info` |
| `Mapos_model::saveConfiguracao(['app_name' => 'X', 'per_page' => '20'])` com falha simulada no `UPDATE` de `configuracoes` | `false`; nenhuma das duas chaves alterada (rollback); log `error` contém `transacao rollback operacao=salvar_configuracao` |
| `saveConfiguracao(['chave_nova' => 'v'])` sem linha prévia | `true`; linha `chave_nova = v` inserida (upsert atual mantido) |

## TDD

Testes em `tests/Unit/DoisFatoresConfirmacaoTest.php` (nova, classe `final` estendendo `MaposTestCase`). O controller é exercitado com `makeInstance(Mapos::class)` (sem construtor) e propriedades injetadas por `setPrivateProperty`/atribuição: `input` (stub com `method()`, `post()`, `ip_address()`), `session` (stub com `userdata`/`unset_userdata`/`set_flashdata`), `mapos_model` (real sobre SQLite ou stub contando chamadas), `totp` (stub contando `verificar`), `limite_login` = `new Limite_login(['db' => $this->db, 'segredo' => 'segredo-de-teste', 'auditar' => fn () => null])` com a tabela `login_attempts` criada como em `tests/Unit/LimiteLoginTest.php`, e `load` com `__call` nulo. Para capturar a tela sem renderizar view, torne `telaDoisFatores()` e `usuarioDoDoisFatores()` `protected` e sobrescreva no teste (capturando `$erros`).

```php
public function testSextaTentativaBloqueadaNemConfereOCodigo(): void
{
    $controller = $this->controllerDeAtivacao(); // usuário ana@x.com, senha 'certa', totp stub
    for ($i = 0; $i < 5; $i++) {
        $controller->post = ['senha' => 'errada', 'codigo' => '123456'];
        $controller->ativarDoisFatores();
    }

    $controller->post = ['senha' => 'certa', 'codigo' => '123456'];
    $controller->ativarDoisFatores();

    $this->assertSame(['senha_ativar' => Limite_login::MENSAGEM], $controller->errosDaTela);
    $this->assertSame(0, $controller->totp->chamadasVerificar);
}
```

Cobrir os demais casos da tabela (nomes nos critérios). Os testes de `Mapos_model` simulam falha como em `tests/Unit/OsVendasTransacaoTest.php::falhaAntesDe()` (trigger `RAISE(ABORT)`, `db_debug = false`, `E_WARNING` silenciado e restaurado no `tearDown`).

**Refatoração:** `ativarDoisFatores` fica com o mesmo formato dos irmãos (`confirmarSenhaDoUsuario` no topo); nada além disso.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/Mapos.php` | Modificar | `ativarDoisFatores` com `confirmarSenhaDoUsuario` e falha de TOTP contada; `desativarDoisFatores` confere o retorno; `telaDoisFatores`/`usuarioDoDoisFatores` viram `protected` |
| `application/models/Mapos_model.php` | Modificar | `desativarTotp(): bool` transacional com log; `saveConfiguracao()` transacional decidindo pelo retorno real |
| `application/controllers/Usuarios.php` | Modificar | redefinição do 2FA confere o retorno de `desativarTotp` |
| `tests/Unit/DoisFatoresConfirmacaoTest.php` | Criar | Controller (ativação/desativação) e model |
| `tests/Unit/DoisFatoresTest.php` | Modificar | só se `testDesativarApagaSegredoECodigos` precisar assertar o novo retorno `true` |

### Detalhamento Técnico

1. `Mapos::ativarDoisFatores()`: troca o `password_verify` por `if ($erro = $this->confirmarSenhaDoUsuario($usuario, (string) $this->input->post('senha'))) { return $this->telaDoisFatores(['senha_ativar' => $erro]); }`. Código TOTP `null` → `$this->limite_login->registrarFalha('confirmacao', (string) $usuario->email, $this->input->ip_address());` antes de devolver a tela com o erro `codigo` (a library já está carregada por `confirmarSenhaDoUsuario`).
2. `Mapos::desativarDoisFatores()`: `if (! $this->mapos_model->desativarTotp(...)) { return $this->telaDoisFatores(['_geral' => 'Não foi possível desativar. Tente de novo.']); }` antes do `log_info`.
3. `Usuarios.php` (método que chama `desativarTotp($id)`): resultado falso → `set_flashdata('error', 'Não foi possível redefinir o 2FA. Tente de novo.')` e `redirect('usuarios/editar/' . $id)`, sem `log_info`.
4. `Mapos_model::desativarTotp(int $idUsuario): bool`: `trans_begin`; `UPDATE usuarios` + `DELETE usuarios_codigos_recuperacao` dentro de `try`; `trans_status() !== false` → commit e `true`; senão (ou `\Throwable`) → `trans_rollback`, `log_message('error', sprintf('transacao rollback operacao=desativar_totp id=%d', $idUsuario))`, `false`. Não exigir `affected_rows() === 1` no UPDATE (desativar quem já está sem 2FA não é erro).
5. `Mapos_model::saveConfiguracao($data): bool`: `trans_begin`; para cada chave mantém o upsert atual, mas guarda o retorno de cada `update()`/`insert()`; qualquer `false`, `trans_status() === false` ou `\Throwable` → `trans_rollback`, `log_message('error', sprintf('transacao rollback operacao=salvar_configuracao chave=%s', $chave))` (só o nome da chave, nunca o valor) e `false`; tudo certo → commit e `true`. Remove o `catch (Exception)` que engolia o erro.

**Contrato com camadas adjacentes:**

```
Produz para: Mapos (painel) e Usuarios (painel)
  - desativarTotp: true gravado | false nada mudou (log 'transacao rollback operacao=desativar_totp id=<id>')
  - saveConfiguracao: true todas as chaves gravadas | false nenhuma (log 'transacao rollback operacao=salvar_configuracao chave=<chave>')

Consome de: Limite_login
  - confirmarSenha(...) === SENHA_BLOQUEADA → mensagem Limite_login::MENSAGEM, nada conferido
  - registrarFalha('confirmacao', email, ip) a cada código TOTP errado
```
