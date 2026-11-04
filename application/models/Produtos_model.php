<?php

class Produtos_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get($table, $fields, $where = '', $perpage = 0, $start = 0, $one = false, $array = 'array')
    {
        $this->db->select($fields);
        $this->db->from($table);
        $this->db->order_by('idProdutos', 'desc');
        $this->db->limit($perpage, $start);
        if ($where) {
            $this->db->like('codDeBarra', $where);
            $this->db->or_like('descricao', $where);
        }

        $query = $this->db->get();

        $result = ! $one ? $query->result() : $query->row();

        return $result;
    }

    /**
     * Produtos da listagem (#2841), do mais recente para o mais antigo.
     *
     * @param  array{pesquisa?: string, estoque?: 'baixo'}  $filtros  Saída de listagemFiltros()
     */
    public function listar(array $filtros, int $limite, int $offset): array
    {
        $this->aplicarFiltros($filtros);

        return $this->db->order_by('idProdutos', 'desc')->limit($limite, max(0, $offset))->get('produtos')->result();
    }

    public function contar(array $filtros): int
    {
        $this->aplicarFiltros($filtros);

        return (int) $this->db->count_all_results('produtos');
    }

    /**
     * pesquisa procura na descrição e no código de barras; estoque=baixo
     * mostra quem está no mínimo ou abaixo dele (com mínimo definido).
     */
    private function aplicarFiltros(array $filtros): void
    {
        if (($filtros['pesquisa'] ?? '') !== '') {
            $this->db->group_start()->like('descricao', $filtros['pesquisa'])->or_like('codDeBarra', $filtros['pesquisa'])->group_end();
        }

        if (($filtros['estoque'] ?? '') === 'baixo') {
            $this->db->where('estoqueMinimo >', 0)->where('estoque <= estoqueMinimo', null, false);
        }
    }

    public function getById($id)
    {
        $this->db->where('idProdutos', $id);
        $this->db->limit(1);

        return $this->db->get('produtos')->row();
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
     * Apaga o produto e as linhas dele em produtos_os e itens_de_vendas numa transação: tudo ou nada.
     * Devolve false (e não altera nada) se o registro não existe ou o banco falha.
     */
    public function excluirComDependentes(int $idProduto): bool
    {
        $this->db->trans_begin();

        try {
            $this->db->where('produtos_id', $idProduto)->delete('produtos_os');
            $this->db->where('produtos_id', $idProduto)->delete('itens_de_vendas');
            $this->db->where('idProdutos', $idProduto)->delete('produtos');
            $apagou = $this->db->affected_rows() === 1;

            if ($apagou && $this->db->trans_status() !== false) {
                $this->db->trans_commit();

                return true;
            }
        } catch (\Throwable $e) {
            // segue para o rollback
        }

        $this->db->trans_rollback();
        log_message('error', sprintf('transacao rollback operacao=excluir_produto id=%d', $idProduto));

        return false;
    }

    public function count($table)
    {
        return $this->db->count_all($table);
    }

    public function updateEstoque($produto, $quantidade, $operacao = '-')
    {
        $sql = "UPDATE produtos set estoque = estoque $operacao ? WHERE idProdutos = ?";

        return $this->db->query($sql, [$quantidade, $produto]);
    }
}
