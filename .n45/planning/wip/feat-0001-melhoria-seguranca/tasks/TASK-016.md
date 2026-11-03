---
checkpoint: null
complexity: M
created_at: "2026-11-03 18:51:53"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testTelaTemTituloFraseEAvisoNaSuperficieDeEntrada tests/Unit/DefinirSenhaViewTest.php
      text: A tela renderiza o título 'Defina sua nova senha', a frase de apoio e o aviso warning com role status, na superfície de entrada escura
    - done: false
      test: application/vendor/bin/phpunit --filter testFormularioPostaParaDefinirSenhaComCsrf tests/Unit/DefinirSenhaViewTest.php
      text: O formulário faz POST para mine/definirSenha com novalidate, formulario/padrao e o campo CSRF
    - done: false
      test: application/vendor/bin/phpunit --filter testCamposDeSenhaVoltamLimposComAutocomplete tests/Unit/DefinirSenhaViewTest.php
      text: Os três campos são password, sem value, com autocomplete current-password / new-password / new-password e senha_atual com autofocus
    - done: false
      test: application/vendor/bin/phpunit --filter testNovaSenhaTemAjudaLigadaPorAriaDescribedby tests/Unit/DefinirSenhaViewTest.php
      text: A nova senha tem a ajuda 'Mínimo de 8 caracteres.' ligada por aria-describedby="campo-senha-ajuda"
    - done: false
      test: application/vendor/bin/phpunit --filter testErroDaSenhaAtualMarcaOCampo tests/Unit/DefinirSenhaViewTest.php
      text: Erro da senha atual marca aria-invalid e aria-describedby="campo-senha_atual-erro" com o texto do servidor
    - done: false
      test: application/vendor/bin/phpunit --filter testErroGeralViraAlertDanger tests/Unit/DefinirSenhaViewTest.php
      text: _geral aparece num alert danger com role alert; sem _geral não há role alert na página
    - done: false
      test: application/vendor/bin/phpunit --filter testMostrarSenhaTemRotuloAcessivelNaoVisivel tests/Unit/DefinirSenhaViewTest.php
      text: Os botões de mostrar senha têm data-mostrar-senha, aria-controls, aria-pressed=false e rótulo 'Mostrar senha' só acessível (sr-only)
    - done: false
      test: application/vendor/bin/phpunit --filter testBotaoDeLogoutEFormularioPost tests/Unit/DefinirSenhaViewTest.php
      text: Sair é um formulário POST para mine/sair com o campo CSRF, sem link GET
    - done: false
      test: application/vendor/bin/phpunit --filter testEscapaErrosESemScriptInline tests/Unit/DefinirSenhaViewTest.php
      text: Textos de erro saem escapados e não há script inline
    - done: false
      test: application/vendor/bin/phpunit --filter testDefinirSenhaLigaAjudaEErroPorAriaDescribedby tests/Unit/AcessibilidadeTest.php
      text: AcessibilidadeTest cobre aria-describedby="campo-senha-ajuda campo-senha-erro" da tela definir_senha
    - done: false
      test: grep -q 'max-w-md' application/views/conecte/definir_senha.php && grep -q 'superficie-entrada' application/views/conecte/definir_senha.php
      text: A view usa o card estreito centralizado da superfície de entrada (max-w-md) e não ocupa o viewport inteiro
    - done: false
      test: '! grep -nE ''font-family|style=|#[0-9a-fA-F]{3,6}\b'' application/views/conecte/definir_senha.php'
      text: 'A view usa só componentes e classes de token: nenhuma família tipográfica, style inline ou cor fixa'
    - done: false
      test: '! grep -nE ''href=[^>]*mine/s[a]ir'' application/views/conecte/definir_senha.php'
      text: Nenhum link GET para mine/sair na view
    - done: false
      test: php scripts/check-escape.php && php scripts/check-inline-script.php
      text: Guards de escape e de script inline passam sem subir baseline
    - done: false
      test: npm run build && git diff --quiet -- assets/dist
      text: CSS compilado em dia com a view nova
deps: []
files:
    - application/views/conecte/definir_senha.php
    - tests/Unit/DefinirSenhaViewTest.php
    - tests/Unit/AcessibilidadeTest.php
    - assets/dist/app.css
