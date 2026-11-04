---
checkpoint: null
complexity: M
created_at: "2026-11-04 01:19:31"
criteria:
    - done: false
      test: node --test --test-name-pattern='renovarCsrf copia' tests/js/logout.test.mjs
      text: renovarCsrf copia o valor do cookie CSRF (novo123) para o campo hidden do formulário e devolve true
    - done: false
      test: node --test --test-name-pattern='renovarCsrf (sem o cookie|cria o campo)' tests/js/logout.test.mjs
      text: renovarCsrf sem o cookie mantém o valor velho e devolve false; com cookie e sem campo cria o hidden com o valor do cookie
    - done: false
      test: node --test --test-name-pattern='iniciar renova no submit' tests/js/logout.test.mjs
      text: iniciar(form) renova o token no submit sem cancelar o envio (preventDefault não chamado)
    - done: false
      test: application/vendor/bin/phpunit --filter testMenuDoUsuarioSaiPorFormularioPost tests/Unit/LogoutPorPostTest.php
      text: Menu do usuário (tema/conteudo) renderiza 'Sair do Sistema' como form POST para login/sair com data-module=layout/sair, hidden MAPOS_TOKEN=hash-csrf e button submit, sem href para login/sair
    - done: false
      test: application/vendor/bin/phpunit --filter testSidebarSaiPorFormularioPost tests/Unit/LogoutPorPostTest.php
      text: Sidebar (tema/menu) renderiza o Sair como form POST com data-module=layout/sair, campo CSRF e button submit com title Sair e rótulo sr-only Sair, sem href para login/sair
    - done: false
      test: application/vendor/bin/phpunit --filter testBancoDesatualizadoSaiPorFormularioPost tests/Unit/LogoutPorPostTest.php
      text: Banco desatualizado (com e sem cSistema) renderiza o Sair como form POST para acaoSair com o hidden csrfNome/csrfValor e button outline submit
    - done: false
      test: application/vendor/bin/phpunit --filter testVoltarAoLoginDoDoisFatoresVaiParaOLogin tests/Unit/LogoutPorPostTest.php
      text: Tela do segundo fator tem Voltar ao login com href site_url('login') e nenhuma ocorrência de login/sair
    - done: false
      test: '! grep -nE "href.{0,40}login/s[a]ir" application/views/tema/conteudo.php application/views/tema/menu.php application/views/mapos/banco-desatualizado.php application/views/mapos/login_dois_fatores.php'
      text: Nenhum link GET para login/sair nas views do painel tocadas
    - done: false
      test: php scripts/check-escape.php && php scripts/check-inline-script.php
      text: Guardas de escape e de script inline não sobem a baseline
    - done: false
      test: npm run build && git diff --exit-code -- assets/dist/app.css
      text: CSS compilado em dia com as views
    - done: false
      test: npm run test:js && application/vendor/bin/phpunit tests/Unit/LogoutPorPostTest.php tests/Unit/BancoDesatualizadoTest.php tests/Unit/LayoutTest.php tests/Unit/AcessibilidadeTest.php tests/Unit/BuscaGlobalTest.php
      text: Testes JS e as suítes de layout e de banco desatualizado passam
deps:
    - TASK-025
files:
    - assets/js/modules/layout/sair.js
    - tests/js/logout.test.mjs
    - application/views/tema/conteudo.php
    - application/views/tema/menu.php
    - application/views/mapos/banco-desatualizado.php
    - application/views/mapos/login_dois_fatores.php
    - tests/Unit/LogoutPorPostTest.php
    - assets/dist/app.css
id: TASK-029
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
tests: node --test tests/js/logout.test.mjs && application/vendor/bin/phpunit tests/Unit/LogoutPorPostTest.php
title: Sair por formulário POST nas telas do painel
updated_at: "2026-11-04 01:19:31"
---
## Contexto

