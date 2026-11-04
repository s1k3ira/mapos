---
checkpoint: null
complexity: M
created_at: "2026-11-03 16:00:04"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter Htaccess tests/Unit/HardeningArmazenamentoTest.php
      text: armazenamentoPasta() grava o .htaccess da raiz com <IfModule mod_authz_core.c> e Require all denied, regrava o antigo sem mod_authz_core e não toca no que já contém mod_authz_core
    - done: true
      test: application/vendor/bin/phpunit --filter PastaPublica tests/Unit/HardeningArmazenamentoTest.php
      text: armazenamentoProtegerPastaPublica() grava (ou regrava o antigo) com Require all denied em mod_authz_core e o fallback Order/Deny
    - done: true
      test: application/vendor/bin/phpunit --filter SemEscrita tests/Unit/HardeningArmazenamentoTest.php
      text: 'Raiz sem permissão de escrita: armazenamentoPasta() devolve null, nenhum aviso PHP escapa e há linha de erro ''armazenamento falha operacao=mkdir'' no log'
    - done: true
      test: application/vendor/bin/phpunit --filter IndexHtml tests/Unit/HardeningArmazenamentoTest.php
      text: Falha ao gravar index.html em protegerDiretorioUpload() gera linha de erro com operacao=gravar
    - done: true
      test: '! grep -nE ''@(file_put_contents|mkdir|unlink|chmod)'' application/helpers/armazenamento_helper.php application/helpers/general_helper.php'
      text: Nenhum @ silencia mkdir, file_put_contents, unlink ou chmod nos dois helpers
    - done: true
      test: grep -nE 'restore_error_handler' application/helpers/armazenamento_helper.php application/helpers/general_helper.php | wc -l | grep -qvx 0
      text: O helper que captura o aviso restaura o error handler no finally
    - done: true
      test: grep -nF "'save_queries' => ENVIRONMENT !== 'production'" application/config/database.php
      text: save_queries desligado em produção
    - done: true
      test: application/vendor/bin/phpunit --filter Seed tests/Unit/HardeningArmazenamentoTest.php
      text: Seed Usuarios com ambiente production ou testing não insere admin@admin.com e imprime o motivo; com development insere
    - done: true
      test: grep -nE "development" application/database/seeds/Usuarios.php
      text: O seed decide pelo ambiente antes do insert
    - done: true
      test: grep -cE 'assertStringContainsString\(.(Require all denied|mod_authz_core)' tests/Unit/HardeningArmazenamentoTest.php | grep -qvx 0
      text: Os testes de arquivo assertam o conteúdo gravado, não só a existência do arquivo
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/ArmazenamentoTest.php
      text: Testes existentes de armazenamento continuam passando
deps:
    - TASK-001
diff_base: 097272eb6c9b7dfd57c57d279ae4a6648d411038
diff_tip: c4c75dcb49b29f4ed35beb706347a0096c6ca96c
files:
    - application/helpers/armazenamento_helper.php
    - application/helpers/general_helper.php
    - application/config/database.php
    - application/database/seeds/Usuarios.php
    - tests/Unit/HardeningArmazenamentoTest.php
id: TASK-006
linter: composer analyse
n45_version: 1.1.0
outcome: |
    Símbolos reutilizáveis em `application/helpers/armazenamento_helper.php`:
    - `armazenamentoSemAviso(callable): array{0: mixed, 1: ?string}` — executa a operação capturando o E_WARNING/E_NOTICE (restaura o error handler no `finally`).
    - `armazenamentoRegistrarFalha(string $operacao, string $caminho, ?string $aviso): void` — `log_message('error', 'armazenamento falha operacao=<mkdir|gravar|apagar> caminho=... motivo=...')`.
    - `armazenamentoApagar(string $caminho): void` — unlink com log `apagar` na falha.
    - `armazenamentoGravarProtecao(string $arquivo, string $conteudo): void` — grava/regrava o `.htaccess` quando ausente ou sem `mod_authz_core` (constante `ARMAZENAMENTO_MARCA_AUTHZ`).
    - `armazenamentoPasta()` devolve `null` e loga `operacao=mkdir` se a pasta não puder ser criada; `.htaccess` (raiz e pasta pública) agora com `Require all denied` (mod_authz_core) + fallback `Order/Deny`.
    - `protegerDiretorioUpload()` (general_helper) loga `operacao=gravar` se o `index.html` falhar.
    Config/seed: `database.php` `save_queries = ENVIRONMENT !== 'production'`; `Usuarios::executar(string $ambiente): bool` só insere em `development` (outros: imprime aviso e devolve `false`); `run()` delega a ele.
