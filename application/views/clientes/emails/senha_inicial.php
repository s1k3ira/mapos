<?php
/**
 * E-mail com a senha inicial da área do cliente.
 *
 * @var object      $cliente   nomeCliente
 * @var object|null $emitente  nome, url_logo
 * @var string      $senha     Senha inicial em claro (nunca vai para log nem fila)
 * @var string      $urlAcesso Link da área do cliente
 */
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
</head>

<body style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #555; font-size: 16px; line-height: 24px;">
    <div style="max-width: 600px; margin: auto; padding: 10px; border: 1px solid #eee;">
        <?php if ($emitente && ! empty($emitente->url_logo)) { ?>
            <img src="<?= e($emitente->url_logo) ?>" style="width:100%; max-width:120px;" alt="">
        <?php } ?>
        <p>Caro(a) <b><?= e($cliente->nomeCliente) ?></b>,</p>
        <p>Seu cadastro foi criado. Esta é a sua senha de acesso à área do cliente:</p>
        <p style="background: #eee; padding: 10px; font-family: monospace; font-size: 20px; text-align: center;"><strong><?= e($senha) ?></strong></p>
        <p><a href="<?= e($urlAcesso) ?>">Acessar a área do cliente</a></p>
        <p>No primeiro acesso você vai definir uma senha só sua.</p>
        <p>Um abraço!<br>Equipe <?= e($emitente ? $emitente->nome : '') ?></p>
    </div>
</body>

</html>
