---
checkpoint: null
complexity: M
created_at: "2026-11-03 16:02:35"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testSenhaApareceUmaUnicaVezSoNoCampoReadonly tests/Unit/SenhaInicialViewTest.php
      text: A senha gerada aparece exatamente uma vez no HTML, só no value do input readonly
    - done: false
      test: application/vendor/bin/phpunit --filter Email tests/Unit/SenhaInicialViewTest.php
      text: 'Texto de envio segue emailEnviado: true → ''Também enviada por e-mail ao cliente.''; false → ''Não foi possível enviar por e-mail: repasse a senha ao cliente.'' (nunca os dois)'
    - done: false
      test: application/vendor/bin/phpunit --filter Escap tests/Unit/SenhaInicialViewTest.php
      text: Nome do cliente sai escapado
    - done: false
      test: grep -qE 'flex max-w-4xl flex-col gap-4 pt-2 pb-8' application/views/clientes/senha_inicial.php && grep -qE 'font-display text-heading-xl' application/views/clientes/senha_inicial.php
      text: A tela usa o wrapper de formulário com largura máxima (max-w-4xl) e o h1 em font-display text-heading-xl
    - done: false
      test: grep -cE "component\('(alert|input|button|card)'" application/views/clientes/senha_inicial.php | grep -qvE '^[0-3]$' && ! grep -nE 'font-family|font-sans|font-\[|style=' application/views/clientes/senha_inicial.php
      text: Só componentes do inventário e nenhuma família tipográfica declarada na view (Rubik herdada da base)
    - done: false
      test: grep -qE "'class' => 'font-mono'" application/views/clientes/senha_inicial.php && grep -qE "'readonly' => true" application/views/clientes/senha_inicial.php && grep -qE "'label' => 'Senha inicial'" application/views/clientes/senha_inicial.php
      text: O campo da senha usa font-mono, é readonly e tem rótulo visível 'Senha inicial'
    - done: false
      test: grep -qE "'icon_only' => true" application/views/clientes/senha_inicial.php && grep -qE 'aria-pressed' application/views/clientes/senha_inicial.php && grep -qE "'label' => 'Copiar senha'" application/views/clientes/senha_inicial.php
      text: Mostrar/ocultar tem rótulo acessível não renderizado (icon_only) com aria-pressed e aria-controls; 'Copiar senha' tem rótulo visível
    - done: false
      test: grep -qE "'role' => 'status'" application/views/clientes/senha_inicial.php
      text: O aviso de senha é alert warning com role=status
    - done: false
      test: php scripts/check-inline-script.php && php scripts/check-escape.php
      text: Sem script inline e sem subir a baseline de escape
    - done: false
      test: node --test --test-name-pattern='copiar' tests/js/senha-inicial.test.mjs
      text: Copiar com sucesso avisa só 'Copiado' (success); sem Clipboard seleciona o campo e avisa 'Copie manualmente' (warning)
    - done: false
      test: node --test --test-name-pattern='mostrar|visibilidade' tests/js/senha-inicial.test.mjs
      text: Mostrar/ocultar alterna type password/text e aria-pressed
    - done: false
      test: '! grep -nE ''localStorage|sessionStorage|console\.log'' assets/js/modules/clientes/senha-inicial.js'
      text: O módulo não guarda nem imprime a senha
    - done: false
      test: grep -nE "deepEqual\(avisos" tests/js/senha-inicial.test.mjs
      text: Os testes JS assertam o aviso exato, não só que algo foi chamado
    - done: false
      test: npm run build && git diff --quiet -- assets/dist
      text: CSS compilado em dia com as views
deps: []
files:
    - application/views/clientes/senha_inicial.php
    - assets/js/modules/clientes/senha-inicial.js
    - tests/Unit/SenhaInicialViewTest.php
    - tests/js/senha-inicial.test.mjs
    - assets/dist/app.css
