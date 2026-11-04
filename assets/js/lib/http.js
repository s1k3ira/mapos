// fetch padronizado para o painel.
//
// - Manda X-Requested-With, para o CodeIgniter tratar como AJAX: negação de
//   permissão volta como JSON 403 (MY_Controller, #2866) e não como redirect.
// - Em POST e afins, acrescenta o token do CSRF lido do cookie, cujo nome vem
//   das metas csrf-token-name e csrf-cookie-name do layout.
// - Como o token é regenerado a cada requisição, atualiza os campos ocultos
//   dos formulários da página depois da resposta, como o csrf.js legado faz
//   para o jQuery.

import { mostrarToast } from './toast.js';

export const TIMEOUT_PADRAO_MS = 30000;
export const MENSAGEM_SESSAO_EXPIRADA = 'Sua sessão expirou. Entre novamente.';
export const MENSAGEM_SEM_CONEXAO = 'Sem conexão ou a resposta demorou demais. Tente novamente.';
export const MENSAGEM_RESPOSTA_INVALIDA = 'Resposta inesperada do servidor.';

// Pontos de contato com o navegador, trocáveis nos testes.
export const ambiente = {
    avisar: (opcoes) => mostrarToast(opcoes),
    navegar: (url) => globalThis.location.assign(url),
    origem: globalThis.location?.origin ?? null,
    esperaMs: 1500,
};

export class ErroHttp extends Error {
    constructor(status, mensagem, corpo = null) {
        super(mensagem);
        this.name = 'ErroHttp';
        this.status = status;
        this.corpo = corpo;
    }
}

function meta(nome, raiz) {
    return raiz.querySelector(`meta[name="${nome}"]`)?.content ?? null;
}

export function lerCookie(nome, cookies) {
    for (const parte of cookies.split(';')) {
        const [chave, ...resto] = parte.trim().split('=');
        if (chave === nome) {
            return decodeURIComponent(resto.join('='));
        }
    }

    return null;
}

export function tokenCsrf(raiz = document) {
    const campo = meta('csrf-token-name', raiz);
    const cookie = meta('csrf-cookie-name', raiz);

    if (!campo || !cookie) {
        return null;
    }

    const valor = lerCookie(cookie, raiz.cookie ?? '');

    return valor === null ? null : { campo, valor };
}

/**
 * Monta o corpo da requisição a partir de FormData, URLSearchParams ou objeto
 * simples, com o token do CSRF quando houver.
 */
export function corpoComCsrf(dados, csrf) {
    const corpo = dados instanceof FormData || dados instanceof URLSearchParams
        ? dados
        : new URLSearchParams(Object.entries(dados ?? {}).map(([k, v]) => [k, String(v)]));

    if (csrf) {
        corpo.set(csrf.campo, csrf.valor);
    }

    return corpo;
}

function atualizarCamposCsrf(raiz = document) {
    const csrf = tokenCsrf(raiz);
    if (!csrf) return;

    raiz.querySelectorAll(`input[name="${csrf.campo}"]`).forEach((campo) => {
        campo.value = csrf.valor;
    });
}

/**
 * Destino do login quando a resposta indica sessão expirada (401 JSON com
 * redirect, ou redirect seguido pelo fetch até uma página HTML), ou null.
 * Só aceita destino da mesma origem, para não virar redirect aberto.
 */
export function destinoDeSessaoExpirada(resposta, corpo, origem = ambiente.origem) {
    const ehJson = (resposta.headers.get('Content-Type') ?? '').includes('application/json');
    const destino = resposta.status === 401 && corpo?.redirect
        ? corpo.redirect
        : (resposta.redirected && !ehJson ? resposta.url : null);

    if (!destino) {
        return null;
    }

    try {
        return new URL(destino, origem ?? undefined).origin === origem ? destino : null;
    } catch {
        return null;
    }
}

/**
 * Faz a requisição e devolve o JSON (ou o texto, se a resposta não for JSON).
 * Lança ErroHttp para status >= 400, com a mensagem do servidor quando vier,
 * e ErroHttp de status 0 para rede caída ou timeout. Sessão expirada avisa,
 * navega para o login e deixa a promessa pendente.
 */
export async function requisicao(url, { metodo = 'GET', dados = null, headers = {}, signal, timeout = TIMEOUT_PADRAO_MS } = {}) {
    const controle = new AbortController();
    const opcoes = {
        method: metodo,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', ...headers },
        signal: controle.signal,
    };

    if (metodo !== 'GET' && metodo !== 'HEAD') {
        opcoes.body = corpoComCsrf(dados, tokenCsrf());
    }

    let estourou = false;
    const temporizador = setTimeout(() => {
        estourou = true;
        controle.abort();
    }, timeout);
    const abortarPeloChamador = () => controle.abort(signal.reason);
    if (signal?.aborted) {
        abortarPeloChamador();
    }
    signal?.addEventListener('abort', abortarPeloChamador, { once: true });

    let resposta;
    try {
        resposta = await fetch(url, opcoes);
    } catch (erro) {
        if (signal?.aborted && !estourou) {
            throw erro;
        }
        throw new ErroHttp(0, MENSAGEM_SEM_CONEXAO);
    } finally {
        clearTimeout(temporizador);
        signal?.removeEventListener('abort', abortarPeloChamador);
    }

    atualizarCamposCsrf();

    const ehJson = (resposta.headers.get('Content-Type') ?? '').includes('application/json');
    const texto = await resposta.text();
    let corpo = texto;
    if (ehJson) {
        try {
            corpo = JSON.parse(texto);
        } catch {
            throw new ErroHttp(resposta.status, MENSAGEM_RESPOSTA_INVALIDA);
        }
    }

    const destino = destinoDeSessaoExpirada(resposta, corpo);
    if (destino !== null) {
        ambiente.avisar({ variante: 'warning', mensagem: MENSAGEM_SESSAO_EXPIRADA });
        setTimeout(() => ambiente.navegar(destino), ambiente.esperaMs);

        return new Promise(() => {});
    }

    if (!resposta.ok) {
        const mensagem = (ehJson && corpo?.message) || `Erro ${resposta.status}`;
        throw new ErroHttp(resposta.status, mensagem, corpo);
    }

    return corpo;
}

export const get = (url, opcoes = {}) => requisicao(url, { ...opcoes, metodo: 'GET' });
export const post = (url, dados, opcoes = {}) => requisicao(url, { ...opcoes, metodo: 'POST', dados });