id: TASK-016
linter: php scripts/check-escape.php && php scripts/check-inline-script.php
n45_version: 1.1.0
outcome: ""
persona: frontend
phase: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/DefinirSenhaViewTest.php tests/Unit/AcessibilidadeTest.php
title: Tela Defina sua nova senha da área do cliente
updated_at: "2026-11-03 18:51:53"
---
## Contexto

Cliente cadastrado pelo operador passa a receber uma senha aleatória e fica com `clientes.senha_trocar = 1`; enquanto isso, toda rota autenticada da área do cliente (`Mine`) vai redirecionar para a tela **"Defina sua nova senha"** (`GET|POST /mine/definirSenha`). Esta task cria **só a view** `application/views/conecte/definir_senha.php` com contrato de variáveis fixo; o controller (`Mine::definirSenha`, validação, troca transacional, redirecionamento obrigatório) é de uma task da fase seguinte, que vai renderizar esta view com `$this->load->view('conecte/definir_senha', ['erros' => $erros])`.

A tela é uma **superfície de entrada** (fora da moldura `conecte/template`), igual às telas de recuperação de senha. O modelo mais próximo, para copiar estrutura e classes, é `application/views/conecte/nova_senha.php`:

- abre com `$entrada = ['titulo' => ..., 'secundaria' => [...]]; include APPPATH . 'views/entrada/inicio.php';` e fecha com `include APPPATH . 'views/entrada/fim.php';` (moldura escura com `<html lang="pt-br" class="scheme-dark">`, meta `csrf-token-name`, `app.js`);
- `<main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-4 py-6 lg:py-12">` → card estreito centralizado `<section class="superficie-entrada w-full rounded-xxl border border-hairline-violet bg-night p-6 text-text sm:p-8" aria-labelledby="...">`;
- título `<h1 id="..." class="font-display text-heading-md text-text">`, frase de apoio `<p class="mt-2 text-body-md text-muted">`;
- formulário `<form method="post" action="..." novalidate class="mt-6 flex flex-col gap-4" <?= js_module('formulario/padrao') ?>>` com o campo CSRF `<input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">`;
- campo de senha + botão mostrar/ocultar lado a lado: `<div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">` com `component('input', ...)` e `component('button', ['label' => 'Mostrar senha', 'icon' => 'eye', 'icon_only' => true, 'variant' => 'ghost', 'class' => 'mt-7', 'attrs' => ['data-mostrar-senha' => 'campo-<name>', 'aria-controls' => 'campo-<name>', 'aria-pressed' => 'false']])`. O `formulario/padrao.js` já trata `[data-mostrar-senha]` (alterna `type` e `aria-pressed`); `icon_only` deixa o rótulo só acessível, não visível;
- botão principal `component('button', ['label' => ..., 'type' => 'submit', 'size' => 'lg', 'icon' => 'save', 'class' => 'mt-2 w-full shadow-elev-3', 'attrs' => ['data-rotulo-carregando' => 'Salvando…']])`;
- erro geral em `component('alert', ['message' => $erros['_geral'], 'variant' => 'danger'])`.

Componente `input` (`application/views/components/input.php`): `id` padrão `componenteId($name)` = `campo-<name>`; `help` gera `<p id="<id>-ajuda">` e `error` gera `<p id="<id>-erro">`, ambos ligados por `aria-describedby` (`"<id>-ajuda <id>-erro"`), e `error` marca `aria-invalid="true"`.

