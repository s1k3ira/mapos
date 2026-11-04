---
checkpoint: null
complexity: M
created_at: "2026-11-04 01:21:15"
criteria:
    - done: false
      test: 'node --test --test-name-pattern=''desfechoDaFalha: 422 marca os campos sem alert'' tests/js/login.test.mjs'
      text: 422 do login vira erros por campo (email) com mensagem null e o token do campo configurado
    - done: false
      test: 'node --test --test-name-pattern=''desfechoDaFalha: 401 e 403 com JSON'' tests/js/login.test.mjs'
      text: 401 e 403 com JSON mostram a message do servidor (passada por textoSimples) no alert
    - done: false
      test: 'node --test --test-name-pattern=''desfechoDaFalha: 429 mostra o tempo restante'' tests/js/login.test.mjs'
      text: 429 com retry_after 90 mostra 'Muitas tentativas de acesso. Tente de novo em 2 min.', com 30 mostra 1 min e sem retry_after mostra a message do servidor
    - done: false
      test: 'node --test --test-name-pattern=''desfechoDaFalha: 403 sem JSON'' tests/js/login.test.mjs'
      text: 403 sem JSON (CSRF recusado) mostra MENSAGEM_SESSAO
    - done: false
      test: 'node --test --test-name-pattern=''desfechoDaFalha: rede e timeout'' tests/js/login.test.mjs'
      text: ErroHttp status 0 e TypeError de rede mostram MENSAGEM_REDE
    - done: false
      test: 'node --test --test-name-pattern=''desfechoDaFalha: token vem do campo de nome configurado'' tests/js/login.test.mjs'
      text: Token CSRF é lido do corpo pelo nome da meta csrf-token-name (OUTRO), não de MAPOS_TOKEN
    - done: false
      test: 'node --test --test-name-pattern=''campoDoFoco: primeiro campo com erro ou a senha'' tests/js/login.test.mjs'
      text: campoDoFoco devolve email quando o e-mail tem erro, senha quando só a senha tem erro ou no alert de credenciais, e null em rede
    - done: false
      test: 'node --test --test-name-pattern=''interpretarResposta: sucesso com e sem destino'' tests/js/login.test.mjs'
      text: interpretarResposta devolve {ok:true} no sucesso e {ok:true, destino} com 2FA
    - done: false
      test: '! grep -nE ''MAPOS_TOKEN|mensagemDeFalha'' assets/js/modules/login/formulario.js'
      text: O módulo de login não usa mais MAPOS_TOKEN fixo nem mensagemDeFalha
    - done: false
      test: grep -q 'csrf-token-name' assets/js/modules/login/formulario.js
      text: O módulo de login lê o nome do campo CSRF da meta csrf-token-name
    - done: false
      test: grep -qE 'finally' assets/js/modules/login/formulario.js
      text: O botão sai do loading em todo desfecho de falha (marcarCarregando false num finally)
    - done: false
      test: application/vendor/bin/phpunit --filter testSucessoDoLogoutApareceNumAlertDeSucesso tests/Unit/LoginViewTest.php
      text: Login do painel mostra 'Você saiu.' num alert success quando sucesso vem preenchido e nenhum alert success sem ele
    - done: false
      test: application/vendor/bin/phpunit --filter testMolduraTemTopNavTabbarEOModoDeCor tests/Unit/AreaDoClienteTest.php
      text: Moldura da área do cliente tem o Sair como form POST para mine/sai(r) com data-module=layout/sair, hidden MAPOS_TOKEN=hash-csrf e button submit, sem href GET
    - done: false
      test: application/vendor/bin/phpunit --filter testContaSaiPorFormularioPost tests/Unit/AreaDoClienteTest.php
      text: Tela da conta tem o atalho Sair como form POST com data-module=layout/sair, campo CSRF e button submit min-h-12, sem href GET
    - done: false
      test: '! grep -nE "href.{0,40}mine/s[a]ir" application/views/conecte/template.php application/views/conecte/conta.php'
      text: Nenhum link GET para mine/sai(r) nas views da área do cliente tocadas
    - done: false
      test: php scripts/check-escape.php && php scripts/check-inline-script.php
      text: Guardas de escape e de script inline não sobem a baseline
    - done: false
      test: npm run build && git diff --exit-code -- assets/dist/app.css
      text: CSS compilado em dia com as views
    - done: false
      test: npm run test:js && application/vendor/bin/phpunit tests/Unit/LoginViewTest.php tests/Unit/AreaDoClienteTest.php tests/Unit/DefinirSenhaViewTest.php tests/Unit/AcessibilidadeTest.php
      text: Testes JS e as suítes das telas de login e da área do cliente passam
