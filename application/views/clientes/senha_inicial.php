<?php
/**
 * Resposta do cadastro de cliente sem senha informada: a senha inicial gerada,
 * exibida uma única vez (não vai para flashdata nem para o banco).
 *
 * - A senha só aparece no value do campo readonly, nunca em data-*, título ou texto.
 * - O módulo clientes/senha-inicial copia a senha e alterna a visibilidade.
 *
 * @var object $cliente      idClientes, nomeCliente
 * @var string $senhaInicial Senha gerada, 16 caracteres
 * @var bool   $emailEnviado true se o e-mail com a senha saiu
 */
$textoEnvio = $emailEnviado
    ? 'Também enviada por e-mail ao cliente.'
    : 'Não foi possível enviar por e-mail: repasse a senha ao cliente.';

$campoSenha = component('input', [
    'name' => 'senha_inicial',
    'id' => 'senha-inicial',
    'label' => 'Senha inicial',
    'type' => 'password',
    'value' => $senhaInicial,
    'readonly' => true,
    'autocomplete' => 'off',
    'class' => 'font-mono',
]);
$botaoMostrar = component('button', [
    'label' => 'Mostrar senha',
    'icon' => 'eye',
    'icon_only' => true,
    'variant' => 'ghost',
    'attrs' => ['data-mostrar-senha' => 'senha-inicial', 'aria-controls' => 'senha-inicial', 'aria-pressed' => 'false'],
]);
$botaoCopiar = component('button', [
    'label' => 'Copiar senha',
    'icon' => 'copy',
    'variant' => 'outline',
    'attrs' => ['data-copiar' => 'senha-inicial'],
]);
$botaoVer = component('button', [
    'label' => 'Ver cliente',
    'variant' => 'outline',
    'href' => site_url('clientes/visualizar/' . (int) $cliente->idClientes),
]);
$botaoVoltar = component('button', [
    'label' => 'Voltar à lista',
    'variant' => 'ghost',
    'href' => site_url('clientes'),
]);
?>
<div class="flex max-w-4xl flex-col gap-4 pt-2 pb-8" <?= js_module('clientes/senha-inicial') ?>>
    <header>
        <h1 class="font-display text-heading-xl text-text">Cliente cadastrado</h1>
        <p class="text-caption text-muted"><?= e($cliente->nomeCliente) ?></p>
    </header>

    <?= component('card', ['padded' => true, 'body' => [
        component('alert', ['variant' => 'warning', 'message' => 'Senha inicial do cliente: exibida apenas agora', 'attrs' => ['role' => 'status']]),
        new HtmlSeguro('<div class="mt-4 flex items-end gap-2 max-sm:flex-col max-sm:items-stretch">' . $campoSenha . $botaoMostrar . $botaoCopiar . '</div>'),
        new HtmlSeguro('<p class="mt-3 text-body-md text-muted">' . e($textoEnvio) . '</p>'),
        new HtmlSeguro('<div class="mt-5 flex flex-wrap justify-end gap-2">' . $botaoVer . $botaoVoltar . '</div>'),
    ]]) ?>
</div>