Tokens usados (DESIGN, só classes de token, nenhuma cor/fonte fixa): fonte herdada da base (`Rubik`; títulos `font-display` = `Space Grotesk`); `text-text`, `text-muted` (#5b5470), `bg-night`, `border-hairline-violet`; `warning` = #a16207 para o aviso; `danger` = #dc2626 para falha; foco `outline-ring` (#3b82f6) já vem dos componentes.

**"Sair"**: hoje `mine/sair` aceita qualquer método; a fase seguinte o deixa só por POST com CSRF. Por isso o "Sair" desta tela já nasce como **formulário POST** com o campo CSRF e um `component('button')` `variant => 'ghost'` (o módulo `layout/sair`, que renova o token no submit, ainda não existe — a task que cria o módulo o liga em todos os formulários de sair). Nenhum `<a href=".../mine/sair">`.

## Comportamento Esperado

Contrato de variáveis da view: `@var array<string, string> $erros` — por campo (`senha_atual`, `senha`, `senha_confirmacao`) e `_geral` (alert danger para bloqueio por tentativas ou falha técnica). Ausente = `[]`.

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| Render com `erros = []` | `<h1 ...>Defina sua nova senha</h1>`; frase `Por segurança, troque a senha que você recebeu antes de continuar.`; alert **warning** com `role="status"` e o texto `Você entrou com uma senha provisória. Crie a sua para continuar.` no topo do card; form `action="http://mapos.test/index.php/mine/definirSenha"` `method="post"` `novalidate` `data-module="formulario/padrao"`; `name="MAPOS_TOKEN" value="hash-csrf"` |
| Campos | `senha_atual` (rótulo `Senha atual`, `autocomplete="current-password"`, `autofocus`, `required`), `senha` (rótulo `Nova senha`, `autocomplete="new-password"`, `required`, `minlength="8"`, `maxlength="72"`, ajuda visível `Mínimo de 8 caracteres.`), `senha_confirmacao` (rótulo `Repita a nova senha`, `autocomplete="new-password"`, `required`, `maxlength="72"`); os três `type="password"` e **sem** atributo `value` |
| Ajuda da nova senha | `<input ... id="campo-senha" ... aria-describedby="campo-senha-ajuda">` e `<p id="campo-senha-ajuda" ...>Mínimo de 8 caracteres.</p>` |
| `erros = ['senha_atual' => 'A senha atual não confere.']` | `id="campo-senha_atual"` com `aria-invalid="true"` e `aria-describedby="campo-senha_atual-erro"`; texto `A senha atual não confere.` |
| `erros = ['senha' => 'A nova senha precisa de pelo menos 8 caracteres.']` | `aria-describedby="campo-senha-ajuda campo-senha-erro"` |
| `erros = ['_geral' => 'Muitas tentativas. Tente de novo em alguns minutos.']` | alert **danger** (`role="alert"`) com o texto; sem `_geral`, nenhum `role="alert"` na página |
| Mostrar/ocultar | dois botões (`senha_atual` e `senha`) com `data-mostrar-senha="campo-senha_atual"` / `"campo-senha"`, `aria-controls` igual, `aria-pressed="false"` e rótulo acessível `Mostrar senha` **não** renderizado visualmente |
| Ações | botão submit `Salvar senha`; form separado `method="post" action="http://mapos.test/index.php/mine/sair"` com o campo CSRF e botão `Sair` (ghost) |
| Valor com HTML em `erros` (`'<b>x</b>'`) | sai escapado (`&lt;b&gt;x&lt;/b&gt;`) |
| Página | nenhum `<script>` inline; nenhum `href` para `mine/sair`; nenhum `font-family`/`style=` |

## TDD

Arquivo novo `tests/Unit/DefinirSenhaViewTest.php` (`final class DefinirSenhaViewTest extends MaposTestCase`). Renderizar a view com o mesmo carregador falso de `tests/Unit/AreaDoClienteTest.php::renderizar()` (classe anônima com `load`, `security` → `MAPOS_TOKEN`/`hash-csrf` e `config` → `app_name`), e os stubs de `site_url`/`base_url` do topo de `tests/Unit/SenhaInicialViewTest.php`.

```php
public function testNovaSenhaTemAjudaLigadaPorAriaDescribedby(): void
{
    $html = $this->renderizar([]);

    $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha"[^>]*aria-describedby="campo-senha-ajuda"/', $html);
    $this->assertMatchesRegularExpression('#<p id="campo-senha-ajuda"[^>]*>Mínimo de 8 caracteres\.</p>#', $html);
}

public function testErroDaSenhaAtualMarcaOCampo(): void
{
    $html = $this->renderizar(['erros' => ['senha_atual' => 'A senha atual não confere.']]);

    $this->assertMatchesRegularExpression('/<input[^>]*id="campo-senha_atual"[^>]*aria-invalid="true"[^>]*aria-describedby="campo-senha_atual-erro"/', $html);
    $this->assertStringContainsString('A senha atual não confere.', $html);
}
```

Em `tests/Unit/AcessibilidadeTest.php`, acrescentar `testDefinirSenhaLigaAjudaEErroPorAriaDescribedby` renderizando a view com `erros['senha']` e assertando `aria-describedby="campo-senha-ajuda campo-senha-erro"` (critério da Spec: "`AcessibilidadeTest` cobre `aria-describedby` dos campos de `definir_senha.php`"). Cobrir os demais casos da tabela com os nomes fixados nos critérios.

**Refatoração:** Nenhuma.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/views/conecte/definir_senha.php` | Criar | tela "Defina sua nova senha" (superfície de entrada) |
| `tests/Unit/DefinirSenhaViewTest.php` | Criar | testes de render, a11y e escape |
| `tests/Unit/AcessibilidadeTest.php` | Modificar | teste de `aria-describedby` da tela |
| `assets/dist/app.css` | Modificar | `npm run build` depois da view (o Tailwind escaneia `application/views/**/*.php`; o CI falha com `assets/dist` defasado) |

### Detalhamento Técnico

1. Docblock no topo da view no padrão de `nova_senha.php`: o que é a tela, quem a renderiza (`Mine::definirSenha`, POST para ela mesma), e `@var array<string, string> $erros`. `$erros ??= [];`.
2. `$entrada = ['titulo' => 'Defina sua nova senha', 'secundaria' => ['label' => 'Área do cliente', 'href' => site_url('mine'), 'icon' => 'circle-user']];`.
3. Estrutura dentro do `<section class="superficie-entrada ...">` (mesmas classes de `nova_senha.php`), nesta ordem:
   1. `h1` + frase de apoio;
   2. `<div class="mt-4">` com `component('alert', ['message' => 'Você entrou com uma senha provisória. Crie a sua para continuar.', 'variant' => 'warning', 'attrs' => ['role' => 'status']])` — o componente põe `role="alert"` em warning por padrão; aviso persistente que não é erro leva `role="status"` (mesmo recurso de `views/clientes/senha_inicial.php`). O alert `danger` de `_geral` fica com o `role="alert"` padrão;
   3. se `isset($erros['_geral'])`, `<div class="mt-4">` com alert `danger`;
   4. formulário principal (CSRF; `senha_atual` + botão mostrar; `senha` com `help` + botão mostrar; `senha_confirmacao`; submit `Salvar senha`);
   5. `<form method="post" action="<?= e(site_url('mine/sair')) ?>" class="mt-4 flex justify-center">` com o campo CSRF e `component('button', ['label' => 'Sair', 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'log-out', 'class' => 'max-sm:min-h-11'])`.
4. Nenhum `value` nos campos de senha (as senhas sempre voltam limpas); `error` de cada campo vem de `$erros[<name>] ?? null`; `data-msg-vazio` em cada campo (`Informe a senha atual.`, `Crie a nova senha.`, `Repita a nova senha.`) e `data-msg-invalido` na nova (`A nova senha precisa de pelo menos 8 caracteres.`), como em `nova_senha.php`.
5. Todo texto dinâmico por `e()` / pelos componentes; nada de `<script>` inline (o `formulario/padrao` já vem por `js_module`). Rodar `php scripts/check-escape.php` e `php scripts/check-inline-script.php` (as baselines não podem subir) e `npm run build` para atualizar `assets/dist/app.css`.

**Contrato com camadas adjacentes:**

```
Produz para: Mine::definirSenha (fase seguinte)
  - $this->load->view('conecte/definir_senha', ['erros' => array<string, string>])
  - POST mine/definirSenha com: <csrf>, senha_atual, senha, senha_confirmacao
  - erros por campo: 'senha_atual' | 'senha' | 'senha_confirmacao'; '_geral' para bloqueio (Limite_login::MENSAGEM) ou "Não foi possível salvar. Tente novamente."
  - "Sair": POST mine/sair com o campo CSRF
```
