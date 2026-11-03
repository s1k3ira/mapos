<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Email extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('email');
    }

    public function process()
    {
        if (! $this->input->is_cli_request()) {
            show_404();
        }

        $this->sair($this->executarCiclo('process', $this->email));
    }

    public function retry()
    {
        if (! $this->input->is_cli_request()) {
            show_404();
        }

        $this->sair($this->executarCiclo('retry', $this->email));
    }

    /**
     * Roda um ciclo da fila e devolve o código de saída do cron: 0 em sucesso
     * ou lock ocupado, 1 em falha técnica.
     */
    protected function executarCiclo(string $ciclo, object $fila): int
    {
        log_message('info', "email_fila ciclo={$ciclo} inicio");

        try {
            if ($ciclo === 'process') {
                $resultado = $fila->processarFila();

                if ($resultado['ignorado']) {
                    log_message('info', 'email_fila ciclo=process ignorado');

                    return 0;
                }

                log_message('info', sprintf('email_fila ciclo=process reservados=%d enviados=%d falhas=%d definitivos=%d', $resultado['reservados'], $resultado['enviados'], $resultado['falhas'], $resultado['definitivos']));

                return 0;
            }

            $resultado = $fila->reabrirFila();
            log_message('info', sprintf('email_fila ciclo=retry reabertos=%d definitivos=%d', $resultado['reabertos'], $resultado['definitivos']));

            return 0;
        } catch (\Throwable $e) {
            // A mensagem fica de fora: pode conter SQL com destinatário.
            log_message('error', "email_fila ciclo={$ciclo} erro=" . $e::class);

            return 1;
        }
    }

    protected function sair(int $codigo): void
    {
        exit($codigo);
    }
}