id: TASK-008
linter: php scripts/check-escape.php && php scripts/check-inline-script.php
n45_version: 1.1.0
outcome: ""
persona: frontend
phase: 'Phase 1 — Base de segurança: schema, API, gateways e avisos de sessão'
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/SenhaInicialViewTest.php && node --test tests/js/senha-inicial.test.mjs
title: Tela da senha inicial do cliente exibida uma única vez
updated_at: "2026-11-03 16:02:35"
---
## Contexto

Hoje o cliente cadastrado pelo operador sem senha ganha o próprio CPF/CNPJ como senha. A feature troca isso por uma **senha aleatória forte**, mostrada ao operador **uma única vez**, na própria resposta do POST de `/clientes/adicionar` (sem redirect e sem flashdata: flashdata é gravado em `ci_sessions` no banco e a senha em claro não pode ser persistida). Esta task cria só a **tela** dessa resposta e o módulo JS dela; o controller que gera a senha, envia o e-mail e renderiza esta view é de outra task, que consome o contrato de variáveis abaixo.

A tela vive dentro da moldura do painel (o controller chama `$this->layout()` como nas demais telas; a view é só o conteúdo, que vai em `.v5-conteudo`). Composição de tela do projeto: wrapper `flex flex-col gap-4 pt-2 pb-8` com `max-w-4xl`, título `h1` com `font-display text-heading-xl text-text`, blocos dentro de `component('card')`; nenhum markup ad-hoc onde existe componente (`application/views/components/`: `alert`, `input`, `button`, `card`, `toast`, `link` ...). Modelo de formulário/tela: `application/views/clientes/formulario.php`. Todo valor impresso passa por `e()` (ou pelos componentes, que já escapam); sem `<script>` inline — JS de página é módulo em `assets/js/modules/<pasta>/<nome>.js` ligado por `<?= js_module('pasta/nome') ?>`. Só classes de token do Tailwind 4 (`assets/src/tokens.css`; a paleta padrão está desligada): `text-text`, `text-muted`, `bg-surface`, `font-mono`, `text-caption`, `text-body-md` etc. Depois de mudar view, `npm ci && npm run build` e commitar `assets/dist/` (o CI falha se estiver defasado).

Valores visuais que esta tela usa: família **Rubik** herdada da base (nenhuma declaração de fonte na view); título em `font-display` (Space Grotesk) `text-heading-xl`; senha em **`font-mono`** (`--font-mono: Monaco, Menlo, "Ubuntu Mono", monospace`); aviso no `alert` `warning` (`bg-warning-soft text-warning-ink`, ícone `triangle-alert`); foco visível pelo `outline-ring` de 3px que os componentes já aplicam; alvo mínimo de 44px no celular (os componentes `button`/`input` já trazem `max-sm:h-11`/`max-sm:min-h-11`).

## Comportamento Esperado

**Contrato de variáveis da view `application/views/clientes/senha_inicial.php`** (o controller passa exatamente estas):

```php
/** @var object $cliente       idClientes (int), nomeCliente (string) */
/** @var string $senhaInicial  senha gerada, 16 caracteres */
/** @var bool   $emailEnviado  true se o e-mail com a senha saiu */
```

Estrutura (de cima para baixo; campo + Copiar na mesma linha a partir de 640px e empilhados abaixo):

