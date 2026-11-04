---
a11y:
    contrast: WCAG 2.1 AA
    focus_visible: 'outline-ring 3px em #3b82f6 (opaco); #93c5fd nas sidebars escuras dos temas'
components:
    - name: button
      path: application/views/components/button.php
      states:
        - default
        - hover
        - pressed
        - disabled
        - loading
        - focus
      variants:
        - primary
        - outline
        - ghost
        - danger
        - inverted
        - ghost-on-dark
        - link
    - name: input
      path: application/views/components/input.php
      states:
        - default
        - focus
        - error
        - disabled
      variants:
        - text
        - search
        - password
    - name: select
      path: application/views/components/select.php
      states:
        - default
        - focus
        - error
        - disabled
    - name: textarea
      path: application/views/components/textarea.php
      states:
        - default
        - focus
        - error
        - disabled
    - name: checkbox
      path: application/views/components/checkbox.php
      states:
        - default
        - focus
        - error
        - disabled
    - name: radio
      path: application/views/components/radio.php
      states:
        - default
        - focus
        - disabled
    - name: switch
      path: application/views/components/switch.php
      states:
        - default
        - focus
        - disabled
    - name: pill-status
      path: application/views/components/pill-status.php
      variants:
        - success
        - warning
        - danger
        - info
        - progress
        - neutral
    - name: badge
      path: application/views/components/badge.php
      variants:
        - neutral
        - accent
    - name: alert
      path: application/views/components/alert.php
      variants:
        - success
        - warning
        - danger
        - info
    - name: toast
      path: application/views/components/toast.php
      variants:
        - success
        - warning
        - danger
        - info
    - name: modal
      path: application/views/components/modal.php
      states:
        - closed
        - open
    - name: modal-confirm
      path: application/views/components/modal-confirm.php
      states:
        - closed
        - open
    - name: card
      path: application/views/components/card.php
    - name: data-table
      path: application/views/components/data-table.php
      states:
        - default
        - empty
        - card-list-mobile
    - name: kpi-card
      path: application/views/components/kpi-card.php
    - name: tabs
      path: application/views/components/tabs.php
      states:
        - default
        - active
        - scrollable
    - name: pagination
      path: application/views/components/pagination.php
    - name: empty-state
      path: application/views/components/empty-state.php
    - name: breadcrumb
      path: application/views/components/breadcrumb.php
    - name: link
      path: application/views/components/link.php
    - name: valor-sensivel
      path: application/views/components/valor-sensivel.php
created_at: "2026-11-03 14:49:05"
foundation:
    idiom: css-vars
    library: null
    sources:
        - assets/src/tokens.css
        - assets/src/app.css
        - assets/src/layout.css
        - DESIGN.md
n45_version: 1.1.0
reset:
    applied: true
    file: assets/src/app.css
    roles:
        h1: page-title
        h2: card-title
shell:
    centered: false
    density: comfortable
    file: application/views/tema/conteudo.php
    max_width: 'sem limite: o conteúdo ocupa a largura ao lado da sidebar (formulários usam max-w-4xl)'
    rhythm: 1rem
    rhythm_carrier: flex flex-col gap-4 pt-2 pb-8 (wrapper da tela dentro de .v5-conteudo)
tokens:
    breakpoints:
        lg: 1024px
        md: 768px
        sm: 640px
        xl: 1280px
    color:
        bg: '#ffffff'
        border: '#e5e7eb'
        danger: '#dc2626'
        dark:
            bg: '#1f1633'
            border: '#362d59'
            input-border: '#756d8a'
            primary-strong: '#f37338'
            primary-tint: '#391f26'
            surface: '#150f23'
            surface-subtle: '#1b1430'
            text: '#ffffff'
        focus: '#3b82f6'
        info: '#0369a1'
        input-border: '#8a849c'
        muted: '#5b5470'
        neutral: '#79628c'
        on-primary: '#1f1633'
        primary: '#f37338'
        primary-hover: '#f47e48'
        primary-pressed: '#db6230'
        primary-strong: '#b34d1d'
        primary-tint: '#feeee7'
        progress: '#6a5fc1'
        success: '#15803d'
        surface: '#ffffff'
        surface-subtle: '#f6f5f9'
        text: '#1f1633'
        warning: '#a16207'
    font:
        display: '"Space Grotesk", "Rubik", system-ui, sans-serif'
        family: '"Rubik", -apple-system, system-ui, "Segoe UI", Helvetica, Arial, sans-serif'
        leading:
            body: "1.5"
            caption: "1.43"
            card-title: "1.25"
            display-hero: "1.2"
            display-large: "1.1"
            page-title: "1.2"
        mono: Monaco, Menlo, "Ubuntu Mono", monospace
        scale:
            body: 1rem
            caption: 0.875rem
            card-title: 1.25rem
            display-hero: 5.5rem
            display-large: 3.75rem
            heading-lg: 1.6875rem
            micro-cap: 0.625rem
            page-title: 1.875rem
            section-heading: 1.5rem
        weights:
            - 400
            - 500
            - 600
            - 700
    radius:
        lg: 10px
        md: 8px
        pill: 9999px
        sm: 6px
        xl: 12px
        xs: 4px
        xxl: 18px
    shadow:
        elev-1: 0 2px 8px 0 rgb(0 0 0 / 8%)
        elev-2: 0 10px 15px -3px rgb(0 0 0 / 10%), 0 4px 6px -4px rgb(0 0 0 / 10%)
        elev-4: 0 0.5rem 1.5rem rgb(0 0 0 / 18%)
        field-focus: inset 0 2px 10px rgb(0 0 0 / 15%)
    space:
        lg: 16px
        md: 12px
        section: 96px
        sm: 8px
        xl: 24px
        xs: 4px
        xxl: 32px
        xxs: 2px
