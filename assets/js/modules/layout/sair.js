// "Sair" por formulário POST (RF-27). O token do CSRF é regenerado a cada
// requisição; no submit, o campo recebe o valor atual do cookie para o
// logout não ser recusado por um token velho.
import { tokenCsrf } from '../../lib/http.js';

export function renovarCsrf(formulario, raiz = document) {
    const csrf = tokenCsrf(raiz);
    if (!csrf) return false;

    let campo = Array.from(formulario.querySelectorAll('input[type="hidden"]')).find((c) => c.name === csrf.campo);
    if (!campo) {
        campo = (raiz.createElement ? raiz : document).createElement('input');
        campo.type = 'hidden';
        campo.name = csrf.campo;
        formulario.appendChild(campo);
    }
    campo.value = csrf.valor;

    return true;
}

export default function iniciar(formulario) {
    formulario.addEventListener('submit', () => renovarCsrf(formulario));
}
