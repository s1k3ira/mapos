<?php

class Servicos_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get($table, $fields, $where = '', $perpage = 0, $start = 0, $one = false, $array = 'array')
    {
        $this->db->select($fields);
        $this->db->from($table);
        $this->db->order_by('idServicos', 'desc');
        $this->db->limit($perpage, $start);
        if ($where) {
            $this->db->like('nome', $where);
            $this->db->or_like('descricao', $where);
        }

        $query = $this->db->get();

        $result = ! $one ? $query->result() : $query->row();

        return $result;
    }

    /**
     * Serviços da listagem (#2841), do mais recente para o mais antigo.
     *
     * @param  array{pesquisa?: string}  $filtros  Saída de listagemFiltros()
     */
    public function listar(array $filtros, int $limite, int $offset): array
    {
        $this->aplicarFiltros($filtros);

        return $this->db->order_by('idServicos', 'desc')->limit($limite, max(0, $offset))->get('servicos')->result();
    }

    public function contar(array $filtros): int
    {
        $this->aplicarFiltros($filtros);

        return (int) $this->db->count_all_results('servicos');
    }

    private function aplicarFiltros(array $filtros): void
    {
        if (($filtros['pesquisa'] ?? '') !== '') {
            $this->db->group_start()->like('nome', $filtros['pesquisa'])->or_like('descricao', $filtros['pesquisa'])->group_end();
        }
    }

    public function getById($id)
    {
        $this->db->where('idServicos', $id);
        $this->db->limit(1);

        return $this->db->get('servicos')->row();
    }

    public function add($table, $data)
    {
        $this->db->insert($table, $data);
        if ($this->db->affected_rows() == '1') {
            return true;
        }

        return false;
    }

    public function edit($table, $data, $fieldID, $ID)
    {
        $this->db->where($fieldID, $ID);
        $this->db->update($table, $data);

        if ($this->db->affected_rows() >= 0) {
            return true;
        }

        return false;
    }

    public function delete($table, $fieldID, $ID)
    {
        $this->db->where($fieldID, $ID);
        $this->db->delete($table);
        if ($this->db->affected_rows() == '1') {
            return true;
        }

        return false;
    }

    /**
     * Apaga o serviço e as linhas dele em servicos_os numa transação: tudo ou nada.
     * Devolve false (e não altera nada) se o registro não existe ou o banco falha.
     */
    public function excluirComDependentes(int $idServico): bool
    {
        $this->db->trans_begin();

        try {
            $this->db->where('servicos_id', $idServico)->delete('servicos_os');
            $this->db->where('idServicos', $idServico)->delete('servicos');
            $apagou = $this->db->affected_rows() === 1;

            if ($apagou && $this->db->trans_status() !== false) {
                $this->db->trans_commit();

                return true;
            }
        } catch (\Throwable $e) {
            // segue para o rollback
        }

        $this->db->trans_rollback();
        log_message('error', sprintf('transacao rollback operacao=excluir_servico id=%d', $idServico));

        return false;
    }

    public function count($table)
    {
        return $this->db->count_all($table);
    }
}
