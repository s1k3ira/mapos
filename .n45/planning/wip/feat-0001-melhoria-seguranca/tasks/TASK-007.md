---
checkpoint: null
complexity: M
created_at: "2026-11-03 16:01:17"
criteria:
    - done: false
      test: node --test --test-name-pattern='401 com redirect' tests/js/http.test.mjs
      text: requisicao() com 401 JSON {redirect} da mesma origem chama o aviso warning 'Sua sessão expirou. Entre novamente.', navega para o redirect após 1500 ms e a promessa não resolve
    - done: false
      test: node --test --test-name-pattern='redirected' tests/js/http.test.mjs
      text: Resposta HTML com redirected=true é tratada como sessão expirada e navega para resposta.url
    - done: false
      test: node --test --test-name-pattern='outra origem' tests/js/http.test.mjs
      text: redirect de outra origem não navega e rejeita com ErroHttp 401
    - done: false
      test: node --test --test-name-pattern='timeout|rede|abort' tests/js/http.test.mjs
      text: fetch que não responde dentro do timeout rejeita com ErroHttp status 0 e a mensagem de conexão; falha de rede idem; abort do chamador mantém o AbortError
    - done: false
      test: grep -nE 'clearTimeout' assets/js/lib/http.js
      text: O temporizador do timeout é limpo ao terminar a requisição
    - done: false
      test: grep -nE 'TIMEOUT_PADRAO_MS *= *30000' assets/js/lib/http.js
      text: Timeout padrão de 30 s
    - done: false
      test: node --test --test-name-pattern='JSON inválido' tests/js/http.test.mjs
      text: Corpo JSON inválido rejeita com ErroHttp 'Resposta inesperada do servidor.' com o status da resposta (200 e 500)
    - done: false
      test: node --test tests/js/layout.test.mjs
      text: gravarNoServidor e gravarCorNoServidor devolvem false e avisam com toast danger a mensagem do ErroHttp (ou a mensagem padrão para outro erro)
    - done: false
      test: '! grep -nE ''catch *\{'' assets/js/modules/layout/shell.js'
      text: shell.js não tem catch vazio
    - done: false
      test: grep -cE 'mostrarToast|avisar\(' assets/js/modules/layout/shell.js | grep -qvE '^[0-3]$'
      text: Cada catch e rejeição tratada de shell.js chama mostrarToast ou o avisar
    - done: false
      test: node --test --test-name-pattern='unhandledrejection|tratarRejeicao' tests/js/http.test.mjs
      text: app.js registra o tratador de unhandledrejection, que mostra toast danger com a mensagem do ErroHttp ou 'Algo deu errado. Tente novamente.' e chama console.error
    - done: false
      test: node --test tests/js/modulos.test.mjs
      text: Os testes de requisicao já existentes continuam passando
    - done: false
      test: grep -nE 'mock\.timers\.enable' tests/js/http.test.mjs
      text: Os testes de timer restauram o relógio falso (mock.timers do node:test por teste, sem setTimeout real de 30 s)
    - done: false
      test: '! grep -nE ''assert\.ok\(true\)|assert\.equal\(1, 1\)'' tests/js/http.test.mjs tests/js/layout.test.mjs'
      text: Nenhum teste da task usa asserção sempre-verde
deps: []
files:
    - assets/js/lib/http.js
    - assets/js/app.js
    - assets/js/modules/layout/shell.js
    - tests/js/http.test.mjs
    - tests/js/layout.test.mjs
id: TASK-007
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
tests: node --test tests/js/http.test.mjs tests/js/layout.test.mjs tests/js/modulos.test.mjs
title: 'Cliente HTTP: sessão expirada, timeout, JSON inválido e erros visíveis'
updated_at: "2026-11-03 16:01:17"
---
## Contexto

O cliente HTTP do painel e da área do cliente é `assets/js/lib/http.js` (`requisicao()`, `get()`, `post()`, classe `ErroHttp(status, mensagem, corpo)`), ES module nativo sem bundler, usado por todos os módulos de `assets/js/modules/`. Hoje ele tem três falhas de tratamento de erro:

1. **Sessão expirada vira sucesso ou erro genérico.** O servidor vai passar a responder AJAX sem sessão com `401` JSON `{result:false, message:"Sessão expirada", redirect:"<url do login>"}` (painel: `site_url('login')`; área do cliente: `site_url('mine')`). Quando o `fetch` segue um redirect do servidor para a página de login, a resposta chega como HTML com `response.redirected === true`. Nenhum dos dois casos é tratado.
2. **Sem timeout.** Uma requisição pendurada deixa o botão em loading para sempre. Rede caída lança `TypeError` cru.
3. **JSON inválido** (`Content-Type: application/json` com corpo quebrado) estoura `SyntaxError` sem mensagem útil.

Além disso, `assets/js/modules/layout/shell.js` engole falhas: `gravarNoServidor()` e `gravarCorNoServidor()` fazem `post(...).then(() => true, () => false)` sem avisar, e os `try { ... } catch { }` de `gravar()` (localStorage) e `lerAtalhos()` (JSON dos atalhos) não mostram nada. E `assets/js/app.js` não tem tratador global de `unhandledrejection`.

Esta task é só JavaScript (nenhuma view, nenhum CSS: não rodar `npm run build`). O contrato do servidor acima é fixo; quem implementa o 401 no PHP é outra task. Toast existente: `mostrarToast({ mensagem, variante, titulo?, duracao? }, doc = document)` de `assets/js/lib/toast.js` (variantes `info`, `success`, `warning`, `danger`; `warning`/`danger` saem com `role="alert"`). Padrões: sem jQuery; testes em `tests/js/*.test.mjs` com `node:test` + `node:assert/strict`, rodados por `npm run test:js` (`node --test tests/js/*.mjs`); os testes atuais de `requisicao()` ficam em `tests/js/modulos.test.mjs` e de `shell.js` em `tests/js/layout.test.mjs` (stub de `globalThis.fetch`/`globalThis.document`).

## Comportamento Esperado

Mensagens (textos exatos):

- `MENSAGEM_SESSAO_EXPIRADA = 'Sua sessão expirou. Entre novamente.'`
- `MENSAGEM_SEM_CONEXAO = 'Sem conexão ou a resposta demorou demais. Tente novamente.'`
- `MENSAGEM_RESPOSTA_INVALIDA = 'Resposta inesperada do servidor.'`

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `requisicao('/x')` com resposta `401` JSON `{result:false, message:'Sessão expirada', redirect:'http://localhost/index.php/login'}` (mesma origem) | `ambiente.avisar({variante:'warning', mensagem:'Sua sessão expirou. Entre novamente.'})` chamado 1 vez; após 1500 ms `ambiente.navegar('http://localhost/index.php/login')`; a promessa **não resolve nem rejeita** |
| Mesmo 401 com `redirect` de **outra origem** (`https://evil.example/login`) | não navega; rejeita com `ErroHttp(401, 'Sessão expirada')` |
| Resposta `200` HTML com `redirected: true` e `url` `http://localhost/index.php/login` | tratada como sessão expirada: aviso warning, navega para `resposta.url` após 1500 ms, promessa pendente |
| `401` JSON **sem** `redirect` (ex.: API com token) | rejeita com `ErroHttp(401, <message do corpo>)` como hoje |
| `fetch` lança `TypeError('Failed to fetch')` | rejeita com `ErroHttp` de `status === 0` e `message === 'Sem conexão ou a resposta demorou demais. Tente novamente.'` |
| `fetch` não responde em `timeout: 50` | rejeita com `ErroHttp(0, 'Sem conexão ou a resposta demorou demais. Tente novamente.')` e o `signal` passado ao `fetch` fica `aborted` |
| Sem `timeout` informado | timeout padrão de `30000` ms (`TIMEOUT_PADRAO_MS`) |
| Chamador aborta pelo próprio `signal` | rejeita com o `AbortError` original (não vira "sem conexão") |
| Resposta `200` com `Content-Type: application/json` e corpo `{quebrado` | rejeita com `ErroHttp(200, 'Resposta inesperada do servidor.')` |
| Resposta `500` com JSON inválido | `ErroHttp(500, 'Resposta inesperada do servidor.')` (nunca "sem conexão") |
| Resposta `403` JSON `{message:'Você não tem permissão...'}` | `ErroHttp(403, 'Você não tem permissão...')` (comportamento atual mantido) |
| Requisição que termina antes do timeout | o temporizador é limpo (o processo do teste não fica pendurado 30 s) |
| `gravarNoServidor('/u', 'escuro', avisar)` com `post` rejeitando `ErroHttp(0, MENSAGEM_SEM_CONEXAO)` | devolve `false` e `avisar({variante:'danger', mensagem:'Sem conexão ou a resposta demorou demais. Tente novamente.'})` |
| `gravarCorNoServidor('/c', 'azul', avisar)` com rejeição `new Error('x')` (não `ErroHttp`) | devolve `false` e `avisar({variante:'danger', mensagem:'Não foi possível salvar a preferência no servidor.'})` |
| `gravarNoServidor('', 'escuro')` | `false`, sem requisição e sem aviso (como hoje) |
| `tratarRejeicao({ reason: new ErroHttp(500, 'Erro interno') }, avisar)` | `avisar({variante:'danger', mensagem:'Erro interno'})` e `console.error` chamado |
| `tratarRejeicao({ reason: new TypeError('x') }, avisar)` | `avisar({variante:'danger', mensagem:'Algo deu errado. Tente novamente.'})` |
| `registrarTratadorGlobal(alvo)` | `alvo.addEventListener('unhandledrejection', <função>)` chamado 1 vez |

