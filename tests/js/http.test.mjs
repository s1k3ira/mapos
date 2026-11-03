// Testes do cliente HTTP (assets/js/lib/http.js): sessão expirada, timeout,
// JSON inválido, e do tratador global de rejeições (assets/js/app.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    ambiente, ErroHttp, requisicao, destinoDeSessaoExpirada, TIMEOUT_PADRAO_MS,
    MENSAGEM_SEM_CONEXAO, MENSAGEM_RESPOSTA_INVALIDA, MENSAGEM_SESSAO_EXPIRADA,
} from '../../assets/js/lib/http.js';
import { tratarRejeicao, registrarTratadorGlobal } from '../../assets/js/app.js';

const tick = () => new Promise((r) => setImmediate(r));
const json = (corpo, status = 200) => new Response(typeof corpo === 'string' ? corpo : JSON.stringify(corpo),
    { status, headers: { 'Content-Type': 'application/json' } });

// Troca fetch, document e ambiente por falsos e restaura no fim do teste.
function preparar(t, fetchFalso) {
    const original = { fetch: globalThis.fetch, document: globalThis.document, ambiente: { ...ambiente } };
    const avisos = [];
    const destinos = [];
    globalThis.document = { querySelector: () => null, querySelectorAll: () => [], cookie: '' };
    globalThis.fetch = fetchFalso;
    Object.assign(ambiente, { avisar: (o) => avisos.push(o), navegar: (u) => destinos.push(u), origem: 'http://localhost' });
    t.after(() => {
        globalThis.fetch = original.fetch;
        globalThis.document = original.document;
        Object.assign(ambiente, original.ambiente);
    });

    return { avisos, destinos };
}

test('401 com redirect avisa, navega depois de 1,5 s e não resolve a promessa', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { avisos, destinos } = preparar(t, async () => json({ result: false, message: 'Sessão expirada', redirect: 'http://localhost/index.php/login' }, 401));

    let terminou = false;
    requisicao('/x').then(() => { terminou = true; }, () => { terminou = true; });
    await tick();
    assert.deepEqual(destinos, [], 'ainda não navega antes dos 1500 ms');
    t.mock.timers.tick(1500);
    await tick();

    assert.deepEqual(avisos, [{ variante: 'warning', mensagem: MENSAGEM_SESSAO_EXPIRADA }]);
    assert.deepEqual(destinos, ['http://localhost/index.php/login']);
    assert.equal(terminou, false);
});

test('resposta HTML redirected trata como sessão expirada e navega para resposta.url', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const html = new Response('<html>login</html>', { status: 200, headers: { 'Content-Type': 'text/html' } });
    Object.defineProperty(html, 'redirected', { value: true });
    Object.defineProperty(html, 'url', { value: 'http://localhost/index.php/login' });
    const { avisos, destinos } = preparar(t, async () => html);

    let terminou = false;
    requisicao('/x').then(() => { terminou = true; }, () => { terminou = true; });
    await tick();
    t.mock.timers.tick(1500);
    await tick();

    assert.equal(avisos.length, 1);
    assert.deepEqual(destinos, ['http://localhost/index.php/login']);
    assert.equal(terminou, false);
});

test('401 com redirect de outra origem não navega e rejeita com ErroHttp 401', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { avisos, destinos } = preparar(t, async () => json({ result: false, message: 'Sessão expirada', redirect: 'https://evil.example/login' }, 401));

    await assert.rejects(requisicao('/x'), (erro) => erro instanceof ErroHttp && erro.status === 401 && erro.message === 'Sessão expirada');
    t.mock.timers.tick(5000);
    assert.deepEqual(destinos, []);
    assert.deepEqual(avisos, []);
});

test('401 sem redirect rejeita com a mensagem do corpo', async (t) => {
    preparar(t, async () => json({ result: false, message: 'Token inválido' }, 401));

    await assert.rejects(requisicao('/x'), { name: 'ErroHttp', status: 401, message: 'Token inválido' });
});

test('destinoDeSessaoExpirada é pura: devolve o destino da mesma origem ou null', () => {
    const resp = (status, tipo, extra = {}) => ({ status, headers: { get: () => tipo }, ...extra });

    assert.equal(destinoDeSessaoExpirada(resp(401, 'application/json'), { redirect: 'http://h/login' }, 'http://h'), 'http://h/login');
    assert.equal(destinoDeSessaoExpirada(resp(401, 'application/json'), { redirect: 'http://x/login' }, 'http://h'), null);
    assert.equal(destinoDeSessaoExpirada(resp(401, 'application/json'), { message: 'm' }, 'http://h'), null);
    assert.equal(destinoDeSessaoExpirada(resp(200, 'text/html', { redirected: true, url: 'http://h/login' }), '', 'http://h'), 'http://h/login');
    assert.equal(destinoDeSessaoExpirada(resp(200, 'application/json', { redirected: true, url: 'http://h/login' }), {}, 'http://h'), null);
});