O logout do painel passou a aceitar só POST com CSRF: `GET /login/sair` agora responde **405** (`Allow: POST`) e não encerra a sessão; `POST /login/sair` encerra e redireciona para `login?saiu=1` (RF-27, entregue pela task de backend desta fase). Os pontos "Sair" do painel ainda são links `<a href=".../login/sair">` — hoje eles levariam o usuário a uma página 405. Esta task troca esses pontos por `<form method="post">` com o campo CSRF, mantendo variante, ícone, rótulo e posição de hoje, e cria o módulo `assets/js/modules/layout/sair.js`, que no `submit` copia o valor atual do cookie CSRF para o campo do formulário (o token é regenerado por requisições AJAX da página, e o campo renderizado no carregamento pode ter ficado velho).

Também ajusta a tela do segundo fator (`mapos/login_dois_fatores.php`): o "Voltar ao login" apontava para `login/sair` (GET, agora 405). Quem está nessa tela ainda **não** está logado — a sessão só guarda o `dois_fatores` pendente, e a task de backend fez `Login::index()` descartá-lo. Então o "Voltar ao login" vira um link comum para `site_url('login')`, sem formulário.

Os pontos "Sair" da área do cliente (`conecte/template.php`, `conecte/conta.php`) ficam para a task do módulo de login, que roda depois desta e reaproveita o `layout/sair` criado aqui; `conecte/definir_senha.php` já tem o formulário POST.

Valores visuais usados (DESIGN, tokens existentes; nada novo): menu do usuário no topo (`tema/conteudo.php`) com item `flex items-center gap-2 rounded-md px-3 py-2 text-sm text-text hover:bg-surface-subtle focus-visible:outline-3 focus-visible:outline-ring` e ícone `size-[18px] text-muted`; sidebar (`tema/menu.php`) com botão de ícone `v5-rotulo inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted hover:bg-surface-subtle hover:text-text` + `$foco`, rótulo `sr-only` "Sair" e `title="Sair"`; tela de banco desatualizado com `component('button', ['label' => 'Sair', 'variant' => 'outline', ...])`. Foco visível pelo `outline-ring` (`#3b82f6`). Usar só classes que já existem no `assets/dist/app.css` (`w-full`, `text-left`, `cursor-pointer` existem); se alguma classe nova entrar, rodar `npm run build` e commitar o `assets/dist/app.css`.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| renderizar `tema/conteudo.php` (menu do usuário) | `<form method="post" action="http://mapos.test/index.php/login/sair" data-module="layout/sair">` com `<input type="hidden" name="MAPOS_TOKEN" value="hash-csrf">` e `<button type="submit" ...>` com o ícone `log-out` e o texto `Sair do Sistema`; nenhum `href` para `login/sair` |
| renderizar `tema/menu.php` (rodapé da sidebar) | form POST para `login/sair` com `data-module="layout/sair"`, campo CSRF e `<button type="submit" title="Sair">` com o ícone e `<span class="sr-only">Sair</span>`; nenhum `href` para `login/sair` |
| renderizar `mapos/banco-desatualizado.php` (com e sem `cSistema`) | "Sair" é `<form method="post" action="<acaoSair>">` com `name="<csrfNome>" value="<csrfValor>"` e `component('button', ['label' => 'Sair', 'variant' => 'outline', 'type' => 'submit'])`; nenhum `href` para `login/sair` (a tela não carrega `app.js`: o form funciona sem JS) |
| renderizar `mapos/login_dois_fatores.php` | "Voltar ao login" com `href="http://mapos.test/index.php/login"`; nenhuma ocorrência de `login/sair` |
| `renovarCsrf(form, raiz)` com metas `csrf-token-name=MAPOS_TOKEN`, `csrf-cookie-name=MAPOS_COOKIE`, cookie `MAPOS_COOKIE=novo123` e campo `MAPOS_TOKEN` com `velho` | campo passa a `novo123`; devolve `true` |
| `renovarCsrf` sem o cookie (`raiz.cookie = ''`) | campo continua `velho`; devolve `false` |
| `renovarCsrf` com cookie mas o form sem o campo hidden | cria `<input type="hidden" name="MAPOS_TOKEN" value="novo123">` no form; devolve `true` |
| `iniciar(form)` e disparar `submit` | o listener chama `renovarCsrf` antes do envio (o campo tem o valor do cookie no momento do submit); o envio **não** é cancelado (`preventDefault` não é chamado) |

## TDD