persona: backend
phase: 'Phase 1 — Base de segurança: schema, API, gateways e avisos de sessão'
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/HardeningArmazenamentoTest.php tests/Unit/ArmazenamentoTest.php
title: Proteção de pastas sem @, save_queries e seed só em development
updated_at: "2026-11-03 17:42:00"
---
## Contexto

Três pontos de endurecimento do servidor, independentes do resto da feature:

1. **Proteção das pastas de arquivos.** `application/helpers/armazenamento_helper.php` e `application/helpers/general_helper.php` gravam os arquivos de proteção (`.htaccess` que nega acesso, `.htaccess` que impede execução de script em pasta pública, `index.html` contra listagem) e criam/apagam arquivos com `@` (`@mkdir`, `@file_put_contents`, `@unlink`): se a gravação falha (permissão), **ninguém fica sabendo** e a pasta fica sem proteção. Além disso, `armazenamentoProtegerPastaPublica()` grava o `.htaccess` só com a sintaxe do Apache 2.2 (`Order allow,deny` / `Deny from all`), ignorada pelo Apache 2.4 sem `mod_access_compat`; e nenhum dos dois `.htaccess` é regravado quando o arquivo antigo existe sem o bloco `mod_authz_core`.
2. **`save_queries` ligado em produção** (`application/config/database.php`, `'save_queries' => true`): o CodeIgniter guarda todas as queries (com valores) na memória da requisição.
3. **Seed `Usuarios`** (`application/database/seeds/Usuarios.php`) insere `admin@admin.com` / `123456` em qualquer ambiente.

Funções afetadas hoje: `armazenamentoPasta()` (`@mkdir` da pasta, `@file_put_contents` do `.htaccess` da raiz), `armazenamentoValidarEnvio()` (dois `@unlink`), `armazenamentoProtegerPastaPublica()` (`@file_put_contents` do `.htaccess` de pasta pública) e `protegerDiretorioUpload()` em `general_helper.php` (`@file_put_contents` do `index.html`). O `@getimagesize()` de `armazenamentoImagemGrandeDemais()` **fica** (é leitura de cabeçalho de arquivo enviado pelo usuário; falha = não é imagem grande).

