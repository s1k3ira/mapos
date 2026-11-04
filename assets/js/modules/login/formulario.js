// Formulário de login do painel (views/mapos/login.php) e da área do cliente
// (views/conecte/login.php), que respondem no mesmo contrato.
//
// Valida no navegador, posta em login/verificarLogin (ou mine/login) pelo
// post() de lib/http.js e mostra o erro na própria página, sem modal. O
// servidor responde por status HTTP com JSON: 200 {result:true[, destino]};
// 422 com erros por campo; 401/403 com a message; 429 com retry_after. O
// post() rejeita com ErroHttp(status, message, corpo) a partir de 400, e é ele
// que decide o desfecho. O token CSRF novo vem no campo de nome configurado
// (meta csrf-token-name).

import { ErroHttp, post } from '../../lib/http.js';
import { marcarCarregando, mostrarErroDoCampo } from '../../lib/formulario.js';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export const MENSAGEM_PADRAO = 'Os dados de acesso estão incorretos, por favor tente novamente!';
export const MENSAGEM_SESSAO = 'Sua sessão expirou. Recarregue a página e tente de novo.';
export const MENSAGEM_REDE = 'Não foi possível falar com o servidor. Verifique a conexão e tente de novo.';

/**
 * Erros de validação por campo. Mensagens vazias significam campo válido.
 *
 * @param {{email: string, senha: string}} valores
 * @param {{emailVazio?: string, emailInvalido?: string, senhaVazia?: string}} textos
 */
export function validar({ email, senha }, textos = {}) {
    const erros = {};
    const emailLimpo = String(email ?? '').trim();

    if (emailLimpo === '') {
        erros.email = textos.emailVazio ?? 'Informe o e-mail.';
    } else if (!EMAIL.test(emailLimpo)) {
        erros.email = textos.emailInvalido ?? 'Informe um e-mail válido.';
    }

    if (String(senha ?? '') === '') {
        erros.senha = textos.senhaVazia ?? 'Informe a senha.';
    }

    return erros;
}

/**
 * Resposta de sucesso do login (status 200). 2FA manda o destino da tela do
 * código (#2876).
 *
 * @returns {{ok: true, destino?: string}|{ok: false, mensagem: string}}
 */
export function interpretarResposta(dados) {
    if (dados && typeof dados === 'object' && dados.result === true) {
        return typeof dados.destino === 'string' && dados.destino !== '' ? { ok: true, destino: dados.destino } : { ok: true };
    }

    const mensagem = dados && typeof dados === 'object' ? textoSimples(dados.message) : '';

    return { ok: false, mensagem: mensagem || MENSAGEM_PADRAO };
}

