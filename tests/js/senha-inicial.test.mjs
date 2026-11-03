// Tela da senha inicial do cliente (assets/js/modules/clientes/senha-inicial.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { alternarVisibilidade, copiarSenha } from '../../assets/js/modules/clientes/senha-inicial.js';

const SENHA = 'Ab3dEf6hJk8mNp2q';

function campoFalso(tipo = 'password') {
    return { value: SENHA, type: tipo, selecionado: false, select() { this.selecionado = true; } };
}

function botaoFalso() {
    return { atributos: {}, setAttribute(nome, valor) { this.atributos[nome] = valor; } };
}

test('copiar com sucesso avisa só "Copiado"', async () => {
    const avisos = [];
    const copiados = [];
    const campo = campoFalso();

    const copiou = await copiarSenha(campo, { writeText: async (t) => copiados.push(t) }, (o) => avisos.push(o));

    assert.equal(copiou, true);
    assert.deepEqual(copiados, [SENHA]);
    assert.deepEqual(avisos, [{ variante: 'success', mensagem: 'Copiado' }]);
    assert.equal(campo.selecionado, false);
    assert.equal(campo.type, 'password');
});

test('copiar sem Clipboard seleciona o campo e avisa "Copie manualmente"', async () => {
    const avisos = [];
    const campo = campoFalso();
    const botao = botaoFalso();

    const copiou = await copiarSenha(campo, undefined, (o) => avisos.push(o), botao);

    assert.equal(copiou, false);
    assert.equal(campo.selecionado, true);
    assert.equal(campo.type, 'text');
    assert.equal(botao.atributos['aria-pressed'], 'true');
    assert.deepEqual(avisos, [{ variante: 'warning', mensagem: 'Copie manualmente' }]);
});

test('copiar recusado pelo navegador também cai no aviso manual', async () => {
    const avisos = [];
    const campo = campoFalso('text');

    await copiarSenha(campo, { writeText: async () => { throw new Error('negado'); } }, (o) => avisos.push(o));

    assert.equal(campo.selecionado, true);
    assert.equal(campo.type, 'text');
    assert.deepEqual(avisos, [{ variante: 'warning', mensagem: 'Copie manualmente' }]);
});

test('mostrar alterna a visibilidade e o aria-pressed', () => {
    const campo = campoFalso();
    const botao = botaoFalso();

    alternarVisibilidade(campo, botao);
    assert.equal(campo.type, 'text');
    assert.equal(botao.atributos['aria-pressed'], 'true');

    alternarVisibilidade(campo, botao);
    assert.equal(campo.type, 'password');
    assert.equal(botao.atributos['aria-pressed'], 'false');
});