Regras: erro técnico em `log_message('error', ...)`; nada de dado sensível no log (caminho de pasta pode). O suporte de teste da `TASK-001` existe: `$this->logs('error')`, `assertLogContem('error', '...')`. `phpunit.xml` tem `failOnWarning`: um `mkdir`/`file_put_contents`/`unlink` que falha emite `E_WARNING`, então trocar o `@` por "deixar o aviso sair" reprova a suíte — o aviso precisa ser **capturado** e virar log.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `armazenamentoPasta('os/12')` numa raiz gravável sem `.htaccess` | cria a pasta e o `.htaccess` da raiz contendo `<IfModule mod_authz_core.c>`, `Require all denied`, `<IfModule !mod_authz_core.c>` e `Deny from all` |
| Raiz com `.htaccess` antigo só com `Order allow,deny`/`Deny from all` | o arquivo é regravado com o conteúdo novo (contém `mod_authz_core`) |
| Raiz com `.htaccess` que já contém `mod_authz_core` (e um comentário do admin) | arquivo **não** é tocado (conteúdo idêntico antes/depois) |
| `armazenamentoProtegerPastaPublica($pasta)` sem `.htaccess` | `.htaccess` com `<FilesMatch ...php...>` contendo **os dois** blocos: `Require all denied` em `<IfModule mod_authz_core.c>` e `Order allow,deny`/`Deny from all` em `<IfModule !mod_authz_core.c>` |
| `armazenamentoProtegerPastaPublica($pasta)` com `.htaccess` antigo sem `mod_authz_core` | regravado com o conteúdo novo |
| `armazenamentoPasta('x')` com a raiz **sem permissão de escrita** | devolve `null`, nenhum aviso PHP escapa, e há uma linha `error` no log com `armazenamento falha operacao=mkdir` e o caminho |
| Gravação do `.htaccess`/`index.html` falha (pasta sem escrita) | sem aviso PHP; linha `error` `armazenamento falha operacao=gravar caminho=<...>/.htaccess` (ou `index.html`) |
| `armazenamentoValidarEnvio()` com arquivo de conteúdo divergente | apaga o arquivo e devolve `O conteúdo do arquivo não confere com a extensão.` (como hoje); se o `unlink` falhar, linha `error` `operacao=apagar` |
| `application/config/database.php` | `'save_queries' => ENVIRONMENT !== 'production'` |
| `(new Usuarios)->executar('production')` | não insere linha em `usuarios`; imprime `Seed Usuarios ignorado: só roda com ENVIRONMENT = development.`; devolve `false` |
| `(new Usuarios)->executar('testing')` | idem `production` |
| `(new Usuarios)->executar('development')` | insere `admin@admin.com`; devolve `true` |

## TDD

Testes novos em `tests/Unit/HardeningArmazenamentoTest.php` (`final class HardeningArmazenamentoTest extends MaposTestCase`). Usar uma raiz temporária por teste (`sys_get_temp_dir() . '/mapos-' . bin2hex(random_bytes(4))`), apontada por `$_ENV['STORAGE_PATH']` (ver `armazenamentoRaiz()`; `tests/Unit/ArmazenamentoTest.php` já faz isso — seguir o mesmo setup/limpeza), restaurando `$_ENV` e apagando a pasta no `tearDown`. Para "sem permissão de escrita", `chmod($raiz, 0555)` e `markTestSkipped` quando `posix_geteuid() === 0` (root escreve mesmo assim). Estilo:

```php
public function testHtaccessAntigoSemModAuthzCoreERegravado(): void
{
    file_put_contents($this->raiz . '/.htaccess', "Order allow,deny\nDeny from all\n");

    armazenamentoPasta('os/12');

    $conteudo = (string) file_get_contents($this->raiz . '/.htaccess');
    $this->assertStringContainsString('<IfModule mod_authz_core.c>', $conteudo);
    $this->assertStringContainsString('Require all denied', $conteudo);
}

public function testRaizSemEscritaVolraNullELogaSemAviso(): void
{
    chmod($this->raiz, 0555);

    $this->assertNull(armazenamentoPasta('nova/pasta'));
    $this->assertLogContem('error', 'armazenamento falha operacao=mkdir');
}
```

Para o seed: `require_once APPPATH . 'libraries/Seeder.php'` e `APPPATH . 'database/seeds/Usuarios.php'`; `makeInstance(Usuarios::class)` + `setPrivateProperty($seed, 'db', $this->db)` sobre uma tabela `usuarios` SQLite com as colunas do array do seed; `expectOutputRegex('/ignorado/')` no caso negado. Cobrir os demais casos da tabela.

