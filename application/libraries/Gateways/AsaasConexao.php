<?php

namespace Libraries\Gateways;

use CodePhix\Asaas\Connection;
use stdClass;

/**
 * Conexão do SDK do Asaas com tempo máximo de conexão e de resposta: o SDK
 * monta o cURL sem timeout, então as quatro chamadas HTTP são refeitas aqui.
 */
class AsaasConexao extends Connection
{
    private int $timeout;

    public function __construct($token, $status, int $timeout)
    {
        parent::__construct($token, $status);
        $this->timeout = $timeout;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function get($url, $option = false, $custom = false)
    {
        if (empty($this->headers)) {
            $this->headers = ['Content-Type: application/json', 'access_token: ' . $this->api_key];
        }
        $opcoes = [CURLOPT_URL => $this->base_url . '/v3' . $url . $option, CURLOPT_HTTPHEADER => $this->headers];
        if (! empty($custom)) {
            $opcoes[CURLOPT_CUSTOMREQUEST] = $custom;
        }

        return $this->enviar($opcoes);
    }

    public function post($url, $params)
    {
        return $this->enviar([
            CURLOPT_URL => $this->base_url . '/v3' . $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'access_token: ' . $this->api_key],
        ]);
    }

    public function put($url, $params)
    {
        return $this->enviar([
            CURLOPT_URL => $this->base_url . '/v3' . $url,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'access_token: ' . $this->api_key],
        ]);
    }

    public function delete($url)
    {
        return $this->enviar([
            CURLOPT_URL => $this->base_url . '/v3' . $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'access_token: ' . $this->api_key],
        ]);
    }

    protected function opcoesDeTempo(): array
    {
        return [CURLOPT_CONNECTTIMEOUT => $this->timeout, CURLOPT_TIMEOUT => $this->timeout];
    }

    private function enviar(array $opcoes)
    {
        $ch = curl_init();
        curl_setopt_array($ch, $opcoes + $this->opcoesDeTempo() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        ]);
        $resposta = curl_exec($ch);
        curl_close($ch);

        $decodificada = is_string($resposta) ? json_decode($resposta) : null;
        if (empty($decodificada)) {
            $decodificada = new stdClass();
            $decodificada->error = [(object) ['description' => 'Tivemos um problema ao processar a requisição.']];
        }

        return $decodificada;
    }
}
