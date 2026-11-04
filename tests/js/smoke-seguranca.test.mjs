// Smoke de segurança (scripts/smoke-seguranca.sh, RF-60).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';

const ESPERADO = {
    'GET /': 302,
    'GET /index.php/api/v1': 401,
    'POST /index.php/webhooks/mercadopago': 401,
    'POST /index.php/webhooks/asaas': 401,
    'POST /index.php/webhooks/efi/invalido': 401,
    'POST /index.php/login/verificarLogin': 403,
    'GET /index.php/login/sair': 405,
    'GET /index.php/mine/sair': 405,
    'POST /index.php/api/v1/client/refresh': 401,
    'PUT /index.php/api/v1/client/password': 401,
    'POST /index.php/api/v1/client/os': 401,
    'PUT /index.php/api/v1/os/1': 401,
};

async function servidor(respostas) {
    const srv = http.createServer((req, res) => {
        res.statusCode = respostas[`${req.method} ${req.url}`] ?? 404;
        res.end();
    });
    await new Promise((ok) => srv.listen(0, '127.0.0.1', ok));
    return srv;
}

// Assíncrono de propósito: chamada síncrona bloquearia o servidor do próprio teste.
function rodarSmoke(url, espera = '3') {
    return new Promise((ok) => {
        const filho = spawn('bash', ['scripts/smoke-seguranca.sh'], {
            env: { ...process.env, SMOKE_SEM_DOCKER: '1', SMOKE_URL: url, SMOKE_ESPERA_SEGUNDOS: espera },
        });
        let saida = '';
        filho.stdout.on('data', (d) => (saida += d));
        filho.stderr.on('data', (d) => (saida += d));
        filho.on('close', (codigo) => ok({ codigo, saida }));
    });
}

const linhasOk = (saida) => saida.split('\n').filter((l) => l.startsWith('OK')).length;
const urlDe = (srv) => `http://127.0.0.1:${srv.address().port}`;

test('smoke sai 0 com todas as rotas no veredito esperado', async (t) => {
    const srv = await servidor(ESPERADO);
    t.after(() => srv.close());
    const { codigo, saida } = await rodarSmoke(urlDe(srv));
    assert.equal(codigo, 0, saida);
    assert.equal(linhasOk(saida), 12);
    assert.doesNotMatch(saida, /FALHOU/);
});

test('smoke sai 1 quando GET login/sair responde 200', async (t) => {
    const srv = await servidor({ ...ESPERADO, 'GET /index.php/login/sair': 200 });
    t.after(() => srv.close());
    const { codigo, saida } = await rodarSmoke(urlDe(srv));
    assert.equal(codigo, 1, saida);
    assert.match(saida, /FALHOU.*GET \/index\.php\/login\/sair.*200/);
    assert.equal(linhasOk(saida), 11);
});

test('login aceita 422 no lugar de 403', async (t) => {
    const srv = await servidor({ ...ESPERADO, 'POST /index.php/login/verificarLogin': 422 });
    t.after(() => srv.close());
    const { codigo, saida } = await rodarSmoke(urlDe(srv));
    assert.equal(codigo, 0, saida);
    assert.equal(linhasOk(saida), 12);
});

test('login com 500 reprova', async (t) => {
    const srv = await servidor({ ...ESPERADO, 'POST /index.php/login/verificarLogin': 500 });
    t.after(() => srv.close());
    const { codigo, saida } = await rodarSmoke(urlDe(srv));
    assert.equal(codigo, 1, saida);
    assert.match(saida, /FALHOU.*verificarLogin.*500/);
});

test('GET / com 200 passa', async (t) => {
    const srv = await servidor({ ...ESPERADO, 'GET /': 200 });
    t.after(() => srv.close());
    const { codigo, saida } = await rodarSmoke(urlDe(srv));
    assert.equal(codigo, 0, saida);
});

test('smoke sai 2 quando o ambiente não sobe', async () => {
    const srv = await servidor({});
    const url = urlDe(srv);
    await new Promise((ok) => srv.close(ok));
    const { codigo, saida } = await rodarSmoke(url, '2');
    assert.equal(codigo, 2, saida);
    assert.match(saida, /não respondeu/);
    assert.equal(linhasOk(saida), 0);
});
