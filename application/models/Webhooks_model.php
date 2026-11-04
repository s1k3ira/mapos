<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use Libraries\Gateways\BasePaymentGateway;

/**
 * Persistência dos webhooks de pagamento: eventos recebidos (idempotência),
 * cobranças, lançamentos e a trilha de auditoria. A baixa roda em transação.
 */
class Webhooks_model extends CI_Model
{
    public function buscarEvento(string $gateway, string $eventoId): ?object
    {
        $evento = $this->db->where(['gateway' => $gateway, 'evento_id' => $eventoId])->limit(1)->get('webhook_eventos')->row();

        return $evento ?: null;
    }

    public function cobrancaPorCharge(string $chargeId, string $paymentGateway): ?object
    {
        $cobranca = $this->db
            ->select('cobrancas.*, os.lancamento AS lancamento_os, vendas.lancamentos_id AS lancamento_venda')
            ->from('cobrancas')
            ->join('os', 'os.idOs = cobrancas.os_id', 'left')
            ->join('vendas', 'vendas.idVendas = cobrancas.vendas_id', 'left')
            ->where('cobrancas.charge_id', $chargeId)
            ->where('cobrancas.payment_gateway', $paymentGateway)
            ->limit(1)
            ->get()
            ->row();

        return $cobranca ?: null;
    }

    /** Grava o desfecho do evento: INSERT novo ou UPDATE do existente ainda não processado. */
    public function registrarResultado(string $gateway, string $eventoId, string $payloadHash, ?int $cobrancaId, string $resultado): bool
    {
        $agora = date('Y-m-d H:i:s');
        $evento = $this->buscarEvento($gateway, $eventoId);

        if ($evento !== null) {
            return (bool) $this->db->where('id', $evento->id)->update('webhook_eventos', [
                'resultado' => $resultado,
                'cobranca_id' => $cobrancaId,
                'processado_em' => $agora,
            ]);
        }

        $debug = $this->db->db_debug;
        $this->db->db_debug = false;
        try {
            return (bool) $this->db->insert('webhook_eventos', [
                'gateway' => $gateway,
                'evento_id' => $eventoId,
                'cobranca_id' => $cobrancaId,
                'payload_hash' => $payloadHash,
                'resultado' => $resultado,
                'processado_em' => $agora,
                'created_at' => $agora,
            ]);
        } finally {
            $this->db->db_debug = $debug;
        }
    }

    /**
     * Registra o evento, atualiza a cobrança e baixa o lançamento numa transação só.
     *
     * @return string 'aplicado' | 'sem_efeito' | 'repetido' | 'falha'
     */
    public function aplicarPagamento(string $gateway, string $eventoId, string $payloadHash, object $cobranca, string $novoStatus, bool $pago): string
    {
        $this->db->trans_begin();

        try {
            $evento = $this->buscarEvento($gateway, $eventoId);
            if ($evento !== null && $evento->processado_em !== null) {
                $this->db->trans_rollback();

                return 'repetido';
            }

            $agora = date('Y-m-d H:i:s');
            if ($evento === null && ! $this->inserirEvento($gateway, $eventoId, $payloadHash, $agora)) {
                $this->db->trans_rollback();

                return $this->buscarEvento($gateway, $eventoId) !== null ? 'repetido' : 'falha';
            }

            $mudou = false;
            $jaPago = BasePaymentGateway::statusPago((string) $cobranca->payment_gateway, (string) $cobranca->status);
            if (! ($jaPago && ! $pago) && $novoStatus !== (string) $cobranca->status) {
                $this->db->where('idCobranca', $cobranca->idCobranca)->update('cobrancas', ['status' => $novoStatus]);
                $mudou = true;
            }

            $idLancamento = (int) ($cobranca->lancamento_os ?: $cobranca->lancamento_venda);
            if ($pago && $idLancamento > 0) {
                $this->db
                    ->where('idLancamentos', $idLancamento)
                    ->where('COALESCE(baixado, 0) = 0', null, false)
                    ->update('lancamentos', ['baixado' => 1, 'data_pagamento' => date('Y-m-d')]);
                $mudou = $mudou || $this->db->affected_rows() === 1;
            }

            $resultado = $mudou ? 'aplicado' : 'sem_efeito';
            $this->db->where(['gateway' => $gateway, 'evento_id' => $eventoId])->update('webhook_eventos', [
                'processado_em' => $agora,
                'resultado' => $resultado,
                'cobranca_id' => $cobranca->idCobranca,
            ]);

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                log_message('error', 'transacao rollback operacao=webhook id=' . $cobranca->idCobranca);

                return 'falha';
            }

            $this->db->trans_commit();

            return $resultado;
        } catch (\Throwable $e) {
            $this->db->trans_rollback();
            log_message('error', 'transacao rollback operacao=webhook id=' . $cobranca->idCobranca . ' erro=' . get_class($e));

            return 'falha';
        }
    }

    public function auditar(string $tarefa, string $gateway, string $ip): void
    {
        $this->db->insert('logs', [
            'usuario' => 'webhook:' . $gateway,
            'ip' => $ip,
            'tarefa' => $tarefa,
            'data' => date('Y-m-d'),
            'hora' => date('H:i:s'),
        ]);
    }

    private function inserirEvento(string $gateway, string $eventoId, string $payloadHash, string $agora): bool
    {
        $debug = $this->db->db_debug;
        $this->db->db_debug = false;
        try {
            return (bool) $this->db->insert('webhook_eventos', [
                'gateway' => $gateway,
                'evento_id' => $eventoId,
                'payload_hash' => $payloadHash,
                'created_at' => $agora,
            ]);
        } finally {
            $this->db->db_debug = $debug;
        }
    }
}
