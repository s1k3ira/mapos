// Lógica do formulário de login (assets/js/modules/login/formulario.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { ErroHttp } from '../../assets/js/lib/http.js';
import {
    MENSAGEM_PADRAO,
    MENSAGEM_REDE,
    MENSAGEM_SESSAO,
    campoDoFoco,
    desfechoDaFalha,
    interpretarResposta,
    textoSimples,
    validar,
} from '../../assets/js/modules/login/formulario.js';

test('validar: campos vazios e e-mail inválido', () => {
    assert.deepEqual(validar({ email: '', senha: '' }), { email: 'Informe o e-mail.', senha: 'Informe a senha.' });
    assert.deepEqual(validar({ email: '   ', senha: 'x' }), { email: 'Informe o e-mail.' });
    assert.deepEqual(validar({ email: 'admin', senha: 'x' }), { email: 'Informe um e-mail válido.' });
    assert.deepEqual(validar({ email: 'a@b', senha: 'x' }), { email: 'Informe um e-mail válido.' });
});

test('validar: dados válidos não geram erro, e e-mail com espaços nas pontas passa', () => {
    assert.deepEqual(validar({ email: 'admin@admin.com', senha: 'password' }), {});
    assert.deepEqual(validar({ email: '  admin@admin.com ', senha: ' ' }), {});
});

test('validar: usa os textos da view quando informados', () => {
    const textos = { emailVazio: 'E?', emailInvalido: 'E!', senhaVazia: 'S?' };
    assert.deepEqual(validar({ email: '', senha: '' }, textos), { email: 'E?', senha: 'S?' });
    assert.deepEqual(validar({ email: 'x', senha: 'y' }, textos), { email: 'E!' });
});

test('interpretarResposta: sucesso com e sem destino', () => {
    assert.deepEqual(interpretarResposta({ result: true }), { ok: true });
    assert.deepEqual(interpretarResposta({ result: true, destino: '/index.php/login/doisFatores' }), { ok: true, destino: '/index.php/login/doisFatores' });
    assert.deepEqual(interpretarResposta({ result: false, message: 'x' }), { ok: false, mensagem: 'x' });
});

test('textoSimples: remove tags e normaliza espaços', () => {
    assert.equal(textoSimples('<p>a</p>\n<p>b</p>'), 'a b');
    assert.equal(textoSimples(undefined), '');
});

test('desfechoDaFalha: 422 marca os campos sem alert', () => {
    const corpo = {
        result: false,
        message: 'Confira os campos destacados.',
        erros: { email: 'O campo E-mail deve conter um endereço de e-mail válido.', outro: 'x' },
        OUTRO: 'h1',
    };

    assert.deepEqual(desfechoDaFalha(new ErroHttp(422, corpo.message, corpo), 'OUTRO'), {
        mensagem: null,
        erros: { email: 'O campo E-mail deve conter um endereço de e-mail válido.' },
        token: 'h1',
    });
});

test('desfechoDaFalha: 401 e 403 com JSON mostram a message do servidor', () => {
    const c401 = { result: false, message: 'Os dados de acesso estão incorretos.', MAPOS_TOKEN: 'h2' };
    assert.deepEqual(desfechoDaFalha(new ErroHttp(401, c401.message, c401), 'MAPOS_TOKEN'), {
        mensagem: 'Os dados de acesso estão incorretos.',
        erros: null,
        token: 'h2',
    });

    const expirada = 'A conta do usuário está expirada, por favor entre em contato com o administrador do sistema.';
    assert.deepEqual(desfechoDaFalha(new ErroHttp(403, 'x', { result: false, message: expirada }), 'MAPOS_TOKEN'), {
        mensagem: expirada,
        erros: null,
        token: null,
    });

    assert.equal(desfechoDaFalha(new ErroHttp(401, 'x', { message: '<p>Os dados</p>' }), 'MAPOS_TOKEN').mensagem, 'Os dados');
});

test('desfechoDaFalha: 429 mostra o tempo restante em minutos', () => {
    const corpo = { result: false, message: 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.', retry_after: 90 };

    assert.deepEqual(desfechoDaFalha(new ErroHttp(429, corpo.message, corpo), 'MAPOS_TOKEN'), {
        mensagem: 'Muitas tentativas de acesso. Tente de novo em 2 min.',
        erros: null,
        token: null,
    });
    assert.equal(desfechoDaFalha(new ErroHttp(429, 'x', { ...corpo, retry_after: 30 }), 'MAPOS_TOKEN').mensagem, 'Muitas tentativas de acesso. Tente de novo em 1 min.');
    assert.equal(desfechoDaFalha(new ErroHttp(429, 'x', { message: corpo.message }), 'MAPOS_TOKEN').mensagem, corpo.message);
});

test('desfechoDaFalha: 403 sem JSON é o CSRF recusado', () => {
    const erro = new ErroHttp(403, 'Erro 403', '<html>The action you have requested is not allowed.</html>');

    assert.deepEqual(desfechoDaFalha(erro, 'MAPOS_TOKEN'), { mensagem: MENSAGEM_SESSAO, erros: null, token: null });
    assert.equal(desfechoDaFalha(new ErroHttp(500, 'Erro 500', { result: false }), 'MAPOS_TOKEN').mensagem, MENSAGEM_PADRAO);
    assert.equal(desfechoDaFalha(new ErroHttp(502, 'Erro 502', '<html></html>'), 'MAPOS_TOKEN').mensagem, MENSAGEM_PADRAO);
});

test('desfechoDaFalha: rede e timeout mostram MENSAGEM_REDE', () => {
    assert.equal(desfechoDaFalha(new ErroHttp(0, 'Sem conexão ou a resposta demorou demais. Tente novamente.'), 'MAPOS_TOKEN').mensagem, MENSAGEM_REDE);
    assert.equal(desfechoDaFalha(new TypeError('Failed to fetch'), 'MAPOS_TOKEN').mensagem, MENSAGEM_REDE);
});

test('desfechoDaFalha: token vem do campo de nome configurado', () => {
    const corpo = { result: false, message: 'x', MAPOS_TOKEN: 'velho', OUTRO: 'novo' };

    assert.equal(desfechoDaFalha(new ErroHttp(401, 'x', corpo), 'OUTRO').token, 'novo');
    assert.equal(desfechoDaFalha(new ErroHttp(401, 'x', { result: false, message: 'x', MAPOS_TOKEN: 'velho' }), 'OUTRO').token, null);
});

test('campoDoFoco: primeiro campo com erro ou a senha', () => {
    assert.equal(campoDoFoco({ erros: { senha: 'Informe a senha.' } }), 'senha');
    assert.equal(campoDoFoco({ erros: { email: 'x', senha: 'y' } }), 'email');
    assert.equal(campoDoFoco({ mensagem: 'Os dados de acesso estão incorretos.' }), 'senha');
    assert.equal(campoDoFoco({ mensagem: MENSAGEM_REDE }), null);
    assert.equal(campoDoFoco({ mensagem: MENSAGEM_SESSAO }), null);
});

test('com 2FA o servidor manda o destino da tela do código e o login segue para lá', async () => {
    const { interpretarResposta } = await import('../../assets/js/modules/login/formulario.js');

    assert.equal(interpretarResposta({ result: true, destino: '/index.php/login/doisFatores' }).destino, '/index.php/login/doisFatores');
    assert.equal(interpretarResposta({ result: true }).destino, undefined);
    assert.equal(interpretarResposta({ result: false, destino: '/x', message: 'erro' }).destino, undefined, 'Falha não redireciona.');
});
