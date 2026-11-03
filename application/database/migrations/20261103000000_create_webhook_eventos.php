<?php

/**
 * Idempotência dos webhooks de pagamento: uma linha por evento recebido do
 * gateway. O índice único (gateway, evento_id) impede processar o mesmo evento
 * duas vezes. Só o SHA-256 do corpo fica no banco; o corpo nunca é persistido.
 */
class Migration_create_webhook_eventos extends CI_Migration
{
    private const INDICES = [
        'webhook_eventos_gateway_evento' => 'CREATE UNIQUE INDEX webhook_eventos_gateway_evento ON %s (gateway, evento_id)',
        'webhook_eventos_cobranca' => 'CREATE INDEX webhook_eventos_cobranca ON %s (cobranca_id)',
        'webhook_eventos_created' => 'CREATE INDEX webhook_eventos_created ON %s (created_at)',
    ];

    public function up()
    {
        if (! $this->db->table_exists('webhook_eventos')) {
            $this->dbforge->add_field([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'gateway' => ['type' => "ENUM('efi','mercadopago','asaas')"],
                'evento_id' => ['type' => 'VARCHAR', 'constraint' => 128],
                'cobranca_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'payload_hash' => ['type' => 'CHAR', 'constraint' => 64],
                'resultado' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'processado_em' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->dbforge->add_key('id', true);
            // Charset e collation vêm da conexão (config/database.php), como nas outras tabelas.
            $this->dbforge->create_table('webhook_eventos', true, ['ENGINE' => 'InnoDB']);
        }

        // Com db_debug desligado (produção) um CREATE com erro só vai para o log
        // e a versão avançaria mesmo assim.
        if (! $this->db->table_exists('webhook_eventos')) {
            throw new RuntimeException('Não foi possível criar a tabela webhook_eventos; veja o log da aplicação.');
        }

        $tabela = $this->db->protect_identifiers('webhook_eventos');
        foreach (self::INDICES as $nome => $sql) {
            if (! $this->indiceExiste('webhook_eventos', $nome)) {
                $this->db->query(sprintf($sql, $tabela));
            }
        }

        if (! $this->indiceExiste('webhook_eventos', 'webhook_eventos_gateway_evento')) {
            throw new RuntimeException('Não foi possível criar o índice webhook_eventos_gateway_evento; veja o log da aplicação.');
        }
    }

    public function down()
    {
        $this->dbforge->drop_table('webhook_eventos', true);
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