```
Cliente cadastrado                                   (h1)
<nome do cliente>                                    (caption)
┌ card ───────────────────────────────────────────────────────────┐
│ [!] Senha inicial do cliente: exibida apenas agora  (alert warning, role="status")
│ [ Senha inicial  ••••••••••••••••  (mono, readonly) ] [👁] [COPIAR SENHA]
│ Também enviada por e-mail ao cliente.               (ou o texto de falha)
│                                     [VER CLIENTE] [VOLTAR À LISTA]
└──────────────────────────────────────────────────────────────────┘
```

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| Render com `senhaInicial = 'Ab3dEf6hJk8mNp2q'`, `emailEnviado = true` | HTML contém `Ab3dEf6hJk8mNp2q` **exatamente 1 vez** (no `value` do `input` readonly `type="password"`), o texto `Também enviada por e-mail ao cliente.` e **não** contém `Não foi possível enviar` |
| Render com `emailEnviado = false` | contém `Não foi possível enviar por e-mail: repasse a senha ao cliente.` e não contém `Também enviada por e-mail` |
| Render com `nomeCliente = 'Ana <b>&'` | nome sai escapado (`Ana &lt;b&gt;&amp;`), nunca a tag crua |
| Render | `h1` com `font-display text-heading-xl` e texto `Cliente cadastrado`; wrapper com `max-w-4xl` |
| Render | `input` com rótulo visível `Senha inicial`, `readonly`, classe `font-mono`, `autocomplete="off"` |
| Render | botão `Copiar senha` com rótulo **visível** (não `icon_only`), variante `outline`, `data-copiar` apontando para o id do campo |
| Render | botão de mostrar/ocultar `icon_only` (rótulo `Mostrar senha` só para leitor de tela), `aria-pressed="false"`, `aria-controls` = id do campo |
| Render | `alert` warning com `role="status"` (aviso, não erro) |
| Render | links `Ver cliente` → `site_url('clientes/visualizar/<id>')` e `Voltar à lista` → `site_url('clientes')` |
| Clique em `Copiar senha` com Clipboard disponível | `navigator.clipboard.writeText('Ab3dEf6hJk8mNp2q')`; toast `success` com a mensagem exata `Copiado` (nunca a senha no toast) |
| Clique em `Copiar senha` com Clipboard indisponível/recusado | o texto do campo fica selecionado; toast `warning` `Copie manualmente` |
| Clique no botão de mostrar com o campo `password` | campo vira `type="text"` e `aria-pressed="true"`; segundo clique volta a `password`/`"false"` |
| Módulo `senha-inicial.js` | não usa `localStorage`/`sessionStorage` e não faz `console.log` da senha |

## TDD

Dois testes:

- **PHP** — `tests/Unit/SenhaInicialViewTest.php` (`final class ... extends MaposTestCase`): renderizar a view fora do framework com `$this` falso e `extract()`, como `tests/Unit/LoginViewTest.php` (stubs de `site_url`/`base_url` com `if (! function_exists(...))`). Os componentes vêm de `component()` (já carregado pelo bootstrap).
- **JS** — `tests/js/senha-inicial.test.mjs` (`node:test`): funções puras exportadas do módulo, com dublês de campo/botão/área de transferência e `avisar` injetado.

```php
public function testSenhaApareceUmaUnicaVezSoNoCampoReadonly(): void
{
    $html = $this->renderizar(['senhaInicial' => 'Ab3dEf6hJk8mNp2q', 'emailEnviado' => true]);

    $this->assertSame(1, substr_count($html, 'Ab3dEf6hJk8mNp2q'));
    $this->assertMatchesRegularExpression('/<input[^>]*readonly[^>]*value="Ab3dEf6hJk8mNp2q"|<input[^>]*value="Ab3dEf6hJk8mNp2q"[^>]*readonly/', $html);
}
```

```js
test('copiar com sucesso avisa só "Copiado"', async () => {
    const avisos = [];
    const campo = { value: 'Ab3dEf6hJk8mNp2q', select() {} };
    await copiarSenha(campo, { writeText: async () => {} }, (o) => avisos.push(o));
    assert.deepEqual(avisos, [{ variante: 'success', mensagem: 'Copiado' }]);
});
```

Cobrir os demais casos da tabela.

