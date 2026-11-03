<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Limite de requisições por IP e por minuto (#2869).
 *
 *     $this->load->library('Limite_requisicoes');
 *     $espera = $this->limite_requisicoes->segundosParaLiberar('api', $ip, 300);
 *     if ($espera > 0) { ...429 com Retry-After: $espera... }
 *
 * Janela fixa de 60 s, contada na tabela api_rate_limit. O escopo separa os
 * contadores (`api`, `webhook`): o mesmo IP não soma entre escopos. A chave é
 * um HMAC-SHA256 do escopo e do IP com a encryption_key como segredo, então o
 * IP nunca fica em claro. Sem a tabela (migration não rodada) ou com limite 0,
 * o limite fica desligado.
 */
class Limite_requisicoes
{
    private const TABELA = 'api_rate_limit';

    private const JANELA_SEGUNDOS = 60;

    /** @var mixed Query Builder do CodeIgniter (CI_DB_query_builder) */
    private $db;

    /** @var callable(): int */
    private $relogio;

    private string $segredo;

    private ?bool $ativo = null;

    /**
     * @param  array{db?: mixed, relogio?: callable, segredo?: string}|null  $opcoes  Para os testes; no framework vem vazio.
     */
    public function __construct($opcoes = null)
    {
        $opcoes = is_array($opcoes) ? $opcoes : [];

        $this->db = $opcoes['db'] ?? get_instance()->db;
        $this->relogio = $opcoes['relogio'] ?? 'time';
        $this->segredo = (string) ($opcoes['segredo'] ?? config_item('encryption_key'));
    }

    /**
     * Conta esta requisição e devolve os segundos até a próxima janela se o
     * limite do minuto foi passado; 0 se está liberada.
     */
    public function segundosParaLiberar(string $escopo, string $ip, int $limitePorMinuto): int
    {
        if ($limitePorMinuto <= 0 || ! $this->ativo()) {
            return 0;
        }

        $agora = (int) ($this->relogio)();
        $janela = intdiv($agora, self::JANELA_SEGUNDOS);
        $chave = hash_hmac('sha256', $escopo . '|' . $ip, $this->segredo);

        if (! $this->incrementar($chave, $janela)) {
            // Corrida com outra requisição que criou a linha agora: soma na existente.
            $this->incrementar($chave, $janela);
        }
        $total = (int) $this->db->select('total')->where(['chave' => $chave, 'janela' => $janela])->get(self::TABELA)->row('total');

        // Limpa janelas antigas de vez em quando, sem custo em toda requisição.
        if (random_int(1, 100) === 1) {
            $this->db->where('janela <', $janela - 5)->delete(self::TABELA);
        }

        return $total > $limitePorMinuto ? self::JANELA_SEGUNDOS - ($agora % self::JANELA_SEGUNDOS) : 0;
    }

    /** UPDATE e, se a linha não existe, INSERT (portável: sem ON DUPLICATE KEY). */
    private function incrementar(string $chave, int $janela): bool
    {
        $this->db->query('UPDATE ' . self::TABELA . ' SET total = total + 1 WHERE chave = ? AND janela = ?', [$chave, $janela]);
        if ($this->db->affected_rows() > 0) {
            return true;
        }

        $debug = $this->db->db_debug;
        $this->db->db_debug = false;
        try {
            return (bool) $this->db->query('INSERT INTO ' . self::TABELA . ' (chave, janela, total) VALUES (?, ?, 1)', [$chave, $janela]);
        } finally {
            $this->db->db_debug = $debug;
        }
    }

    private function ativo(): bool
    {
        if ($this->ativo === null) {
            $this->ativo = $this->db->table_exists(self::TABELA);
        }

        return $this->ativo;
    }
}
