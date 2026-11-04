<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * Retenção de dados que crescem sem limite: webhook_eventos (90 dias),
 * api_refresh_tokens (30 dias após expirar ou ser revogado) e email_queue
 * (sent 7 dias, failed 30 dias). Hard delete em lotes por tabela; as datas
 * são calculadas no PHP para o SQL ser portável.
 */
class Retencao
{
    public const LOTE_PADRAO = 500;

    private const DIA = 86400;

    /** @var mixed Query Builder do CodeIgniter (CI_DB_query_builder) */
    private $db;

    /** @var callable(): int */
    private $relogio;

    /**
     * @param  array{db?: mixed, relogio?: callable}|null  $opcoes  Para os testes; no framework vem vazio.
     */
    public function __construct($opcoes = null)
    {
        $opcoes = is_array($opcoes) ? $opcoes : [];

        $this->db = $opcoes['db'] ?? get_instance()->db;
        $this->relogio = $opcoes['relogio'] ?? 'time';
    }

    /**
     * @return array{webhook_eventos: int, api_refresh_tokens: int, email_queue: int}
     */
    public function limpar(int $lote = self::LOTE_PADRAO): array
    {
        $lote = max(1, $lote);
        $agora = (int) ($this->relogio)();
        $limite = fn (int $dias): string => date('Y-m-d H:i:s', $agora - $dias * self::DIA);

        $limite7 = $limite(7);
        $limite30 = $limite(30);
        $limite90 = $limite(90);

        return [
            'webhook_eventos' => $this->apagarEmLote('webhook_eventos', function ($db) use ($limite90) {
                $db->where('created_at <', $limite90);
            }, $lote),
            'api_refresh_tokens' => $this->apagarEmLote('api_refresh_tokens', function ($db) use ($limite30) {
                $db->group_start()->where('expira_em <', $limite30)->or_where('revogado_em <', $limite30)->group_end();
            }, $lote),
            'email_queue' => $this->apagarEmLote('email_queue', function ($db) use ($limite7, $limite30) {
                $db->group_start()
                    ->group_start()->where('status', 'sent')->where('date <', $limite7)->group_end()
                    ->or_group_start()->where('status', 'failed')->where('date <', $limite30)->group_end()
                    ->group_end();
            }, $lote),
        ];
    }

    private function apagarEmLote(string $tabela, callable $filtro, int $lote): int
    {
        if (! $this->db->table_exists($tabela)) {
            return 0;
        }

        $filtro($this->db);
        $ids = array_column($this->db->select('id')->order_by('id')->limit($lote)->get($tabela)->result_array(), 'id');
        if ($ids === []) {
            return 0;
        }

        $this->db->where_in('id', $ids)->delete($tabela);

        return (int) $this->db->affected_rows();
    }
}