**Refatoração:** depois do verde, o conteúdo dos dois `.htaccess` vira constante/função única em `armazenamento_helper.php` (texto do arquivo e a checagem "contém `mod_authz_core`") em vez de string repetida.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/helpers/armazenamento_helper.php` | Modificar | Sem `@` em `mkdir`/`file_put_contents`/`unlink`; helper que captura o aviso e loga; `.htaccess` 2.4 + 2.2 regravado quando antigo |
| `application/helpers/general_helper.php` | Modificar | `protegerDiretorioUpload()` sem `@`, com log na falha |
| `application/config/database.php` | Modificar | `'save_queries' => ENVIRONMENT !== 'production'` |
| `application/database/seeds/Usuarios.php` | Modificar | `run()` delega a `executar(string $ambiente): bool`, que só insere em `development` |
| `tests/Unit/HardeningArmazenamentoTest.php` | Criar | Testes da tabela |

### Detalhamento Técnico

1. Helper novo em `armazenamento_helper.php` (com `if (! function_exists(...))`, como as vizinhas) — o trecho não-óbvio é **capturar** o aviso em vez de suprimi-lo:
   ```php
   /**
    * Executa uma operação de arquivo e devolve [resultado, aviso]: o E_WARNING
    * que mkdir/file_put_contents/unlink emitem na falha vira texto em vez de @.
    *
    * @return array{0: mixed, 1: ?string}
    */
   function armazenamentoSemAviso(callable $operacao): array
   {
       $aviso = null;
       set_error_handler(static function (int $nivel, string $mensagem) use (&$aviso): bool {
           $aviso = $mensagem;

           return true;
       }, E_WARNING | E_NOTICE);
       try {
           return [$operacao(), $aviso];
       } finally {
           restore_error_handler();
       }
   }
   ```
   e `armazenamentoRegistrarFalha(string $operacao, string $caminho, ?string $aviso): void` → `log_message('error', sprintf('armazenamento falha operacao=%s caminho=%s motivo=%s', $operacao, $caminho, $aviso ?? 'desconhecido'))`. Operações: `mkdir`, `gravar`, `apagar`.
2. `armazenamentoPasta()`: `mkdir` pelo helper; falso e pasta ainda inexistente → log + `return null`. `.htaccess` da raiz: gravar quando **não existe** ou quando existe e `! str_contains($conteudo, 'mod_authz_core')`; falha na gravação → log (a função continua devolvendo a pasta: a proteção principal é a pasta estar fora do público, e a falha agora fica registrada).
3. `armazenamentoProtegerPastaPublica()`: mesmo critério de (re)gravação; conteúdo novo:
   ```
   # Pasta de uploads: impede a execução de scripts enviados como imagem
   <FilesMatch "\.(?i:php[0-9]*|phtml|phar|pht|phps|cgi|pl|py|sh)$">
       <IfModule mod_authz_core.c>
           Require all denied
       </IfModule>
       <IfModule !mod_authz_core.c>
           Order allow,deny
           Deny from all
       </IfModule>
   </FilesMatch>
   ```
4. `armazenamentoValidarEnvio()`: os dois `@unlink` viram `armazenamentoSemAviso(fn () => unlink($caminho))` com log `apagar` quando falso. O retorno (mensagens) não muda.
5. `protegerDiretorioUpload()` (`general_helper.php`): `file_put_contents` do `index.html` capturando o aviso localmente (mesmo padrão `set_error_handler`/`restore_error_handler` no `finally`, porque `general_helper.php` não depende de `armazenamento_helper.php`) e `log_message('error', 'armazenamento falha operacao=gravar caminho=<...>/index.html motivo=<aviso>')` na falha. Ler `$diretorio` com `(string)`.
6. `database.php`: só a chave `save_queries`.
7. Seed: `public function run() { $this->executar(defined('ENVIRONMENT') ? ENVIRONMENT : 'production'); }` e `public function executar(string $ambiente): bool` — fora de `development`, `echo 'Seed Usuarios ignorado: só roda com ENVIRONMENT = development.' . PHP_EOL; return false;`; em `development`, o insert atual e `return true`. O hash e os dados do array não mudam.
8. `php scripts/check-escape.php` e `php scripts/check-inline-script.php` não são afetados (nenhuma view muda).