**JS** — `tests/js/logout.test.mjs` (node:test), com dublês simples de `raiz` (`{ querySelector(seletor) → { content } | null, cookie: 'MAPOS_COOKIE=novo123' }`) e de formulário (`querySelectorAll`/`appendChild`/`addEventListener`). O node não tem `CSS.escape`: o módulo procura o campo percorrendo `formulario.querySelectorAll('input[type="hidden"]')` e comparando `campo.name`, sem seletor montado com o nome.

```js
test('renovarCsrf copia o valor do cookie para o campo do formulário', () => {
    const campo = { name: 'MAPOS_TOKEN', value: 'velho' };
    const form = formularioFalso([campo]);

    assert.equal(renovarCsrf(form, raizFalsa('MAPOS_COOKIE=novo123')), true);
    assert.equal(campo.value, 'novo123');
});
```

**PHP** — `tests/Unit/LogoutPorPostTest.php` (novo, `final class LogoutPorPostTest extends MaposTestCase`): renderiza cada view com `include` dentro de uma closure ligada (`Closure::bind`) a um objeto de contexto com `security` dublê (`get_csrf_token_name()` → `'MAPOS_TOKEN'`, `get_csrf_hash()` → `'hash-csrf'`) e `load`/`config` dublês, como `tests/Unit/AreaDoClienteTest.php` faz para as telas do cliente (linhas ~140-160); variáveis mínimas de `$layout` para `tema/menu.php` e `tema/conteudo.php` (`usuario`, `iniciais`, `avatar`, `tem_foto`, `grupos` vazio, `breadcrumb` vazio, `acao` null, `saudacao`, `versao`, `nome_sistema`) e `$view` apontando para uma view vazia de teste ou com `load->view` dublê. Dublês sempre anônimos (nome repetido entre arquivos derruba a suíte com `Cannot redeclare class`). Assertar o HTML renderizado (regex sobre `<form method="post" action="...login/sair"`, o hidden e o `<button type="submit"`), nunca o arquivo-fonte.

```php
public function testMenuDoUsuarioSaiPorFormularioPost(): void
{
    $html = $this->renderizar('tema/conteudo', $this->dadosDoLayout());

    $this->assertMatchesRegularExpression('#<form method="post" action="http://mapos\.test/index\.php/login/sair"[^>]*data-module="layout/sair"#', $html);
    $this->assertStringContainsString('<input type="hidden" name="MAPOS_TOKEN" value="hash-csrf">', $html);
    $this->assertMatchesRegularExpression('#<button type="submit"[^>]*>.*Sair do Sistema</button>#s', $html);
    $this->assertDoesNotMatchRegularExpression('#href="[^"]*login/sair"#', $html);
}
```

Cobrir as demais linhas da tabela com os nomes dos critérios. Nomes dos testes JS (o critério filtra por `--test-name-pattern`): `renovarCsrf copia o valor do cookie para o campo do formulário`, `renovarCsrf sem o cookie mantém o campo`, `renovarCsrf cria o campo quando o formulário não tem` e `iniciar renova no submit sem cancelar o envio`.

**Refatoração:** se os dois forms do painel (menu e sidebar) repetirem as mesmas três linhas de abertura (form + hidden), manter assim — são duas views com marcação diferente; não criar componente novo (o inventário não ganha componente nesta feature).

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `assets/js/modules/layout/sair.js` | Criar | `renovarCsrf(formulario, raiz = document)` e `export default iniciar(formulario)` |
| `tests/js/logout.test.mjs` | Criar | Testes do módulo |
| `application/views/tema/conteudo.php` | Modificar | "Sair do Sistema" do menu do usuário vira form POST |
| `application/views/tema/menu.php` | Modificar | "Sair" da sidebar vira form POST |
| `application/views/mapos/banco-desatualizado.php` | Modificar | Os dois "Sair" viram form POST com `$csrfNome`/`$csrfValor` |
| `application/views/mapos/login_dois_fatores.php` | Modificar | "Voltar ao login" → `site_url('login')` |
| `tests/Unit/LogoutPorPostTest.php` | Criar | Renderização das quatro views |
| `assets/dist/app.css` | Modificar | Só se `npm run build` gerar diff (classe nova) |

### Detalhamento Técnico

