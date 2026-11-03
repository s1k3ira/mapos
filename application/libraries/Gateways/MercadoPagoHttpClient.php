<?php

namespace Libraries\Gateways;

use MercadoPago\Net\CurlRequest;
use MercadoPago\Net\MPDefaultHttpClient;

/**
 * Cliente HTTP do SDK com tempo máximo de resposta: o SDK só expõe o timeout de
 * conexão, então o CURLOPT_TIMEOUT é acrescentado a cada requisição.
 */
class MercadoPagoHttpClient extends MPDefaultHttpClient
{
    private int $timeout;

    public function __construct(int $timeout)
    {
        $this->timeout = $timeout;

        parent::__construct(new class($timeout) extends CurlRequest {
            private int $segundos;

            public function __construct(int $segundos)
            {
                parent::__construct();
                $this->segundos = $segundos;
            }

            public function setOptionArray(array $value): void
            {
                $value[CURLOPT_CONNECTTIMEOUT_MS] = $this->segundos * 1000;
                $value[CURLOPT_TIMEOUT] = $this->segundos;
                parent::setOptionArray($value);
            }
        });
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
