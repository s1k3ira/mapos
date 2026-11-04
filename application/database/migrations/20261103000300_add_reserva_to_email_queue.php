<?php

/**
 * Reserva atômica da fila de e-mail: cada worker marca as linhas que pegou em
 * reservado_por/reservado_em e conta as tentativas. O status continua o mesmo
 * ENUM; o índice (status, reservado_por) serve a busca por reserva.
 */
class Migration_add_reserva_to_email_queue extends CI_Migration
{
    private const COLUNAS = [
        'tentativas' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0, 'null' => false],
        'reservado_por' => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
        'reservado_em' => ['type' => 'DATETIME', 'null' => true],
    ];

    public function up()
    {
        foreach (self::COLUNAS as $nome => $definicao) {
            if (! $this->db->field_exists($nome, 'email_queue')) {
                $this->dbforge->add_column('email_queue', [$nome => $definicao]);
            }
        }

        // Com db_debug desligado (produção) um ALTER com erro só vai para o log.
        foreach (array_keys(self::COLUNAS) as $nome) {
            if (! $this->db->field_exists($nome, 'email_queue')) {
                throw new RuntimeException("Não foi possível criar a coluna email_queue.{$nome}; veja o log da aplicação.");
            }
        }

        if (! $this->indiceExiste('email_queue', 'email_queue_status_reserva')) {
            $this->db->query('CREATE INDEX email_queue_status_reserva ON ' . $this->db->protect_identifiers('email_queue') . ' (status, reservado_por)');
        }

        if (! $this->indiceExiste('email_queue', 'email_queue_status_reserva')) {
            throw new RuntimeException('Não foi possível criar o índice email_queue_status_reserva; veja o log da aplicação.');
        }
    }

    public function down()
    {
        if ($this->indiceExiste('email_queue', 'email_queue_status_reserva')) {
            $this->db->query('DROP INDEX email_queue_status_reserva ON ' . $this->db->protect_identifiers('email_queue'));
        }

        foreach (array_keys(self::COLUNAS) as $nome) {
            if ($this->db->field_exists($nome, 'email_queue')) {
                $this->dbforge->drop_column('email_queue', $nome);
            }
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
