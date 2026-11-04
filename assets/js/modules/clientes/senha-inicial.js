// Tela da senha inicial do cliente (views/clientes/senha_inicial.php): a senha
// aparece uma única vez; aqui só se copia e se alterna a visibilidade do campo.
// Nada é guardado nem impresso: a senha vive só no value do campo.

import { copiar } from '../conecte/copiar.js';
import { mostrarToast } from '../../lib/toast.js';

export function alternarVisibilidade(campo, botao) {
    const mostrar = campo.type === 'password';
    campo.type = mostrar ? 'text' : 'password';
    botao?.setAttribute('aria-pressed', String(mostrar));
}

export async function copiarSenha(campo, area = globalThis.navigator?.clipboard, avisar = (aviso) => mostrarToast(aviso), botao = null) {
    if (await copiar(campo, area)) {
        avisar({ variante: 'success', mensagem: 'Copiado' });
        return true;
    }

    // O fallback de seleção só mostra o texto selecionado em campo visível.
    if (campo.type === 'password') {
        alternarVisibilidade(campo, botao);
    }
    campo.select();
    avisar({ variante: 'warning', mensagem: 'Copie manualmente' });
    return false;
}

export default function iniciar(raiz) {
    const doc = raiz.ownerDocument ?? document;
    const campoDe = (botao, atributo) => doc.getElementById(botao.dataset[atributo]);

    raiz.addEventListener('click', async (evento) => {
        const copia = evento.target.closest?.('[data-copiar]');
        const mostra = evento.target.closest?.('[data-mostrar-senha]');
        const campo = copia ? campoDe(copia, 'copiar') : mostra ? campoDe(mostra, 'mostrarSenha') : null;
        if (!campo) {
            return;
        }

        if (mostra) {
            alternarVisibilidade(campo, mostra);
            return;
        }

        const botaoMostrar = raiz.querySelector(`[data-mostrar-senha="${campo.id}"]`);
        await copiarSenha(campo, globalThis.navigator?.clipboard, (aviso) => mostrarToast(aviso, doc), botaoMostrar);
    });
}