updated_at: "2026-11-03 14:49:05"
---

## Decisões

**Fundação própria, sem biblioteca de componentes:** o Map-OS usa Tailwind CSS 4 com tokens em CSS (`assets/src/tokens.css`) e uma biblioteca de componentes PHP própria (`component()`). O `DESIGN.md` da raiz é a fonte da verdade de valores, contrastes e specs de cada componente; quando o código diverge, o documento vence, e mudar o design começa por ele (com os contrastes WCAG recalculados) e só depois pelo código.

**Uma cor de ação:** o laranja `#f37338` é a marca e o padrão; o usuário pode escolher um tema de cor (Padrão, Azul, Verde, Violeta, Grafite) por `app_tema_cor` e `usuarios.tema_cor`. O rótulo do `button-primary` é sempre `on-primary` (tinta sobre laranja, nunca branco; branco só onde a tabela de temas do `DESIGN.md` manda). Status nunca usa o hex de ação: estado é `pill-status` com a palavra. Um `button-primary` por tela, na topbar por `$this->data['topbar_acao']`.

**Tema e modo:** modo claro, escuro ou sistema (`app_tema_modo`, `usuarios.tema_modo`) pela classe `dark` no `<html>`; o modo escuro troca a tela inteira (paleta violeta noturna), nunca uma faixa. Temas por `data-tema` (`tema_helper.php`). As telas de entrada (logins e área do cliente) ficam sempre escuras, no escopo `.superficie-entrada`. `scripts/contraste-temas.mjs` confere os contrastes e `tests/js/temas-tokens.test.mjs` falha se o `tokens.css` divergir dele.

**Só tokens:** a paleta padrão do Tailwind está desligada (`--color-*: initial`); não existe `bg-white` nem cor, tamanho ou fonte fixos fora de `assets/src/tokens.css`. Cores de tema também só ali, nunca em view ou componente.

**Tipografia:** Rubik para toda a interface e Space Grotesk (`font-display`) para títulos de destaque, servidas localmente de `assets/vendor/fonts` (a CSP não libera origem externa de fonte). Dado (célula, valor, texto digitado) em peso 400; rótulo e nome em 500 (`label-md`); 600 marca o ativo. Botões e eyebrows em caixa alta com 0,2px de espaçamento.

**Ícones e ilustração:** ícones Lucide por sprite SVG (`icon('wrench')` na view, `criarIcone('wrench')` no JS; nome novo entra em `assets/src/icones.json` e roda `npm run build:vendor`). Stickers (`assets/img/stickers`) só em superfície de entrada e destaque escuro, nunca em cards nem em tela operacional, nunca laranja.

**Moldura do painel:** `MY_Controller::layout()` renderiza `views/tema/*` dentro de `.v5-shell` (reset e utilities escopados, `!important`, em `assets/dist/layout.css`); o conteúdo da tela vai em `.v5-conteudo`, fora do shell, com `assets/dist/app.css`. Sidebar de 15,75rem (4,75rem recolhida) vira gaveta abaixo de 1024px; topbar de 4rem.

## Um padrão por problema

| Problema | O padrão |
| -------- | -------- |
| Ação principal da tela | `button-primary` único na topbar por `topbar_acao`; secundárias em `button-outline` ou `button-ghost` |
| Estado de OS, venda ou cobrança | `component('pill-status')` com a palavra do estado; `badge` só para contador |
| Confirmação destrutiva | `modal-confirm` com `button-danger`, preenchido por `data-valor-*` da linha |
| Formulário | `<form novalidate>` com `js_module('formulario/padrao')`, componentes `input`/`select`/`textarea`, erro inline por campo |
| Listagem | `data-table` com filtros na query string, `pagination`, ações de linha como `button-ghost` de ícone, estados vazios distintos |
| Feedback transiente | `toast` por flashdata; mensagem persistente em `alert` |
| Ícone | `icon()` Lucide; nunca Font Awesome ou Boxicons |
| Impressão e PDF | `assets/dist/imprimir.css` (classes `doc-*`, `cupom-*`) e `assets/dist/pdf.css` (classes `rel-*`, só CSS 2.1) |

## Composição de tela

Toda rota do painel renderiza pelo shell; a tela é um wrapper `flex flex-col gap-4 pt-2 pb-8` (formulários com `max-w-4xl`) com o título `font-display text-heading-xl` em `h1`, e cada bloco dentro de um `card` ou de uma seção com `h2` em `text-heading-sm`; nunca campos e blocos soltos em sequência. Nenhum markup ad-hoc onde já existe componente no inventário, e UI que repetir em duas telas ou mais vira componente antes da segunda tela. Telas novas seguem `Clientes::gerenciar()` (listagem) e `Clientes::formulario()` (formulário); a página de componentes `/index.php/componentes` mostra o catálogo, só em `development`.

Abaixo de 640px a `data-table` vira lista de cards (número e status primeiro, valor e ações por último); campos e botões têm no mínimo 44px de altura no celular.

## A11y

Foco visível por `outline-ring` de 3px em todo interativo; indicador fino laranja (item ativo da sidebar, aba, paginação) sempre com segundo sinal (peso 600 e fundo `primary-tint`). Campos com `aria-invalid` e `aria-describedby` para o erro; `AcessibilidadeTest` cobre o contrato. Estado nunca só por cor.
