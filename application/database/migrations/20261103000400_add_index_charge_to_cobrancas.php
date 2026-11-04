<?php

/**
 * Busca da cobrança pelo id do gateway (webhooks): índice (charge_id, payment_gateway)
 * com prefixo, pois as duas colunas são VARCHAR(255). Não cria se já existe
 * algum índice começando por charge_id.
 */
class Migration_add_index_charge_to_cobrancas extends CI_Migration
{
    public function up()
    {
        if (! $this->existeIndiceComecandoPor('cobrancas', 'charge_id')) {
            $this->db->query('CREATE INDEX cobrancas_charge_gateway ON ' . $this->db->protect_identifiers('cobrancas') . ' (charge_id(64), payment_gateway(32))');
        }

        // Com db_debug desligado (produção) um CREATE com erro só vai para o log.
        if (! $this->existeIndiceComecandoPor('cobrancas', 'charge_id')) {
            throw new RuntimeException('Não foi possível criar o índice cobrancas_charge_gateway; veja o log da aplicação.');
        }
    }

    public function down()
    {
        if ($this->indiceExiste('cobrancas', 'cobrancas_charge_gateway')) {
            $this->db->query('DROP INDEX cobrancas_charge_gateway ON ' . $this->db->protect_identifiers('cobrancas'));
        }
    }

    private function indiceExiste(string $tabela, string $indice): bool
    {
        foreach ($this->linhasDeIndice($tabela) as $linha) {
            if ($linha['Key_name'] === $indice) {
                return true;
            }
        }

        return false;
    }

    private function existeIndiceComecandoPor(string $tabela, string $coluna): bool
    {
        foreach ($this->linhasDeIndice($tabela) as $linha) {
            if ((int) $linha['Seq_in_index'] === 1 && $linha['Column_name'] === $coluna) {
                return true;
            }
        }

        return false;
    }

    private function linhasDeIndice(string $tabela): array
    {
        return $this->db->query('SHOW INDEX FROM ' . $this->db->protect_identifiers($tabela))->result_array();
    }
}