1. **`assets/js/modules/layout/sair.js`** (contrato que a task seguinte reaproveita nas telas da área do cliente):

   ```js
   // "Sair" por formulário POST (RF-27). O token do CSRF é regenerado a cada
   // requisição; no submit, o campo recebe o valor atual do cookie para o
   // logout não ser recusado por um token velho.
   import { tokenCsrf } from '../../lib/http.js';

   export function renovarCsrf(formulario, raiz = document) {
       const csrf = tokenCsrf(raiz);
       if (!csrf) return false;

       let campo = Array.from(formulario.querySelectorAll('input[type="hidden"]')).find((c) => c.name === csrf.campo);
       if (!campo) {
           campo = (raiz.createElement ? raiz : document).createElement('input');
           campo.type = 'hidden';
           campo.name = csrf.campo;
           formulario.appendChild(campo);
       }
       campo.value = csrf.valor;

       return true;
   }

   export default function iniciar(formulario) {
       formulario.addEventListener('submit', () => renovarCsrf(formulario));
   }
   ```

   `tokenCsrf(raiz)` (de `lib/http.js`) lê as metas `csrf-token-name` e `csrf-cookie-name` e o `raiz.cookie`; devolve `{ campo, valor }` ou `null`. No teste, o dublê de `raiz` precisa de `createElement` para o caso "form sem o campo".
2. **`tema/conteudo.php`** (linha do "Sair do Sistema", hoje `<a href="<?= e(site_url('login/sair')) ?>" ...>`):

   ```php
   <form method="post" action="<?= e(site_url('login/sair')) ?>" <?= js_module('layout/sair') ?>>
       <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
       <button type="submit" class="flex w-full cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-text hover:bg-surface-subtle focus-visible:outline-3 focus-visible:outline-ring"><?= icon('log-out', ['class' => 'size-[18px] text-muted']) ?>Sair do Sistema</button>
   </form>
   ```

3. **`tema/menu.php`** (rodapé da sidebar): mesmo padrão; o `<button type="submit" title="Sair">` leva as classes atuais do `<a>` (`v5-rotulo inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted hover:bg-surface-subtle hover:text-text <?= e($foco) ?>` + `cursor-pointer`), o ícone `size-5` e o `<span class="sr-only">Sair</span>`. O `<form>` não pode quebrar o `flex` da linha do usuário: dar ao form a classe `v5-rotulo shrink-0` se o `v5-rotulo` esconde o item na sidebar recolhida (conferir no `layout.css` qual elemento recebe o `v5-rotulo` hoje e manter o mesmo efeito).
4. **`mapos/banco-desatualizado.php`**: nos dois lugares, trocar `component('button', ['label' => 'Sair', 'variant' => 'outline', 'href' => $acaoSair])` por

   ```php
   <form method="post" action="<?= e($acaoSair) ?>">
       <input type="hidden" name="<?= e($csrfNome) ?>" value="<?= e($csrfValor) ?>">
       <?= component('button', ['label' => 'Sair', 'variant' => 'outline', 'type' => 'submit']) ?>
   </form>
   ```

   Esta tela não carrega `app.js` (é avulsa, abre com o esquema antigo): sem `js_module`, o form funciona com o token renderizado. O `BancoDesatualizadoTest` existente procura `login/sair` no HTML e continua passando (agora no `action`).
5. **`mapos/login_dois_fatores.php`**: `'secundaria' => ['label' => 'Voltar ao login', 'href' => site_url('login'), 'icon' => 'log-in']`.
6. Guardas do CI: todo valor impresso por `e()`; `js_module()` é a forma aceita de ligar módulo (sem `<script>` inline) — `php scripts/check-escape.php` e `php scripts/check-inline-script.php` sem subir baseline.

**Contrato com camadas adjacentes:**

```
Consome de: backend (Login::sair)
  - POST /login/sair com o campo CSRF → 302 /index.php/login?saiu=1
  - GET  /login/sair → 405 (por isso nenhum link GET pode sobrar)
  - CSRF inválido → 403 do framework (o layout/sair evita copiando o cookie no submit)
Produz para: task do login e da área do cliente
  - assets/js/modules/layout/sair.js: export default iniciar(formulario); export renovarCsrf(formulario, raiz)
```