**Refatoração:** nenhuma prevista.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/views/clientes/senha_inicial.php` | Criar | Tela "Cliente cadastrado" com a senha exibida uma vez |
| `assets/js/modules/clientes/senha-inicial.js` | Criar | Copiar (Clipboard API, com fallback de seleção) e mostrar/ocultar |
| `tests/Unit/SenhaInicialViewTest.php` | Criar | Render da view |
| `tests/js/senha-inicial.test.mjs` | Criar | Funções do módulo |
| `assets/dist/app.css` | Modificar (gerado) | `npm run build` se a view usar classe ainda não presente no CSS compilado |

### Detalhamento Técnico

1. **View**: `<div class="flex max-w-4xl flex-col gap-4 pt-2 pb-8" <?= js_module('clientes/senha-inicial') ?>>` → `<header>` com `h1` e `p.text-caption.text-muted` (nome) → `component('card', ['body' => [...]])` com, em ordem: `component('alert', ['variant' => 'warning', 'message' => 'Senha inicial do cliente: exibida apenas agora', 'attrs' => ['role' => 'status']])`; a linha `div.flex.items-end.gap-2.max-sm:flex-col.max-sm:items-stretch` com `component('input', ['name' => 'senha_inicial', 'id' => 'senha-inicial', 'label' => 'Senha inicial', 'type' => 'password', 'value' => $senhaInicial, 'readonly' => true, 'autocomplete' => 'off', 'class' => 'font-mono'])`, `component('button', ['label' => 'Mostrar senha', 'icon' => 'eye', 'icon_only' => true, 'variant' => 'ghost', 'attrs' => ['data-mostrar-senha' => 'senha-inicial', 'aria-controls' => 'senha-inicial', 'aria-pressed' => 'false']])` e `component('button', ['label' => 'Copiar senha', 'icon' => 'copy', 'variant' => 'outline', 'attrs' => ['data-copiar' => 'senha-inicial']])`; o texto de envio em `p.text-body-md.text-muted` (dois textos exatos da tabela, escolhidos por `$emailEnviado`); ações `component('button', ['label' => 'Ver cliente', 'variant' => 'outline', 'href' => site_url('clientes/visualizar/' . (int) $cliente->idClientes)])` e `component('button', ['label' => 'Voltar à lista', 'variant' => 'ghost', 'href' => site_url('clientes')])`. Conferir no `componente_helper.php` como passar um fragmento composto para o slot `body` do card (lista de `HtmlSeguro`/strings) — não montar `<section>` de card na mão.
2. A senha **só** vai no `value` do `input` (o componente escapa). Nunca em `data-*`, título, `aria-label` ou texto.
3. **Módulo** `senha-inicial.js` (`export default function iniciar(raiz)`): delegação de clique em `raiz` para `[data-copiar]` e `[data-mostrar-senha]`. Exportar as funções puras para o teste: `export async function copiarSenha(campo, area = globalThis.navigator?.clipboard, avisar = (o) => mostrarToast(o))` (reusar `copiar(campo, area)` de `assets/js/modules/conecte/copiar.js`, que já faz `writeText` e, na falha, `campo.select()`; sucesso → `avisar({ variante: 'success', mensagem: 'Copiado' })`, falha → `avisar({ variante: 'warning', mensagem: 'Copie manualmente' })`) e `export function alternarVisibilidade(campo, botao)` (`password` ↔ `text` e `aria-pressed`, como `assets/js/modules/formulario/padrao.js` faz dentro de formulário). Para copiar com o campo em `type="password"`, `writeText(campo.value)` funciona; o fallback de seleção troca o campo para `text` antes do `select()` e mantém `aria-pressed` coerente.
4. Rodar `php scripts/check-escape.php` e `php scripts/check-inline-script.php` (não podem subir a baseline) e `npm ci && npm run build`; commitar `assets/dist/` só se mudou.

**Contrato com a task do controller (`Clientes::adicionar`)**:

```
Renderiza: $this->layout('clientes/senha_inicial', ['cliente' => $cliente, 'senhaInicial' => $senha, 'emailEnviado' => $enviado])
           (ou o mecanismo de layout atual do MY_Controller; nomes das variáveis fixos)
Resposta com Cache-Control: no-store (responsabilidade do controller)
```
