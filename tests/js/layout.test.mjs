// Testes do módulo da moldura do painel (assets/js/modules/layout/shell.js)
// e do modo de cor (assets/js/lib/tema.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { proximoEstado, CONSULTA_DESKTOP, ehAtalhoBusca, grupoDaSidebar } from '../../assets/js/modules/layout/shell.js';
import { ErroHttp } from '../../assets/js/lib/http.js';
import { aplicarModo, salvarModo, CHAVE_MODO } from '../../assets/js/lib/tema.js';

test('no desktop o botão de menu recolhe e expande a sidebar', () => {
    assert.deepEqual(proximoEstado({ desktop: true, recolhida: false, gavetaAberta: false }), { recolhida: true, gavetaAberta: false });
    assert.deepEqual(proximoEstado({ desktop: true, recolhida: true, gavetaAberta: false }), { recolhida: false, gavetaAberta: false });
});

test('no celular o botão de menu abre e fecha a gaveta sem mexer na sidebar recolhida', () => {
    assert.deepEqual(proximoEstado({ desktop: false, recolhida: true, gavetaAberta: false }), { recolhida: true, gavetaAberta: true });
    assert.deepEqual(proximoEstado({ desktop: false, recolhida: false, gavetaAberta: true }), { recolhida: false, gavetaAberta: false });
});

test('a consulta de desktop é o lg do layout.css', () => {
    assert.equal(CONSULTA_DESKTOP, '(min-width: 64rem)');
});

function htmlFalso() {
    const classes = new Set();
    return {
        dataset: {},
        classList: {
            toggle(nome, ligar) {
                if (ligar) classes.add(nome);
                else classes.delete(nome);
            },
            contains: (nome) => classes.has(nome),
        },
    };
}

test('aplicarModo liga .dark no escuro e no sistema com preferência escura', () => {
    const html = htmlFalso();

    aplicarModo(html, 'escuro', false);
    assert.equal(html.dataset.temaModo, 'escuro');
    assert.ok(html.classList.contains('dark'));

    aplicarModo(html, 'claro', true);
    assert.ok(!html.classList.contains('dark'));

    aplicarModo(html, 'sistema', true);
    assert.ok(html.classList.contains('dark'));

    aplicarModo(html, 'sistema', false);
    assert.ok(!html.classList.contains('dark'));
});

test('salvarModo grava a escolha e recusa modo inválido', () => {
    const gravado = {};
    const armazenamento = { setItem: (k, v) => { gravado[k] = v; } };

    salvarModo('escuro', armazenamento);
    assert.equal(gravado[CHAVE_MODO], 'escuro');

    assert.throws(() => salvarModo('roxo', armazenamento), /inválido/);
});

test('salvarModo não quebra com localStorage bloqueado', () => {
    const bloqueado = { setItem: () => { throw new Error('SecurityError'); } };

    assert.doesNotThrow(() => salvarModo('claro', bloqueado));
});

test('Ctrl+K e ⌘K levam à busca; com Shift, Alt ou outra tecla, não', () => {
    assert.equal(ehAtalhoBusca({ ctrlKey: true, key: 'k' }), true);
    assert.equal(ehAtalhoBusca({ metaKey: true, key: 'K' }), true);
    assert.equal(ehAtalhoBusca({ ctrlKey: true, shiftKey: true, key: 'k' }), false);
    assert.equal(ehAtalhoBusca({ ctrlKey: true, altKey: true, key: 'k' }), false);
    assert.equal(ehAtalhoBusca({ ctrlKey: true, key: 'j' }), false);
    assert.equal(ehAtalhoBusca({ key: 'k' }), false);
});

test('a troca de modo é gravada no servidor, e sem URL não faz nada nem quebra', async () => {
    const { gravarNoServidor } = await import('../../assets/js/modules/layout/shell.js');

    const semAviso = () => assert.fail('sem URL não avisa');
    assert.equal(await gravarNoServidor('', 'escuro', semAviso), false);
    assert.equal(await gravarNoServidor(undefined, 'escuro', semAviso), false);

    const original = globalThis.fetch;
    const documentoOriginal = globalThis.document;
    // O post() lê as metas do token CSRF do documento; sem elas, segue sem token.
    globalThis.document = { querySelector: () => null, querySelectorAll: () => [], cookie: '' };
    const chamadas = [];
    globalThis.fetch = async (url, opcoes) => {
        chamadas.push({ url, corpo: String(opcoes.body) });

        return { ok: true, headers: { get: () => 'application/json' }, text: async () => '{"result":true}' };
    };
    try {
        assert.equal(await gravarNoServidor('/index.php/mapos/salvarTemaModo', 'escuro'), true);
        assert.equal(chamadas[0].url, '/index.php/mapos/salvarTemaModo');
        assert.match(chamadas[0].corpo, /modo=escuro/);

        globalThis.fetch = async () => {
            throw new Error('sem rede');
        };
        const avisos = [];
        assert.equal(await gravarNoServidor('/x', 'claro', (o) => avisos.push(o)), false, 'Falha de rede não estoura.');
        assert.deepEqual(avisos, [{ variante: 'danger', mensagem: 'Sem conexão ou a resposta demorou demais. Tente novamente.' }]);
    } finally {
        globalThis.fetch = original;
        globalThis.document = documentoOriginal;
    }
});

test('só o <summary> de dentro da sidebar é um grupo dela (o menu do usuário não é)', () => {
    const resumoDoGrupo = {};
    const resumoDoUsuario = {};
    const sidebar = { contains: (no) => no === resumoDoGrupo };
    const clique = (resumo) => ({ closest: (seletor) => (seletor === 'summary' ? resumo : null) });

    assert.equal(grupoDaSidebar(clique(resumoDoGrupo), sidebar), resumoDoGrupo);
    assert.equal(grupoDaSidebar(clique(resumoDoUsuario), sidebar), null);
    assert.equal(grupoDaSidebar(clique(null), sidebar), null);
    assert.equal(grupoDaSidebar(clique(resumoDoGrupo), null), null);
});

test('gravarNoServidor e gravarCorNoServidor avisam a mensagem do ErroHttp ou a padrão', async () => {
    const { gravarNoServidor, gravarCorNoServidor } = await import('../../assets/js/modules/layout/shell.js');
    const original = { fetch: globalThis.fetch, document: globalThis.document };
    globalThis.document = { querySelector: () => null, querySelectorAll: () => [], cookie: '' };
    const avisos = [];
    const avisar = (o) => avisos.push(o);
    try {
        globalThis.fetch = async () => { throw new ErroHttp(0, 'Sem conexão ou a resposta demorou demais. Tente novamente.'); };
        assert.equal(await gravarNoServidor('/u', 'escuro', avisar), false);

        // Corpo ilegível: o erro não é ErroHttp, então vale a mensagem padrão.
        globalThis.fetch = async () => ({ ok: true, headers: { get: () => 'text/plain' }, text: async () => { throw new Error('x'); } });
        assert.equal(await gravarCorNoServidor('/c', 'azul', avisar), false);
        assert.equal(await gravarCorNoServidor('', 'azul', avisar), false);
    } finally {
        globalThis.fetch = original.fetch;
        globalThis.document = original.document;
    }
    assert.deepEqual(avisos, [
        { variante: 'danger', mensagem: 'Sem conexão ou a resposta demorou demais. Tente novamente.' },
        { variante: 'danger', mensagem: 'Não foi possível salvar a preferência no servidor.' },
    ]);
});
