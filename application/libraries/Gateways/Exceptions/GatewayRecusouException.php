<?php

namespace Libraries\Gateways\Exceptions;

/**
 * O provedor recusou a operação (dado inválido, cobrança inexistente).
 * A mensagem é sempre um texto nosso em pt-BR, seguro para exibir; nunca o do provedor.
 */
final class GatewayRecusouException extends \RuntimeException
{
    public function __construct(string $mensagem, ?\Throwable $anterior = null)
    {
        parent::__construct($mensagem, 0, $anterior);
    }
}