deps:
    - TASK-025
    - TASK-029
files:
    - assets/js/modules/login/formulario.js
    - tests/js/login.test.mjs
    - application/views/mapos/login.php
    - application/views/conecte/template.php
    - application/views/conecte/conta.php
    - tests/Unit/LoginViewTest.php
    - tests/Unit/AreaDoClienteTest.php
    - assets/dist/app.css
id: TASK-030
linter: php scripts/check-escape.php && php scripts/check-inline-script.php
n45_version: 1.1.0
outcome: ""
persona: frontend
phase: Phase 4 — Login por status, logout seguro e limite de OS do cliente
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: node --test tests/js/login.test.mjs && application/vendor/bin/phpunit tests/Unit/LoginViewTest.php tests/Unit/AreaDoClienteTest.php
title: Login por status HTTP no módulo de login e Sair por POST na área do cliente
updated_at: "2026-11-04 01:21:15"
---
## Contexto

Os logins do painel (`POST /login/verificarLogin`) e da área do cliente (`POST /mine/login`) passaram a responder por status HTTP com `Content-Type: application/json` (RF-29, task de backend desta fase), e o token CSRF novo agora vem no campo de nome configurável (`APP_CSRF_TOKEN_NAME`), não mais em `MAPOS_TOKEN`. O módulo do formulário de login, `assets/js/modules/login/formulario.js` (usado pelas duas telas: `views/mapos/login.php` e `views/conecte/login.php`), ainda espera o contrato antigo (tudo em 200, `{result, message, MAPOS_TOKEN}`, corpo como texto). Como `lib/http.js` lança `ErroHttp(status, message, corpo)` para status >= 400 (com `corpo` já como objeto quando a resposta é JSON, ou texto quando não é), o módulo precisa decidir o desfecho pelo `ErroHttp` (RF-53). A spec chama o módulo de `login/login.js`; o arquivo real do projeto é `login/formulario.js`, e é ele que muda (nada de arquivo novo).

Esta task também fecha o RF-27 na área do cliente: os "Sair" de `views/conecte/template.php` (botão de ícone na top nav) e `views/conecte/conta.php` (atalho da conta no celular) ainda são links GET para `mine/sair`, que agora responde 405. Viram formulário POST com o campo CSRF e o módulo `layout/sair` (criado na task anterior: `export default iniciar(formulario)` copia o valor atual do cookie CSRF para o campo no `submit`). E o login do painel passa a mostrar o "Você saiu." que o backend entrega em `$sucesso` depois do logout (`Login::index()` passa `sucesso` = `'Você saiu.'` com `?saiu=1`); a tela da área do cliente já renderiza `$sucesso` num `alert` success.

Valores visuais (tokens existentes; nada novo): botão "Sair" da top nav do cliente = `component('button', ['label' => 'Sair', 'icon' => 'log-out', 'variant' => 'ghost-on-dark', 'icon_only' => true])` (como hoje, só muda de `href` para `type` submit); atalho da conta = classe `$classeAtalho` já definida em `conta.php` (`flex min-h-12 items-center gap-3 rounded-md px-3 text-body-md text-text hover:bg-surface-subtle ...`), ícone `log-out` `size-5 text-muted`, texto `Sair`; aviso de sucesso no login = `component('alert', ['message' => (string) $sucesso, 'variant' => 'success'])` dentro de `<div class="mb-4">`, igual a `conecte/login.php`. Alvos ≥ 44px no celular (o `min-h-12` do atalho já cobre; o botão `icon_only` da top nav segue o tamanho atual do componente).

## Comportamento Esperado

Contrato consumido (backend, já entregue): 

```
200 {result:true} | {result:true, destino:"<url>"}            → http.js resolve com o objeto
422 {result:false, message:"Confira os campos destacados.", erros:{email?, senha?}, <csrf>:hash}
401 {result:false, message:"Os dados de acesso estão incorretos.", <csrf>:hash}
403 {result:false, message:<motivo>, <csrf>:hash}             (só painel: conta expirada / grupo desativado)
429 {result:false, message:"Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.", retry_after:<s>, <csrf>:hash} + Retry-After
403 HTML (sem JSON)                                            → CSRF recusado pelo framework
nunca vem "redirect" no corpo (o http.js trataria como sessão expirada)
```

