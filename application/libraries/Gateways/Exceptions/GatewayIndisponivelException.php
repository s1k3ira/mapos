<?php

namespace Libraries\Gateways\Exceptions;

/** Falha técnica ao falar com o provedor (timeout, rede, 5xx, credencial). */
final class GatewayIndisponivelException extends \RuntimeException
{
    public const MENSAGEM = 'O provedor de pagamento não respondeu. Tente novamente em instantes.';

    public function __construct(string $mensagem = self::MENSAGEM, ?\Throwable $anterior = null)
    {
        parent::__construct($mensagem, 0, $anterior);
    }
}