## TDD

Testes novos em `tests/js/http.test.mjs` (http.js e o tratador do `app.js`); ajustar `tests/js/layout.test.mjs` para o terceiro parâmetro `avisar` de `gravarNoServidor`/`gravarCorNoServidor`. Usar `t.mock.timers.enable({ apis: ['setTimeout'] })` para os 1500 ms e o timeout, e trocar `ambiente.avisar`/`ambiente.navegar` por funções que gravam as chamadas (restaurar no fim de cada teste). Estilo:

```js
test('401 com redirect avisa, navega depois de 1,5 s e não resolve a promessa', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const avisos = []; const destinos = [];
    const original = { ...ambiente };
    Object.assign(ambiente, { avisar: (o) => avisos.push(o), navegar: (u) => destinos.push(u), origem: 'http://localhost' });
    globalThis.fetch = async () => new Response(JSON.stringify({ result: false, message: 'Sessão expirada', redirect: 'http://localhost/index.php/login' }),
        { status: 401, headers: { 'Content-Type': 'application/json' } });

    let terminou = false;
    requisicao('/x').then(() => { terminou = true; }, () => { terminou = true; });
    await new Promise((r) => setImmediate(r));
    t.mock.timers.tick(1500);
    await new Promise((r) => setImmediate(r));

    assert.deepEqual(avisos, [{ variante: 'warning', mensagem: 'Sua sessão expirou. Entre novamente.' }]);
    assert.deepEqual(destinos, ['http://localhost/index.php/login']);
    assert.equal(terminou, false);
    Object.assign(ambiente, original);
});
```

Cobrir os demais casos da tabela (com o `document` stub de `layout.test.mjs` quando o código lê as metas do CSRF). Controle negativo: rodar o primeiro teste com `destinos` esperado errado e ver ficar vermelho.

**Refatoração:** depois do verde, a decisão "isto é sessão expirada?" fica numa função pura exportada (`destinoDeSessaoExpirada(resposta, corpo, origem): string | null`) em vez de `if`s dentro de `requisicao()`.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `assets/js/lib/http.js` | Modificar | Timeout com `AbortController`, `ErroHttp` status 0, JSON inválido, sessão expirada (401 `redirect` e `redirected`), objeto `ambiente` |
| `assets/js/app.js` | Modificar | `tratarRejeicao()` e `registrarTratadorGlobal()`; registro no carregamento da página |
| `assets/js/modules/layout/shell.js` | Modificar | `gravarNoServidor`/`gravarCorNoServidor` avisam a falha; `catch` de `gravar()` e `lerAtalhos()` com toast |
| `tests/js/http.test.mjs` | Criar | Testes de http.js e do tratador global |
| `tests/js/layout.test.mjs` | Modificar | Passar `avisar` falso aos testes de `gravarNoServidor` e cobrir o aviso |

### Detalhamento Técnico

