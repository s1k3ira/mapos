<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Conecte_model extends CI_Model
{
    /**
     * Colunas de `usuarios` que podem ser expostas na área do cliente.
     *
     * As consultas desta model fazem join com `usuarios`; um `SELECT *` traria
     * junto o hash da senha do técnico para dentro da resposta ao cliente.
     */
    private const USUARIO_CAMPOS_PUBLICOS = 'usuarios.idUsuarios, usuarios.nome, usuarios.email, usuarios.telefone, usuarios.celular';

    public const LIMITE_OS_MENSAGEM = 'Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência.';

    /** Aberturas de OS pelo próprio cliente desde 00:00 de hoje (fuso APP_TIMEZONE). */
    public function aberturasDeOsHoje(int $idCliente): int
    {
        if (! $this->db->table_exists('os_historico')) {
            return 0;
        }

        $linha = $this->db->query(
            'SELECT COUNT(*) AS total FROM os_historico h JOIN os ON os.idOs = h.os_id'
            . ' WHERE os.clientes_id = ? AND h.usuarios_id IS NULL AND h.status_anterior IS NULL'
            . " AND h.status_novo = 'Aberto' AND h.data_hora >= ?",
            [$idCliente, date('Y-m-d 00:00:00')]
        );

        return $linha ? (int) ($linha->row()->total ?? 0) : 0;
    }

    /** Trava a linha do cliente até o fim da transação: serializa aberturas simultâneas. */
    protected function travarCliente(int $idCliente): bool
    {
        $consulta = $this->db->query('SELECT idClientes FROM clientes WHERE idClientes = ? FOR UPDATE', [$idCliente]);

        return $consulta !== false && $consulta->row() !== null;
    }

    /**
     * Abre a OS do cliente respeitando o limite diário, de forma atômica: lock
     * da linha do cliente, contagem, OS e histórico na mesma transação.
     *
     * @param  array<string, mixed>  $dados
     * @return array{ok: true, id: int}|array{ok: false, motivo: string}
     */
    public function abrirOsDoCliente(int $idCliente, array $dados, int $limite): array
    {
        $this->db->trans_begin();

        try {
            if (! $this->travarCliente($idCliente)) {
                return $this->desfazerAberturaDeOs($idCliente);
            }

            if ($limite > 0 && $this->aberturasDeOsHoje($idCliente) >= $limite) {
                $this->db->trans_rollback();

                return ['ok' => false, 'motivo' => 'limite'];
            }

            $dados['clientes_id'] = $idCliente;
            if (! $this->db->insert('os', $dados)) {
                return $this->desfazerAberturaDeOs($idCliente);
            }
            $id = (int) $this->db->insert_id();

            if ($this->db->table_exists('os_historico')) {
                $historico = $this->db->insert('os_historico', [
                    'os_id' => $id,
                    'status_anterior' => null,
                    'status_novo' => 'Aberto',
                    'usuarios_id' => null,
                    'data_hora' => date('Y-m-d H:i:s'),
                ]);
                if (! $historico) {
                    return $this->desfazerAberturaDeOs($idCliente);
                }
            }

            if ($this->db->trans_status() === false) {
                return $this->desfazerAberturaDeOs($idCliente);
            }

            $this->db->trans_commit();

            return ['ok' => true, 'id' => $id];
        } catch (\Throwable) {
            return $this->desfazerAberturaDeOs($idCliente);
        }
    }

    /** @return array{ok: false, motivo: string} */
    private function desfazerAberturaDeOs(int $idCliente): array
    {
        $this->db->trans_rollback();
        log_message('error', sprintf('transacao rollback operacao=abrir_os_cliente id=%d', $idCliente));

        return ['ok' => false, 'motivo' => 'erro'];
    }

    /**
     * Técnico que recebe a OS aberta pelo cliente: o com menos OS; sem OS no
     * sistema, o primeiro usuário ativo.
     */
    public function tecnicoParaNovaOs(): ?int
    {
        $usuario = $this->db->query('SELECT usuarios_id, count(*) as down FROM os GROUP BY usuarios_id ORDER BY down LIMIT 1')->row();
        if ($usuario !== null && $usuario->usuarios_id) {
            return (int) $usuario->usuarios_id;
        }

        $usuario = $this->db->where('situacao', 1)->limit(1)->get('usuarios')->row();

        return $usuario ? (int) $usuario->idUsuarios : null;
    }

    public function add($table, $data, $returnId = false)
    {
        $this->db->insert($table, $data);
        if ($this->db->affected_rows() == '1') {
            if ($returnId == true) {
                return $this->db->insert_id($table);
            }

            return true;
        }

        return false;
    }

    public function getLastOs($cliente, int $limite = 10)
    {
        $this->db->select('os.*, ' . self::USUARIO_CAMPOS_PUBLICOS);
        $this->db->from('os');
        $this->db->join('usuarios', 'os.usuarios_id = usuarios.idUsuarios', 'left');
        $this->db->where('clientes_id', $cliente);
        $this->db->limit($limite);
        $this->db->order_by('idOs', 'desc');

        return $this->db->get()->result();
    }

    public function getLastCompras($cliente, int $limite = 10)
    {
        $this->db->select('vendas.*,usuarios.nome');
        $this->db->from('vendas');
        $this->db->join('usuarios', 'usuarios.idUsuarios = vendas.usuarios_id');
        $this->db->order_by('idVendas', 'desc');
        $this->db->where('clientes_id', $cliente);
        $this->db->limit($limite);
        $this->db->order_by('idVendas', 'desc');

        return $this->db->get()->result();
    }

    public function getCompras($table, $fields, $where, $perpage, $start, $one, $array, $cliente)
    {
        // $fields costuma vir como '*', o que traria usuarios.senha pelo join.
        $this->db->select($fields === '*' ? 'vendas.*, ' . self::USUARIO_CAMPOS_PUBLICOS : $fields);
        $this->db->from($table);
        $this->db->join('usuarios', 'vendas.usuarios_id = usuarios.idUsuarios', 'left');
        $this->db->order_by('idVendas', 'desc');
        $this->db->where('clientes_id', $cliente);
        $this->db->limit($perpage, $start);
        $this->db->order_by('idVendas', 'desc');
        if ($where) {
            $this->db->where($where);
        }

        $query = $this->db->get();

        $result = ! $one ? $query->result() : $query->row();

        return $result;
    }

    public function getCobrancas($table, $fields, $where, $perpage, $start, $one, $array, $cliente)
    {
        // $fields costuma vir como '*', o que traria clientes.senha pelo join.
        $this->db->select($fields === '*'
            ? 'cobrancas.*, clientes.idClientes, clientes.nomeCliente, clientes.documento,
               clientes.telefone, clientes.celular, clientes.email, clientes.rua, clientes.numero,
               clientes.complemento, clientes.bairro, clientes.cidade, clientes.estado, clientes.cep'
            : $fields);
        $this->db->from($table);
        $this->db->join('clientes', 'cobrancas.clientes_id = clientes.idClientes', 'left');
        $this->db->where('clientes_id', $cliente);
        $this->db->order_by('expire_at', 'desc');
        $this->db->limit($perpage, $start);
        $this->db->order_by('idCobranca', 'desc');
        if ($where) {
            $this->db->where($where);
        }

        $query = $this->db->get();

        $result = ! $one ? $query->result() : $query->row();

        return $result;
    }

    public function getOs($table, $fields, $where, $perpage, $start, $one, $array, $cliente)
    {
        // $fields costuma vir como '*', o que traria usuarios.senha pelo join.
        $this->db->select($fields === '*' ? 'os.*, ' . self::USUARIO_CAMPOS_PUBLICOS : $fields);
        $this->db->from($table);
        $this->db->join('usuarios', 'os.usuarios_id = usuarios.idUsuarios', 'left');
        $this->db->where('clientes_id', $cliente);
        $this->db->limit($perpage, $start);
        $this->db->order_by('idOs', 'desc');
        if ($where) {
            $this->db->where($where);
        }

        $query = $this->db->get();

        $result = ! $one ? $query->result() : $query->row();

        return $result;
    }

    public function getById($id)
    {
        $this->db->select('os.*, clientes.*, clientes.celular as celular_cliente, garantias.refGarantia, garantias.textoGarantia, usuarios.telefone as telefone_usuario, usuarios.email as email_usuario, usuarios.nome');
        $this->db->from('os');
        $this->db->join('clientes', 'clientes.idClientes = os.clientes_id');
        $this->db->join('usuarios', 'usuarios.idUsuarios = os.usuarios_id');
        $this->db->join('garantias', 'garantias.idGarantias = os.garantias_id', 'left');
        $this->db->where('os.idOs', $id);
        $this->db->limit(1);

        return $this->db->get()->row();
    }

    public function count($table, $cliente)
    {
        $this->db->where('clientes_id', $cliente);

        return $this->db->count_all_results($table);
    }

    /**
     * OS do cliente que ainda não terminaram: nem finalizadas, nem faturadas,
     * nem canceladas.
     */
    public function contarOsEmAndamento($cliente): int
    {
        return (int) $this->db->where('clientes_id', $cliente)
            ->where_not_in('status', ['Finalizado', 'Faturado', 'Cancelado'])
            ->count_all_results('os');
    }

    public function getDados()
    {
        $this->db->where('idclientes', $this->session->userdata('cliente_id'));
        $this->db->limit(1);

        return $this->db->get('clientes')->row();
    }

    /**
     * Versão da sessão e troca de senha pendente do cliente, lidas do banco a
     * cada requisição da área do cliente. Null: cliente inexistente ou consulta
     * que falhou (quem chama encerra a sessão).
     *
     * @return array{sessao_versao: int, senha_trocar: int}|null
     */
    public function estadoDaSessao(int $idCliente): ?array
    {
        $consulta = $this->db->select('sessao_versao, senha_trocar')->where('idClientes', $idCliente)->limit(1)->get('clientes');
        if ($consulta === false) {
            log_message('error', sprintf('cliente sessao falha id=%d', $idCliente));

            return null;
        }

        $linha = $consulta->row();

        return $linha ? ['sessao_versao' => (int) $linha->sessao_versao, 'senha_trocar' => (int) $linha->senha_trocar] : null;
    }

    /** Marca o token de recuperação como usado; false = outra requisição já o consumiu. */
    public function consumirTokenDeReset(int $idToken): bool
    {
        $this->db->where('id', $idToken)->where('token_utilizado', 0)->update('resets_de_senha', ['token_utilizado' => 1]);

        return $this->db->affected_rows() === 1;
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

    public function getQrCode($id, $pixKey, $emitente)
    {
        if (empty($id) || empty($pixKey) || empty($emitente)) {
            return;
        }

        $result = $this->valorTotalOS($id);
        $amount = $result['valor_desconto'] != 0 ? round(floatval($result['valor_desconto']), 2) : round(floatval($result['totalServico'] + $result['totalProdutos']), 2);

        if ($amount <= 0) {
            return;
        }

        $pix = (new StaticPayload())
            ->setAmount($amount)
            ->setTid($id)
            ->setDescription(sprintf('%s OS %s', substr($emitente->nome, 0, 18), $id), true)
            ->setPixKey(getPixKeyType($pixKey), $pixKey)
            ->setMerchantName($emitente->nome)
            ->setMerchantCity($emitente->cidade);

        return $pix->getQRCode();
    }
}

/* End of file conecte_model.php */
/* Location: ./application/models/conecte_model.php */
