<?php

/**
 * Refresh token da área do cliente na mesma tabela do operador: api_refresh_tokens.tipo
 * diz de quem é o usuario_id ('usuario' = operador, 'cliente'). As linhas
 * legadas ficam como 'usuario'. O índice simples usuario_id dá lugar ao
 * composto (tipo, usuario_id).
 */
class Migration_add_tipo_to_api_refresh_tokens extends CI_Migration
{
    public function up()
    {
        if (! $this->db->field_exists('tipo', 'api_refresh_tokens')) {
            $this->dbforge->add_column('api_refresh_tokens', [
                'tipo' => ['type' => "ENUM('usuario','cliente')", 'default' => 'usuario', 'null' => false, 'after' => 'id'],
            ]);
        }

        // Com db_debug desligado (produção) um ALTER com erro só vai para o log.
        if (! $this->db->field_exists('tipo', 'api_refresh_tokens')) {
            throw new RuntimeException('Não foi possível criar a coluna api_refresh_tokens.tipo; veja o log da aplicação.');
        }

        $tabela = $this->db->protect_identifiers('api_refresh_tokens');
        if (! $this->indiceExiste('api_refresh_tokens', 'api_refresh_tokens_dono')) {
            $this->db->query('CREATE INDEX api_refresh_tokens_dono ON ' . $tabela . ' (tipo, usuario_id)');
        }

        if (! $this->indiceExiste('api_refresh_tokens', 'api_refresh_tokens_dono')) {
            throw new RuntimeException('Não foi possível criar o índice api_refresh_tokens_dono; veja o log da aplicação.');
        }

        if ($this->indiceExiste('api_refresh_tokens', 'usuario_id')) {
            $this->db->query('DROP INDEX usuario_id ON ' . $tabela);
        }
    }

    public function down()
    {
        $tabela = $this->db->protect_identifiers('api_refresh_tokens');

        if ($this->db->field_exists('tipo', 'api_refresh_tokens')) {
            // Sem a coluna, um refresh token de cliente viraria token de operador.
            $this->db->query("DELETE FROM {$tabela} WHERE tipo = 'cliente'");
        }

        if (! $this->indiceExiste('api_refresh_tokens', 'usuario_id')) {
            $this->db->query('CREATE INDEX usuario_id ON ' . $tabela . ' (usuario_id)');
        }

        if ($this->indiceExiste('api_refresh_tokens', 'api_refresh_tokens_dono')) {
            $this->db->query('DROP INDEX api_refresh_tokens_dono ON ' . $tabela);
        }

        if ($this->db->field_exists('tipo', 'api_refresh_tokens')) {
            $this->dbforge->drop_column('api_refresh_tokens', 'tipo');
        }
    }

    private function indiceExiste(string $tabela, string $indice): bool
    {
        foreach ($this->db->query('SHOW INDEX FROM ' . $this->db->protect_identifiers($tabela))->result_array() as $linha) {
            if ($linha['Key_name'] === $indice) {
                return true;
            }
        }

        return false;
    }
}