1. **`ambiente`** (exportado, mutável só para teste): `export const ambiente = { avisar: (opcoes) => mostrarToast(opcoes), navegar: (url) => globalThis.location.assign(url), origem: globalThis.location?.origin ?? null, esperaMs: 1500 };` com `import { mostrarToast } from './toast.js';`. Exportar também `TIMEOUT_PADRAO_MS = 30000` e as três mensagens.
2. **Assinatura**: `requisicao(url, { metodo = 'GET', dados = null, headers = {}, signal, timeout = TIMEOUT_PADRAO_MS } = {})`. O trecho que mais erra é combinar o `signal` do chamador com o do timeout e **limpar o temporizador**:
   ```js
   const controle = new AbortController();
   let estourou = false;
   const temporizador = setTimeout(() => { estourou = true; controle.abort(); }, timeout);
   const abortarPeloChamador = () => controle.abort(signal.reason);
   signal?.addEventListener('abort', abortarPeloChamador, { once: true });

   let resposta;
   try {
       resposta = await fetch(url, { ...opcoes, signal: controle.signal });
   } catch (erro) {
       if (signal?.aborted && !estourou) throw erro;            // cancelado por quem chamou
       throw new ErroHttp(0, MENSAGEM_SEM_CONEXAO);              // rede ou timeout
   } finally {
       clearTimeout(temporizador);
       signal?.removeEventListener('abort', abortarPeloChamador);
   }
   ```
   O timeout cobre só a espera pelo `fetch` (cabeçalhos); a leitura do corpo segue depois.
3. Corpo: com `Content-Type` JSON, `resposta.text()` + `JSON.parse` em `try`; falha → `throw new ErroHttp(resposta.status, MENSAGEM_RESPOSTA_INVALIDA)`. Sem JSON, `text()` como hoje. `atualizarCamposCsrf()` continua logo após o `fetch`.
4. **Sessão expirada** (antes do `!resposta.ok`): `destino = (status === 401 && corpo?.redirect) ? corpo.redirect : (resposta.redirected && !ehJson ? resposta.url : null)`. Só vale se `new URL(destino, ambiente.origem ?? undefined).origin === ambiente.origem` (proteção contra redirect aberto). Válido → `ambiente.avisar({ variante: 'warning', mensagem: MENSAGEM_SESSAO_EXPIRADA })`, `setTimeout(() => ambiente.navegar(destino), ambiente.esperaMs)` e `return new Promise(() => {})` (o chamador não mostra sucesso nem segundo erro). Destino de outra origem → segue o fluxo normal de erro (`ErroHttp(401, corpo.message)`).
5. **`shell.js`**: `gravarNoServidor(url, modo, avisar = avisarFalha)` e `gravarCorNoServidor(url, cor, avisar = avisarFalha)`; na rejeição, `avisar({ variante: 'danger', mensagem: erro instanceof ErroHttp ? erro.message : 'Não foi possível salvar a preferência no servidor.' })` e `return false`. `avisarFalha` = `(opcoes) => mostrarToast(opcoes)` (importar `mostrarToast` de `../../lib/toast.js` e `ErroHttp` de `../../lib/http.js`). Os `catch {` de `gravar()` e `lerAtalhos()` viram `catch (erro)` com `mostrarToast({ variante: 'danger', mensagem: ... })`: `gravar()` → `Não foi possível guardar esta preferência no navegador.` (**uma vez por carregamento de página**, com uma flag de módulo, para não repetir a cada clique); `lerAtalhos()` → `Os atalhos de teclado não puderam ser carregados.` e `return {}`. Nenhum `catch {` vazio fica no arquivo.
6. **`app.js`**: `export function tratarRejeicao(evento, avisar = (o) => mostrarToast(o)) { console.error('[app] erro não tratado', evento?.reason); avisar({ variante: 'danger', mensagem: evento?.reason instanceof ErroHttp ? evento.reason.message : 'Algo deu errado. Tente novamente.' }); }` e `export function registrarTratadorGlobal(alvo = globalThis) { alvo.addEventListener('unhandledrejection', (evento) => tratarRejeicao(evento)); }`, chamado dentro do `if (typeof document !== 'undefined')` já existente. Os imports no topo de `app.js` não podem tocar o DOM no carregamento (o `tests/js/modulos.test.mjs` importa `app.js` no Node).
7. Os testes existentes de `requisicao()` em `tests/js/modulos.test.mjs` não mudam e continuam passando.

**Contrato HTTP consumido** (servidor, implementado em outra task):

```
Qualquer rota AJAX (header X-Requested-With: XMLHttpRequest) sem sessão válida
Response 401  Content-Type: application/json
{ "result": false, "message": "Sessão expirada", "redirect": "http://<host>/index.php/login" }   // área do cliente: ".../index.php/mine"
// redirect é sempre caminho interno fixo do próprio site
```