test('timeout: fetch que não responde rejeita com ErroHttp 0 e aborta o signal', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let sinal;
    preparar(t, (url, opcoes) => {
        sinal = opcoes.signal;
        return new Promise((_, rejeitar) => sinal.addEventListener('abort', () => rejeitar(new DOMException('abortado', 'AbortError'))));
    });

    const promessa = requisicao('/x', { timeout: 50 });
    await tick();
    t.mock.timers.tick(50);

    await assert.rejects(promessa, (erro) => erro instanceof ErroHttp && erro.status === 0 && erro.message === MENSAGEM_SEM_CONEXAO);
    assert.equal(sinal.aborted, true);
});

test('rede caída (TypeError do fetch) vira ErroHttp 0 com a mensagem de conexão', async (t) => {
    preparar(t, async () => { throw new TypeError('Failed to fetch'); });

    await assert.rejects(requisicao('/x'), (erro) => erro instanceof ErroHttp && erro.status === 0 && erro.message === MENSAGEM_SEM_CONEXAO);
});

test('abort do chamador mantém o AbortError original', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    preparar(t, (url, opcoes) => new Promise((_, rejeitar) => opcoes.signal.addEventListener('abort', () => rejeitar(opcoes.signal.reason))));
    const chamador = new AbortController();

    const promessa = requisicao('/x', { signal: chamador.signal });
    await tick();
    chamador.abort();

    await assert.rejects(promessa, (erro) => !(erro instanceof ErroHttp) && erro.name === 'AbortError');
});

test('o timeout padrão é de 30 s e o temporizador é limpo ao terminar (sem timeout pendente)', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    preparar(t, async () => json({ ok: true }));
    assert.equal(TIMEOUT_PADRAO_MS, 30000);

    assert.deepEqual(await requisicao('/x'), { ok: true });
    // Se o temporizador seguisse vivo, abortaria depois: o tick não pode ter efeito.
    t.mock.timers.tick(TIMEOUT_PADRAO_MS);
});

test('JSON inválido rejeita com ErroHttp de resposta inesperada, com o status (200 e 500)', async (t) => {
    preparar(t, async () => json('{quebrado', 200));
    await assert.rejects(requisicao('/x'), { name: 'ErroHttp', status: 200, message: MENSAGEM_RESPOSTA_INVALIDA });

    globalThis.fetch = async () => json('{quebrado', 500);
    await assert.rejects(requisicao('/x'), { name: 'ErroHttp', status: 500, message: MENSAGEM_RESPOSTA_INVALIDA });
});

test('403 JSON mantém a mensagem do servidor', async (t) => {
    preparar(t, async () => json({ message: 'Você não tem permissão...' }, 403));

    await assert.rejects(requisicao('/x'), { status: 403, message: 'Você não tem permissão...' });
});

test('tratarRejeicao avisa a mensagem do ErroHttp e registra no console.error', (t) => {
    const erro = t.mock.method(console, 'error', () => {});
    const avisos = [];

    tratarRejeicao({ reason: new ErroHttp(500, 'Erro interno') }, (o) => avisos.push(o));

    assert.deepEqual(avisos, [{ variante: 'danger', mensagem: 'Erro interno' }]);
    assert.equal(erro.mock.callCount(), 1);
});

test('tratarRejeicao usa a mensagem padrão para erro que não é ErroHttp', (t) => {
    t.mock.method(console, 'error', () => {});
    const avisos = [];

    tratarRejeicao({ reason: new TypeError('x') }, (o) => avisos.push(o));

    assert.deepEqual(avisos, [{ variante: 'danger', mensagem: 'Algo deu errado. Tente novamente.' }]);
});

test('registrarTratadorGlobal liga o unhandledrejection uma vez', () => {
    const chamadas = [];

    registrarTratadorGlobal({ addEventListener: (nome, funcao) => chamadas.push([nome, funcao]) });

    assert.equal(chamadas.length, 1);
    assert.equal(chamadas[0][0], 'unhandledrejection');
    assert.equal(typeof chamadas[0][1], 'function');
});