Funções puras do módulo (`nomeCsrf` = conteúdo da meta `csrf-token-name`):

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `desfechoDaFalha(new ErroHttp(422, 'Confira os campos destacados.', {result:false, message:'Confira os campos destacados.', erros:{email:'O campo E-mail deve conter um endereço de e-mail válido.'}, OUTRO:'h1'}), 'OUTRO')` | `{ mensagem: null, erros: { email: 'O campo E-mail deve conter um endereço de e-mail válido.' }, token: 'h1' }` |
| `desfechoDaFalha(new ErroHttp(401, 'Os dados de acesso estão incorretos.', {result:false, message:'Os dados de acesso estão incorretos.', MAPOS_TOKEN:'h2'}), 'MAPOS_TOKEN')` | `{ mensagem: 'Os dados de acesso estão incorretos.', erros: null, token: 'h2' }` |
| `desfechoDaFalha(new ErroHttp(403, 'x', {result:false, message:'A conta do usuário está expirada, por favor entre em contato com o administrador do sistema.'}), 'MAPOS_TOKEN')` | `mensagem` = a `message` do servidor; `token: null` |
| `desfechoDaFalha(new ErroHttp(429, 'x', {result:false, message:'Muitas tentativas...', retry_after: 90}), 'MAPOS_TOKEN')` | `mensagem: 'Muitas tentativas de acesso. Tente de novo em 2 min.'` |
| idem com `retry_after: 30` | `'Muitas tentativas de acesso. Tente de novo em 1 min.'` |
| idem sem `retry_after` | `mensagem` = a `message` do servidor |
| `desfechoDaFalha(new ErroHttp(403, 'Erro 403', '<html>The action you have requested is not allowed.</html>'), 'MAPOS_TOKEN')` | `{ mensagem: MENSAGEM_SESSAO, erros: null, token: null }` |
| `desfechoDaFalha(new ErroHttp(0, 'Sem conexão ou a resposta demorou demais. Tente novamente.'), 'MAPOS_TOKEN')` | `mensagem: MENSAGEM_REDE` |
| `desfechoDaFalha(new TypeError('Failed to fetch'), 'MAPOS_TOKEN')` | `mensagem: MENSAGEM_REDE` |
| `desfechoDaFalha(new ErroHttp(500, 'Erro 500', {result:false}), 'MAPOS_TOKEN')` | `mensagem: MENSAGEM_PADRAO` |
| `desfechoDaFalha(new ErroHttp(401, 'x', {message: '<p>Os dados</p>'}), ...)` | `mensagem: 'Os dados'` (passa por `textoSimples()`) |
| `campoDoFoco({ erros: { senha: 'Informe a senha.' } })` / `({ erros: { email: 'x', senha: 'y' } })` / `({ mensagem: 'Os dados de acesso estão incorretos.' })` / `({ mensagem: MENSAGEM_REDE })` | `'senha'` / `'email'` / `'senha'` / `null` |
| `interpretarResposta({result:true})` / `({result:true, destino:'/index.php/login/doisFatores'})` / `({result:false, message:'x'})` | `{ ok: true }` / `{ ok: true, destino: '/index.php/login/doisFatores' }` / `{ ok: false, mensagem: 'x' }` |

Comportamento do `iniciar(formulario)` (derivado das funções acima): sucesso → `location.assign(destino ?? data-destino)`; falha → token (se veio) gravado no campo CSRF do form, **um só local por erro** (com `erros` → erro inline em cada campo por `mostrarErroDoCampo`, alert escondido; sem `erros` → texto no alert `[data-login-mensagem]`, campos sem erro), senha limpa nos casos 401/403/429, foco em `campoDoFoco()`, e o botão **sai do loading em todo desfecho de falha** (`marcarCarregando(botao, false)` num `finally`, exceto no sucesso, que navega).

Views:

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| renderizar `mapos/login` com `sucesso` = `'Você saiu.'` | um `alert` success com o texto `Você saiu.` antes do formulário |
| renderizar `mapos/login` com `sucesso` = `null` | nenhum alert success |
| renderizar a moldura `conecte/template` (tela `conecte/painel`) | `<form method="post" action="http://mapos.test/index.php/mine/sair" data-module="layout/sair">` com `<input type="hidden" name="MAPOS_TOKEN" value="hash-csrf">` e `<button type="submit" ...>` com o rótulo acessível `Sair`; nenhum `href` para `mine/sair`; nenhum `login/sair` |
| renderizar `conecte/conta` | atalho "Sair" como form POST para `mine/sair` com `data-module="layout/sair"`, campo CSRF e `<button type="submit" class="... min-h-12 ...">` com o texto `Sair`; nenhum `href` para `mine/sair` |

