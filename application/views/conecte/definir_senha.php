<?php
/**
 * Tela "Defina sua nova senha" da área do cliente: o cliente cadastrado pelo
 * operador entra com a senha provisória (clientes.senha_trocar = 1) e só segue
 * depois de criar a sua. Renderizada por Mine::definirSenha, que recebe o POST
 * nesta mesma rota; as senhas sempre voltam limpas.
 *
 * @var array<string, string> $erros  Por campo (senha_atual, senha,
 *                                    senha_confirmacao); _geral para bloqueio
 *                                    por tentativas ou falha técnica
 */
$erros ??= [];
$entrada = [
    'titulo' => 'Defina sua nova senha',
    'secundaria' => ['label' => 'Área do cliente', 'href' => site_url('mine'), 'icon' => 'circle-user'],
];
include APPPATH . 'views/entrada/inicio.php';
?>
    <main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-4 py-6 lg:py-12">
        <section class="superficie-entrada w-full rounded-xxl border border-hairline-violet bg-night p-6 text-text sm:p-8" aria-labelledby="titulo-definir-senha">
            <h1 id="titulo-definir-senha" class="font-display text-heading-md text-text">Defina sua nova senha</h1>
            <p class="mt-2 text-body-md text-muted">Por segurança, troque a senha que você recebeu antes de continuar.</p>

            <div class="mt-4"><?= component('alert', ['message' => 'Você entrou com uma senha provisória. Crie a sua para continuar.', 'variant' => 'warning', 'attrs' => ['role' => 'status']]) ?></div>

            <?php if (isset($erros['_geral'])) { ?>
                <div class="mt-4"><?= component('alert', ['message' => $erros['_geral'], 'variant' => 'danger']) ?></div>
            <?php } ?>

            <form method="post" action="<?= e(site_url('mine/definirSenha')) ?>" novalidate class="mt-6 flex flex-col gap-4" <?= js_module('formulario/padrao') ?>>
                <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">
                    <?= component('input', [
                        'name' => 'senha_atual',
                        'label' => 'Senha atual',
                        'type' => 'password',
                        'required' => true,
                        'autocomplete' => 'current-password',
                        'error' => $erros['senha_atual'] ?? null,
                        'attrs' => ['autofocus' => true, 'data-msg-vazio' => 'Informe a senha atual.'],
                    ]) ?>
                    <?= component('button', ['label' => 'Mostrar senha', 'icon' => 'eye', 'icon_only' => true, 'variant' => 'ghost', 'class' => 'mt-7', 'attrs' => ['data-mostrar-senha' => 'campo-senha_atual', 'aria-controls' => 'campo-senha_atual', 'aria-pressed' => 'false']]) ?>
                </div>
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">
                    <?= component('input', [
                        'name' => 'senha',
                        'label' => 'Nova senha',
                        'type' => 'password',
                        'required' => true,
                        'autocomplete' => 'new-password',
                        'help' => 'Mínimo de 8 caracteres.',
                        'error' => $erros['senha'] ?? null,
                        'attrs' => ['minlength' => 8, 'maxlength' => 72, 'data-msg-vazio' => 'Crie a nova senha.', 'data-msg-invalido' => 'A nova senha precisa de pelo menos 8 caracteres.'],
                    ]) ?>
                    <?= component('button', ['label' => 'Mostrar senha', 'icon' => 'eye', 'icon_only' => true, 'variant' => 'ghost', 'class' => 'mt-7', 'attrs' => ['data-mostrar-senha' => 'campo-senha', 'aria-controls' => 'campo-senha', 'aria-pressed' => 'false']]) ?>
                </div>
                <?= component('input', [
                    'name' => 'senha_confirmacao',
                    'label' => 'Repita a nova senha',
                    'type' => 'password',
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'error' => $erros['senha_confirmacao'] ?? null,
                    'attrs' => ['maxlength' => 72, 'data-msg-vazio' => 'Repita a nova senha.'],
                ]) ?>
                <?= component('button', [
                    'label' => 'Salvar senha',
                    'type' => 'submit',
                    'size' => 'lg',
                    'icon' => 'save',
                    'class' => 'mt-2 w-full shadow-elev-3',
                    'attrs' => ['data-rotulo-carregando' => 'Salvando…'],
                ]) ?>
            </form>

            <form method="post" action="<?= e(site_url('mine/sair')) ?>" class="mt-4 flex justify-center">
                <input type="hidden" name="<?= e($this->security->get_csrf_token_name()) ?>" value="<?= e($this->security->get_csrf_hash()) ?>">
                <?= component('button', ['label' => 'Sair', 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'log-out', 'class' => 'max-sm:min-h-11']) ?>
            </form>
        </section>
    </main>
<?php include APPPATH . 'views/entrada/fim.php'; ?>
