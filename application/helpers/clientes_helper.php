<?php

/**
 * Regras do formulário de cliente (#2851), fora do controller para serem
 * testadas: o que vai para o banco e o que a tela mostra.
 */
if (! defined('CLIENTE_CAMPOS_TEXTO')) {
    define('CLIENTE_CAMPOS_TEXTO', [
        'nomeCliente', 'contato', 'documento', 'telefone', 'celular', 'email',
        'cep', 'rua', 'numero', 'complemento', 'bairro', 'cidade', 'estado',
    ]);
}

if (! defined('CLIENTE_SENHA_ALFABETO')) {
    // Sem I, O, l, o, 0 e 1: não se confundem quando a senha é lida ou digitada.
    define('CLIENTE_SENHA_ALFABETO', 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789');
}

if (! function_exists('clienteSenhaAleatoria')) {
    /** Senha forte gerada por CSPRNG (random_int). */
    function clienteSenhaAleatoria(int $tamanho = 16): string
    {
        if ($tamanho < 12) {
            throw new InvalidArgumentException('A senha aleatória tem pelo menos 12 caracteres.');
        }

        $maximo = strlen(CLIENTE_SENHA_ALFABETO) - 1;
        $senha = '';
        for ($i = 0; $i < $tamanho; $i++) {
            $senha .= CLIENTE_SENHA_ALFABETO[random_int(0, $maximo)];
        }

        return $senha;
    }
}

if (! function_exists('clienteDadosDoFormulario')) {
    /**
     * Dados da tabela clientes a partir do POST já validado.
     *
     * - pessoa_fisica: CPF tem 11 caracteres; o resto é CNPJ.
     * - senha: a digitada; num cadastro novo sem senha, a $senhaGerada (que o
     *   controller mostra ao operador) ou, sem ela, uma senha aleatória que
     *   ninguém conhece. O documento nunca é a senha. Na edição, sem senha, não
     *   muda. A função não grava senha_trocar: quem marca é o controller.
     * - estado: só uma UF válida.
     *
     * @param  mixed  $post  $this->input->post()
     */
    function clienteDadosDoFormulario($post, bool $novo, ?string $senhaGerada = null): array
    {
        $post = is_array($post) ? $post : [];
        $texto = static fn (string $campo): string => is_string($post[$campo] ?? null) ? trim($post[$campo]) : '';

        $dados = [];
        foreach (CLIENTE_CAMPOS_TEXTO as $campo) {
            $dados[$campo] = $texto($campo);
        }

        $dados['estado'] = array_key_exists(strtoupper($dados['estado']), ufsDoBrasil()) ? strtoupper($dados['estado']) : '';
        $documento = (string) preg_replace('/[^A-Za-z0-9]/', '', $dados['documento']);
        $dados['pessoa_fisica'] = strlen($documento) === 11 ? 1 : 0;
        $dados['fornecedor'] = empty($post['fornecedor']) ? 0 : 1;

        $senha = $texto('senha');
        if ($senha !== '') {
            $dados['senha'] = password_hash($senha, PASSWORD_DEFAULT);
        } elseif ($novo) {
            $dados['senha'] = password_hash(is_string($senhaGerada) && $senhaGerada !== '' ? $senhaGerada : bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        }

        return $dados;
    }
}

if (! function_exists('clienteEnviarSenhaInicial')) {
    /**
     * Envia a senha inicial ao cliente pelo SMTP, direto (send(true)), fora da
     * fila: a senha em claro não pode ficar em email_queue. Nunca loga o HTML,
     * a senha nem o e-mail.
     *
     * @param  object       $email     Mailer (MY_Email)
     * @param  object|null  $emitente  Dados do emitente (email, nome)
     * @param  object       $cliente   idClientes e email
     * @return bool true = o SMTP aceitou
     */
    function clienteEnviarSenhaInicial(object $email, ?object $emitente, object $cliente, string $html): bool
    {
        $destino = trim((string) ($cliente->email ?? ''));
        if ($emitente === null || ! filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $email->clear(true);
            $email->from((string) $emitente->email, (string) $emitente->nome);
            $email->to($destino);
            $email->subject('Sua senha de acesso à área do cliente');
            $email->message($html);
            $enviado = (bool) $email->send(true);
            $erro = 'envio';
        } catch (\Throwable $e) {
            $enviado = false;
            $erro = $e::class;
        }

        if (! $enviado) {
            log_message('error', sprintf('email senha_inicial falha cliente=%d erro=%s', (int) $cliente->idClientes, $erro));
        }

        return $enviado;
    }
}

if (! function_exists('clienteValoresDoFormulario')) {
    /**
     * Valores dos campos na tela: o que foi enviado (quando a tela volta com
     * erro), senão o cliente em edição, senão vazio. A senha nunca volta.
     *
     * @param  mixed        $post     $this->input->post(), ou null fora de um POST
     * @param  object|null  $cliente
     * @return array<string, string|bool>
     */
    function clienteValoresDoFormulario($post, ?object $cliente): array
    {
        $valores = [];
        foreach (CLIENTE_CAMPOS_TEXTO as $campo) {
            if (is_array($post)) {
                $valores[$campo] = is_string($post[$campo] ?? null) ? $post[$campo] : '';
            } else {
                $valores[$campo] = $cliente !== null ? (string) ($cliente->{$campo} ?? '') : '';
            }
        }

        $valores['fornecedor'] = is_array($post) ? ! empty($post['fornecedor']) : (bool) ($cliente->fornecedor ?? false);

        return $valores;
    }
}