## TDD

**JS** — atualizar `tests/js/login.test.mjs` (node:test): os testes de `interpretarResposta` com `MAPOS_TOKEN` e de `mensagemDeFalha` deixam de valer (contrato antigo) e são substituídos pelos de `desfechoDaFalha`, `campoDoFoco`, `mensagemDeBloqueio` e o novo `interpretarResposta`. Os de `validar` e `textoSimples` continuam. Nomes (o critério filtra por `--test-name-pattern`): `desfechoDaFalha: 422 marca os campos sem alert`, `desfechoDaFalha: 401 e 403 com JSON mostram a message do servidor`, `desfechoDaFalha: 429 mostra o tempo restante em minutos`, `desfechoDaFalha: 403 sem JSON é o CSRF recusado`, `desfechoDaFalha: rede e timeout mostram MENSAGEM_REDE`, `desfechoDaFalha: token vem do campo de nome configurado`, `campoDoFoco: primeiro campo com erro ou a senha`, `interpretarResposta: sucesso com e sem destino`.

```js
test('desfechoDaFalha: 429 mostra o tempo restante em minutos', () => {
    const corpo = { result: false, message: 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.', retry_after: 90 };

    assert.deepEqual(desfechoDaFalha(new ErroHttp(429, corpo.message, corpo), 'MAPOS_TOKEN'), {
        mensagem: 'Muitas tentativas de acesso. Tente de novo em 2 min.',
        erros: null,
        token: null,
    });
});
```

**PHP** — `tests/Unit/LoginViewTest.php` ganha `testSucessoDoLogoutApareceNumAlertDeSucesso` (com e sem `sucesso`), usando o `renderizar()` existente; `tests/Unit/AreaDoClienteTest.php`: o `testMolduraTemTopNavTabbarEOModoDeCor` troca a asserção `href="http://mapos.test/index.php/mine/sair"` pela do formulário POST (mantém a de "nenhum `login/sair`"), e ganha `testContaSaiPorFormularioPost` renderizando `conecte/conta` pelo `tela()`/`renderizar()` do próprio arquivo. Assertar o HTML renderizado, nunca o arquivo-fonte.

**Refatoração:** remover `mensagemDeFalha` e o `token` do `interpretarResposta` (substituídos por `desfechoDaFalha`); atualizar o comentário do topo de `formulario.js` para o contrato por status.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `assets/js/modules/login/formulario.js` | Modificar | `desfechoDaFalha`, `mensagemDeBloqueio`, `campoDoFoco`, `interpretarResposta` novo; `iniciar` decide pelo `ErroHttp` |
| `tests/js/login.test.mjs` | Modificar | Testes do contrato por status |
| `application/views/mapos/login.php` | Modificar | `alert` success com `$sucesso` ("Você saiu.") |
| `application/views/conecte/template.php` | Modificar | "Sair" da top nav vira form POST com `layout/sair` |
| `application/views/conecte/conta.php` | Modificar | Atalho "Sair" vira form POST com `layout/sair` |
| `tests/Unit/LoginViewTest.php` | Modificar | Alert de sucesso no login do painel |
| `tests/Unit/AreaDoClienteTest.php` | Modificar | Sair por POST na moldura e na conta |
| `assets/dist/app.css` | Modificar | Só se `npm run build` gerar diff |

### Detalhamento Técnico

1. **`formulario.js` — funções exportadas** (assinaturas fixas; os testes as importam):

   ```js
   /** "Muitas tentativas de acesso. Tente de novo em N min." (N >= 1), ou null sem número. */
   export function mensagemDeBloqueio(segundos) {
       const s = Number(segundos);
       if (!Number.isFinite(s) || s <= 0) return null;

       return `Muitas tentativas de acesso. Tente de novo em ${Math.max(1, Math.ceil(s / 60))} min.`;
   }

   /**
    * Desfecho de uma falha do login (RF-29/RF-53): mensagem do alert OU erros
    * por campo (nunca os dois), e o token CSRF novo do corpo, quando veio.
    *
    * @returns {{mensagem: string|null, erros: Record<string,string>|null, token: string|null}}
    */
   export function desfechoDaFalha(erro, nomeCsrf) { /* ver tabela */ }

   /** 'email' | 'senha' | null — onde pôr o foco depois da falha. */
   export function campoDoFoco(desfecho) { /* ver tabela */ }
   ```

   Regras do `desfechoDaFalha`: não é `ErroHttp` → `MENSAGEM_REDE`; `status === 0` → `MENSAGEM_REDE`; `corpo` não é objeto (texto/HTML/null) → `403` vira `MENSAGEM_SESSAO`, os demais `MENSAGEM_PADRAO`; `corpo` objeto → `token = typeof corpo[nomeCsrf] === 'string' ? corpo[nomeCsrf] : null`; `422` com `erros` objeto → `erros` só com as chaves `email`/`senha`, cada valor por `textoSimples()`, `mensagem: null`; `429` → `mensagemDeBloqueio(corpo.retry_after) ?? textoSimples(corpo.message) || MENSAGEM_PADRAO`; `401`/`403` → `textoSimples(corpo.message) || MENSAGEM_PADRAO`; outros → `MENSAGEM_PADRAO`. `campoDoFoco`: `erros.email` → `'email'`; senão `erros.senha` → `'senha'`; sem `erros` e `mensagem` diferente de `MENSAGEM_REDE`/`MENSAGEM_SESSAO` → `'senha'`; senão `null`.