export function textoSimples(valor) {
    return String(valor ?? '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/** "Muitas tentativas de acesso. Tente de novo em N min." (N >= 1), ou null sem número. */
export function mensagemDeBloqueio(segundos) {
    const s = Number(segundos);
    if (!Number.isFinite(s) || s <= 0) return null;

    return `Muitas tentativas de acesso. Tente de novo em ${Math.max(1, Math.ceil(s / 60))} min.`;
}

/**
 * Desfecho de uma falha do login (RF-29/RF-53): mensagem do alert OU erros
 * por campo (nunca os dois), e o token CSRF novo do corpo, quando veio.
 *
 * @returns {{mensagem: string|null, erros: Record<string,string>|null, token: string|null}}
 */
export function desfechoDaFalha(erro, nomeCsrf) {
    const semCorpo = (mensagem) => ({ mensagem, erros: null, token: null });

    if (!(erro instanceof ErroHttp) || erro.status === 0) {
        return semCorpo(MENSAGEM_REDE);
    }

    const corpo = erro.corpo;

    // Sem JSON: 403 é o CSRF recusado pelo framework (token vencido).
    if (!corpo || typeof corpo !== 'object') {
        return semCorpo(erro.status === 403 ? MENSAGEM_SESSAO : MENSAGEM_PADRAO);
    }

    const token = nomeCsrf && typeof corpo[nomeCsrf] === 'string' ? corpo[nomeCsrf] : null;
    const doServidor = () => textoSimples(corpo.message) || MENSAGEM_PADRAO;

    if (erro.status === 422 && corpo.erros && typeof corpo.erros === 'object') {
        const erros = {};
        for (const campo of ['email', 'senha']) {
            if (corpo.erros[campo] !== undefined) erros[campo] = textoSimples(corpo.erros[campo]);
        }

        return { mensagem: null, erros, token };
    }

    if (erro.status === 429) {
        return { mensagem: mensagemDeBloqueio(corpo.retry_after) ?? doServidor(), erros: null, token };
    }

    if (erro.status === 401 || erro.status === 403) {
        return { mensagem: doServidor(), erros: null, token };
    }

    return { mensagem: MENSAGEM_PADRAO, erros: null, token };
}

/** 'email' | 'senha' | null: onde pôr o foco depois da falha. */
export function campoDoFoco(desfecho) {
    if (desfecho.erros?.email) return 'email';
    if (desfecho.erros?.senha) return 'senha';
    if (!desfecho.erros && desfecho.mensagem !== MENSAGEM_REDE && desfecho.mensagem !== MENSAGEM_SESSAO) return 'senha';

    return null;
}

export default function iniciar(formulario) {
    const email = formulario.querySelector('[name="email"]');
    const senha = formulario.querySelector('[name="senha"]');
    const botao = formulario.querySelector('[type="submit"]');
    const caixa = formulario.querySelector('[data-login-mensagem]');
    const texto = formulario.querySelector('[data-login-texto]');

    const textos = {
        emailVazio: email?.dataset.msgVazio,
        emailInvalido: email?.dataset.msgInvalido,
        senhaVazia: senha?.dataset.msgVazio,
    };

    function mostrarMensagem(mensagem) {
        if (!caixa || !texto) return;
        texto.textContent = mensagem;
        caixa.hidden = !mensagem;
    }

    const nomeCsrf = document.querySelector('meta[name="csrf-token-name"]')?.content ?? null;

    const carregando = (ativo) => marcarCarregando(botao, ativo);

    [email, senha].forEach((campo) => {
        campo?.addEventListener('input', () => {
            if (campo.getAttribute('aria-invalid') === 'true') {
                mostrarErroDoCampo(campo, validar({ email: email.value, senha: senha.value }, textos)[campo.name]);
            }
        });
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();

        const erros = validar({ email: email.value, senha: senha.value }, textos);
        mostrarErroDoCampo(email, erros.email);
        mostrarErroDoCampo(senha, erros.senha);

        if (erros.email || erros.senha) {
            (erros.email ? email : senha).focus();
            return;
        }

        mostrarMensagem('');
        carregando(true);
        let navegando = false;

        try {
            const resposta = interpretarResposta(await post(formulario.action, new FormData(formulario)));

            if (resposta.ok) {
                navegando = true;
                window.location.assign(resposta.destino ?? formulario.dataset.destino);
                return;
            }

            mostrarMensagem(resposta.mensagem);
        } catch (erro) {
            const desfecho = desfechoDaFalha(erro, nomeCsrf);

            // O post() já atualiza o campo pelo cookie; o token da resposta
            // cobre o caso de o cookie não ser legível.
            const campoCsrf = desfecho.token && nomeCsrf
                ? [...formulario.querySelectorAll('input[type="hidden"]')].find((campo) => campo.name === nomeCsrf)
                : null;
            if (campoCsrf) campoCsrf.value = desfecho.token;

            if (desfecho.erros) {
                mostrarErroDoCampo(email, desfecho.erros.email);
                mostrarErroDoCampo(senha, desfecho.erros.senha);
            } else {
                mostrarMensagem(desfecho.mensagem);
                if (campoDoFoco(desfecho) === 'senha') senha.value = '';
            }

            const alvo = campoDoFoco(desfecho);
            if (alvo === 'email') email.focus();
            else if (alvo === 'senha') senha.focus();
        } finally {
            if (!navegando) carregando(false);
        }
    });
}
