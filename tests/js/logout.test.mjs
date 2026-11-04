// "Sair" por formulário POST (assets/js/modules/layout/sair.js, RF-27).
import { test } from 'node:test';
import assert from 'node:assert/strict';

import iniciar, { renovarCsrf } from '../../assets/js/modules/layout/sair.js';

function raizFalsa(cookie) {
    const metas = { 'csrf-token-name': 'MAPOS_TOKEN', 'csrf-cookie-name': 'MAPOS_COOKIE' };

    return {
        cookie,
        querySelector(seletor) {
            const nome = /meta\[name="([^"]+)"\]/.exec(seletor)?.[1];

            return nome in metas ? { content: metas[nome] } : null;
        },
        createElement() {
            return { type: '', name: '', value: '' };
        },
    };
}

function formularioFalso(campos) {
    const ouvintes = {};

    return {
        campos,
        ouvintes,
        querySelectorAll() {
            return campos;
        },
        appendChild(campo) {
            campos.push(campo);
        },
        addEventListener(tipo, ouvinte) {
            ouvintes[tipo] = ouvinte;
        },
    };
}

test('renovarCsrf copia o valor do cookie para o campo do formulário', () => {
    const campo = { name: 'MAPOS_TOKEN', value: 'velho' };
    const form = formularioFalso([campo]);

    assert.equal(renovarCsrf(form, raizFalsa('MAPOS_COOKIE=novo123')), true);
    assert.equal(campo.value, 'novo123');
});

test('renovarCsrf sem o cookie mantém o campo', () => {
    const campo = { name: 'MAPOS_TOKEN', value: 'velho' };
    const form = formularioFalso([campo]);

    assert.equal(renovarCsrf(form, raizFalsa('')), false);
    assert.equal(campo.value, 'velho');
});

test('renovarCsrf cria o campo quando o formulário não tem', () => {
    const form = formularioFalso([]);

    assert.equal(renovarCsrf(form, raizFalsa('MAPOS_COOKIE=novo123')), true);
    assert.deepEqual(form.campos, [{ type: 'hidden', name: 'MAPOS_TOKEN', value: 'novo123' }]);
});

test('iniciar renova no submit sem cancelar o envio', () => {
    const campo = { name: 'MAPOS_TOKEN', value: 'velho' };
    const form = formularioFalso([campo]);
    const original = globalThis.document;
    globalThis.document = raizFalsa('MAPOS_COOKIE=novo123');

    try {
        iniciar(form);
        assert.equal(campo.value, 'velho');

        let cancelado = false;
        form.ouvintes.submit({ preventDefault: () => (cancelado = true) });

        assert.equal(campo.value, 'novo123');
        assert.equal(cancelado, false);
    } finally {
        globalThis.document = original;
    }
});