2. **`iniciar(formulario)`**: `const nomeCsrf = document.querySelector('meta[name="csrf-token-name"]')?.content ?? null;` lido uma vez. No `submit`: validação local como hoje; `mostrarMensagem('')`; limpar erros de campo; `carregando(true)`; `try { const resposta = interpretarResposta(await post(...)); if (resposta.ok) { window.location.assign(resposta.destino ?? formulario.dataset.destino); return; } mostrarMensagem(resposta.mensagem); } catch (erro) { const desfecho = desfechoDaFalha(erro, nomeCsrf); ... } finally { /* só se não navegou */ carregando(false); }`. Com o `return` dentro do `try`, o `finally` roda também no sucesso — usar uma flag `navegando` para não tirar o loading quando vai navegar. Gravar `desfecho.token` no `input[name="<nomeCsrf>"]` do form (procurar percorrendo os `input[type="hidden"]` por `name`, sem `CSS.escape`, para o módulo continuar importável no node). Com `erros`: `mostrarErroDoCampo(email, erros.email)` e `mostrarErroDoCampo(senha, erros.senha)`; sem `erros`: `mostrarMensagem(desfecho.mensagem)` e, se `campoDoFoco` for `'senha'`, `senha.value = ''`. Foco em `campoDoFoco(desfecho)`.
3. **`views/mapos/login.php`**: antes do `<form id="formLogin" ...>`, dentro da `<section>`, `<?php if (! empty($sucesso)) { ?><div class="mb-4"><?= component('alert', ['message' => (string) $sucesso, 'variant' => 'success']) ?></div><?php } ?>` e declarar `@var string|null $sucesso` no docblock.
4. **`views/conecte/template.php`** (linha do `component('button', ['label' => 'Sair', ... 'href' => site_url('mine/sair'), 'icon_only' => true])`):

   ```php
   <form method="post" action="<?= e(site_url('mine/sair')) ?>" <?= js_module('layout/sair') ?>>
       <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
       <?= component('button', ['label' => 'Sair', 'icon' => 'log-out', 'variant' => 'ghost-on-dark', 'type' => 'submit', 'icon_only' => true]) ?>
   </form>
   ```

5. **`views/conecte/conta.php`** (atalho `<a href="<?= e(site_url('mine/sair')) ?>" class="<?= e($classeAtalho) ?>">`): mesmo `<form>` com `js_module('layout/sair')` e o hidden; dentro, `<button type="submit" class="<?= e($classeAtalho) ?> w-full cursor-pointer text-left">` com o mesmo ícone e `<span class="flex-1">Sair</span>`.
6. Guardas do CI: todo valor impresso por `e()`, módulo por `js_module()`, sem `<script>` inline; `php scripts/check-escape.php` e `php scripts/check-inline-script.php` sem subir baseline; `npm run build` sem diff em `assets/dist` (se houver, commitar).

**Contrato com camadas adjacentes:**

```
Consome de: lib/http.js
  - Sucesso: post() resolve com o objeto JSON
  - Falha: rejeita ErroHttp { status, message, corpo } — corpo objeto quando JSON, texto quando não
  - Rede/timeout: ErroHttp status 0
Consome de: assets/js/modules/layout/sair.js (task anterior)
  - export default iniciar(formulario): copia o cookie CSRF para o campo no submit
Consome de: backend
  - POST /mine/sair (CSRF) → 302 mine?saiu=1 ; GET → 405
  - $sucesso = "Você saiu." na view mapos/login (Login::index com ?saiu=1)
```
