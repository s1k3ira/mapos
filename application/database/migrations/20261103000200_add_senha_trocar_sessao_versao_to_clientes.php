<?php

/**
 * Área do cliente: senha_trocar (1 = o cliente precisa trocar a senha no próximo
 * acesso) e sessao_versao (somar 1 derruba as sessões abertas do cliente).
 * Clientes existentes ficam com 0 nas duas. clientes.token_versao já existe.
 */
class Migration_add_senha_trocar_sessao_versao_to_clientes extends CI_Migration
{
    public function up()
    {
        if (! $this->db->field_exists('senha_trocar', 'clientes')) {
            $this->dbforge->add_column('clientes', [
                'senha_trocar' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
            ]);
        }

        if (! $this->db->field_exists('sessao_versao', 'clientes')) {
            $this->dbforge->add_column('clientes', [
                'sessao_versao' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0, 'null' => false],
            ]);
        }

        // Com db_debug desligado (produção) um ALTER com erro só vai para o log.
        foreach (['senha_trocar', 'sessao_versao'] as $coluna) {
            if (! $this->db->field_exists($coluna, 'clientes')) {
                throw new RuntimeException("Não foi possível criar a coluna clientes.{$coluna}; veja o log da aplicação.");
            }
        }
    }

    public function down()
    {
        foreach (['senha_trocar', 'sessao_versao'] as $coluna) {
            if ($this->db->field_exists($coluna, 'clientes')) {
                $this->dbforge->drop_column('clientes', $coluna);
            }
        }
    }
}
