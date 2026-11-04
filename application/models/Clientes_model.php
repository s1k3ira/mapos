<?php

require_once __DIR__ . '/../libraries/Refresh_token.php';

class Clientes_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Troca a senha do cliente e derruba sessão web, access tokens e refresh
     * tokens dele, tudo numa transação. False = nada mudou.
     */
    public function registrarTrocaDeSenha(int $idCliente, string $hash, bool $exigirTroca): bool
    {
        $this->db->trans_begin();

        try {
            $this->db->set('senha', $hash)
                ->set('senha_trocar', $exigirTroca ? 1 : 0)
                ->set('sessao_versao', 'sessao_versao + 1', false)
                ->set('token_versao', 'token_versao + 1', false)
                ->where('idClientes', $idCliente)
                ->update('clientes');
            $alterou = $this->db->affected_rows() === 1;

            if ($alterou) {
                $this->refreshTokens()->revogarDoUsuario($idCliente, 'cliente');
            }

            if ($alterou && $this->db->trans_status() !== false) {
                $this->db->trans_commit();

                return true;
            }
        } catch (\Throwable $e) {
            // Com db_debug ligado o CI já desfez a transação antes de lançar; o rollback abaixo vira no-op.
        }

        $this->db->trans_rollback();
        log_message('error', sprintf('transacao rollback operacao=troca_senha_cliente id=%d', $idCliente));

        return false;
    }

    protected function refreshTokens(): Refresh_token
    {
        return new Refresh_token(['db' => $this->db]);
    }

    public function get($table, $fields, $where = '', $perpage = 0, $start = 0, $one = false, $array = 'array')
    {
        $this->db->select($fields);
        $this->db->from($table);
        $this->db->order_by('idClientes', 'desc');
        $this->db->limit($perpage, $start);
        if ($where) {
            $this->db->like('nomeCliente', $where);
            $this->db->or_like('documento', $where);
            $this->db->or_like('email', $where);
            $this->db->or_like('telefone', $where);
        }

        $query = $this->db->get();

        $result = ! $one ? $query->result() : $query->row();

        return $result;
    }

    /**
     * Clientes da listagem (#2852), do mais recente para o mais antigo.
     *
     * @param  array{pesquisa?: string, tipo?: 'cliente'|'fornecedor'}  $filtros  Saída de listagemFiltros()
     */
    public function listar(array $filtros, int $limite, int $offset): array
    {
        $this->aplicarFiltros($filtros);

        return $this->db
            ->order_by('idClientes', 'desc')
            ->limit($limite, max(0, $offset))
            ->get('clientes')
            ->result();
    }

    /**
     * Total da listagem com os mesmos filtros de listar(): a paginação conta
     * só o que a busca encontra.
     */
    public function contar(array $filtros): int
    {
        $this->aplicarFiltros($filtros);

        return (int) $this->db->count_all_results('clientes');
    }

    /**
     * pesquisa procura em nome, documento, e-mail, telefone e celular (o OR
     * fica entre parênteses, para não anular o filtro de tipo); tipo separa
     * clientes de fornecedores.
     */
    private function aplicarFiltros(array $filtros): void
    {
        if (($filtros['pesquisa'] ?? '') !== '') {
            $this->db->group_start()
                ->like('nomeCliente', $filtros['pesquisa'])
                ->or_like('documento', $filtros['pesquisa'])
                ->or_like('email', $filtros['pesquisa'])
                ->or_like('telefone', $filtros['pesquisa'])
                ->or_like('celular', $filtros['pesquisa'])
                ->group_end();
        }

        if (($filtros['tipo'] ?? '') !== '') {
            $this->db->where('fornecedor', $filtros['tipo'] === 'fornecedor' ? 1 : 0);
        }
    }

    public function getById($id)
    {
        $this->db->where('idClientes', $id);
        $this->db->limit(1);

        return $this->db->get('clientes')->row();
    }

    public function add($table, $data)
    {
        $this->db->insert($table, $data);
        if ($this->db->affected_rows() == '1') {
            return $this->db->insert_id($table);
        }

        return false;
    }

    public function edit($table, $data, $fieldID, $ID)
    {
        $this->db->where($fieldID, $ID);
        $this->db->update($table, $data);

        if ($this->db->affected_rows() >= 0) {
            // Senha trocada: os access tokens da API do cliente deixam de valer.
            if ($table === 'clientes' && $fieldID === 'idClientes' && isset($data['senha'])) {
                $this->load->library('Authorization_Token');
                $this->authorization_token->incrementarVersao('clientes', 'idClientes', (int) $ID);
            }

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

    public function count($table)
    {
        return $this->db->count_all($table);
    }

    /** OS do cliente, das mais recentes para as mais antigas. */
    public function osDoCliente(int $id, int $limite, int $offset = 0): array
    {
        return $this->db->where('clientes_id', $id)->order_by('idOs', 'desc')->limit($limite, $offset)->get('os')->result();
    }

    /** Vendas do cliente, das mais recentes para as mais antigas. */
    public function vendasDoCliente(int $id, int $limite, int $offset = 0): array
    {
        return $this->db->where('clientes_id', $id)->order_by('idVendas', 'desc')->limit($limite, $offset)->get('vendas')->result();
    }

    public function contarOsDoCliente(int $id): int
    {
        return (int) $this->db->where('clientes_id', $id)->count_all_results('os');
    }

    public function contarVendasDoCliente(int $id): int
    {
        return (int) $this->db->where('clientes_id', $id)->count_all_results('vendas');
    }

    public function getOsByCliente($id)
    {
        $this->db->where('clientes_id', $id);
        $this->db->order_by('idOs', 'desc');
        $this->db->limit(10);

        return $this->db->get('os')->result();
    }

    /**
     * Retorna todas as OS vinculados ao cliente
     *
     * @param  int  $id
     * @return array
     */
    public function getAllOsByClient($id)
    {
        $this->db->where('clientes_id', $id);

        return $this->db->get('os')->result();
    }

    /**
     * Marca senha_trocar = 1 nos clientes cuja senha ainda é o próprio
     * CPF/CNPJ sem pontuação (senha padrão do cadastro antigo). Devolve a
     * quantidade marcada ou false se o banco falhar. Custa um bcrypt por
     * cliente com documento e senha: demora em bases grandes.
     */
    public function marcarSenhasLegadas(int $lote = 500): int|false
    {
        $ultimoId = 0;
        $total = 0;

        while (true) {
            $consulta = $this->db->select('idClientes, documento, senha')
                ->where('idClientes >', $ultimoId)
                ->where('senha_trocar', 0)
                ->order_by('idClientes')
                ->limit($lote)
                ->get('clientes');

            if ($consulta === false) {
                log_message('error', 'cliente marcar_senhas_legadas falha');

                return false;
            }

            $linhas = $consulta->result();
            if ($linhas === []) {
                return $total;
            }

            $ids = [];
            foreach ($linhas as $linha) {
                $ultimoId = (int) $linha->idClientes;
                $documento = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) $linha->documento);
                if ($documento === '' || (string) $linha->senha === '') {
                    continue;
                }
                if (password_verify($documento, (string) $linha->senha)) {
                    $ids[] = $ultimoId;
                }
            }

            if ($ids === []) {
                continue;
            }

            $atualizou = $this->db->where_in('idClientes', $ids)->where('senha_trocar', 0)->update('clientes', ['senha_trocar' => 1]);
            if ($atualizou === false) {
                log_message('error', 'cliente marcar_senhas_legadas falha');

                return false;
            }
            $total += (int) $this->db->affected_rows();
        }
    }

    /**
     * Exclui o cliente e o que é dele (OS, itens, vendas) numa transação e
     * revoga os refresh tokens dele. false = nada mudou (inclusive cliente
     * inexistente). Anexos, equipamentos, cobranças e lançamentos não são
     * apagados aqui: no MySQL as FKs fazem o DELETE falhar e tudo é desfeito.
     */
    public function excluirComDependentes(int $idCliente): bool
    {
        $this->db->trans_begin();

        try {
            $ids = array_column($this->db->select('idOs')->where('clientes_id', $idCliente)->get('os')->result_array(), 'idOs');
            if ($ids !== []) {
                foreach (['servicos_os', 'produtos_os', 'anotacoes_os', 'os_historico'] as $tabela) {
                    if (in_array($tabela, ['anotacoes_os', 'os_historico'], true) && ! $this->db->table_exists($tabela)) {
                        continue;
                    }
                    $this->db->where_in('os_id', $ids)->delete($tabela);
                }
                $this->db->where('clientes_id', $idCliente)->delete('os');
            }

            $vendas = array_column($this->db->select('idVendas')->where('clientes_id', $idCliente)->get('vendas')->result_array(), 'idVendas');
            if ($vendas !== []) {
                $this->db->where_in('vendas_id', $vendas)->delete('itens_de_vendas');
                $this->db->where('clientes_id', $idCliente)->delete('vendas');
            }

            $this->db->where('idClientes', $idCliente)->delete('clientes');
            $apagou = $this->db->affected_rows() === 1;

            if ($apagou) {
                $this->refreshTokens()->revogarDoUsuario($idCliente, 'cliente');
            }

            if ($apagou && $this->db->trans_status() !== false) {
                $this->db->trans_commit();

                return true;
            }
        } catch (\Throwable $e) {
            // segue para o rollback
        }

        $this->db->trans_rollback();
        log_message('error', sprintf('transacao rollback operacao=excluir_cliente id=%d', $idCliente));

        return false;
    }

    /**
     * Remover todas as OS por cliente
     *
     * @param  array  $os
     * @return bool
     */
    public function removeClientOs($os)
    {
        foreach ($os as $o) {
            foreach (['servicos_os' => 'os_id', 'produtos_os' => 'os_id', 'os' => 'idOs'] as $tabela => $campo) {
                if ($this->db->where($campo, $o->idOs)->delete($tabela) === false) {
                    log_message('error', sprintf('cliente remover_os falha os=%d', (int) $o->idOs));

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Retorna todas as Vendas vinculados ao cliente
     *
     * @param  int  $id
     * @return array
     */
    public function getAllVendasByClient($id)
    {
        $this->db->where('clientes_id', $id);

        return $this->db->get('vendas')->result();
    }

    /**
     * Remover todas as Vendas por cliente
     *
     * @param  array  $vendas
     * @return bool
     */
    public function removeClientVendas($vendas)
    {
        foreach ($vendas as $v) {
            foreach (['itens_de_vendas' => 'vendas_id', 'vendas' => 'idVendas'] as $tabela => $campo) {
                if ($this->db->where($campo, $v->idVendas)->delete($tabela) === false) {
                    log_message('error', sprintf('cliente remover_venda falha venda=%d', (int) $v->idVendas));

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Verifica se o e-mail já existe na tabela de clientes
     *
     * @param  string  $email
     * @param  int     $id (opcional, para excluir o próprio cliente na edição)
     * @return bool
     */
    public function emailExists($email, $id = null)
    {
        $this->db->where('email', $email);
        
        if ($id !== null) {
            $this->db->where('idClientes !=', $id);
        }
        
        $query = $this->db->get('clientes');
        
        return $query->num_rows() > 0;
    }
}
