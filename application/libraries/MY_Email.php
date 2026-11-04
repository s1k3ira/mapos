<?php

defined('BASEPATH') or exit('No direct script access allowed');
/**
 * CodeIgniter Email Queue
 *
 * A CodeIgniter library to queue e-mails.
 *
 * @category    Libraries
 *
 * @author      Thaynã Bruno Moretti
 *
 * @link    http://www.meau.com.br/
 *
 * @license http://www.opensource.org/licenses/mit-license.html
 *
 * Updated by @RamonSilva for Map-OS
 */
class MY_Email extends CI_Email
{
    public const MAX_TENTATIVAS = 5;

    public const LOTE = 50;

    public const RESERVA_EXPIRA_MINUTOS = 15;

    public const NOME_DO_LOCK = 'mapos_email_fila';

    /** @var object Instância do CodeIgniter, injetada por get_instance() no construtor */
    protected $CI;

    // DB table
    private $table_email_queue = 'email_queue';

    // Status (pending, sending, sent, failed)
    private $status;

    /**
     * Constructor
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        log_message('debug', 'Email Queue Class Initialized');

        $this->CI = &get_instance();

        $this->CI->load->database('default');
    }

    public function set_status($status)
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get
     *
     * Get queue emails.
     *
     * @return mixed
     */
    public function get($limit = null, $offset = null)
    {
        if ($this->status != false) {
            $this->CI->db->where('status', $this->status);
        }

        $query = $this->CI->db->get("{$this->table_email_queue}", $limit, $offset);

        return $query->result();
    }

    /**
     * Save
     *
     * Add queue email to database.
     *
     * @return mixed
     */
    public function send($skip_job = false)
    {
        if ($skip_job === true) {
            return parent::send();
        }

        $date = date('Y-m-d H:i:s');

        $to = is_array($this->_recipients) ? implode(', ', $this->_recipients) : $this->_recipients;
        $cc = implode(', ', $this->_cc_array);
        $bcc = implode(', ', $this->_bcc_array);

        $dbdata = [
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'message' => $this->_body,
            'headers' => json_encode($this->_headers),
            'status' => 'pending',
            'date' => $date,
        ];

        return $this->CI->db->insert($this->table_email_queue, $dbdata);
    }

    /**
     * Envia um lote da fila. Só uma execução por vez (GET_LOCK); cada e-mail é
     * reservado de forma atômica e conta uma tentativa ao ser reservado.
     *
     * @return array{ignorado: bool, reservados: int, enviados: int, falhas: int, definitivos: int}
     *
     * @throws RuntimeException erro de banco
     */
    public function processarFila(): array
    {
        $resultado = ['ignorado' => false, 'reservados' => 0, 'enviados' => 0, 'falhas' => 0, 'definitivos' => 0];

        if (! $this->obterLock()) {
            $resultado['ignorado'] = true;

            return $resultado;
        }

        try {
            $reserva = bin2hex(random_bytes(16));
            $agora = date('Y-m-d H:i:s', $this->agora());
            $ok = $this->CI->db->query(
                'UPDATE email_queue SET status = ?, reservado_por = ?, reservado_em = ?, tentativas = tentativas + 1'
                . ' WHERE status = ? AND tentativas < ? AND id IN (SELECT id FROM (SELECT id FROM email_queue WHERE status = ? AND tentativas < ? ORDER BY id LIMIT ' . self::LOTE . ') AS lote)',
                ['sending', $reserva, $agora, 'pending', self::MAX_TENTATIVAS, 'pending', self::MAX_TENTATIVAS]
            );

            if ($ok === false) {
                throw new RuntimeException('email_fila: falha ao reservar');
            }

            $emails = $this->CI->db->where('reservado_por', $reserva)->where('status', 'sending')->order_by('id')->get($this->table_email_queue)->result();
            $resultado['reservados'] = count($emails);

            foreach ($emails as $email) {
                try {
                    $enviado = $this->enviarDaFila($email);
                } catch (\Throwable $e) {
                    $enviado = false;
                }

                if ($enviado) {
                    $resultado['enviados']++;
                } else {
                    $resultado['falhas']++;

                    if ((int) $email->tentativas >= self::MAX_TENTATIVAS) {
                        $resultado['definitivos']++;
                    }

                    log_message('error', sprintf('email_fila falha id=%d tentativas=%d %s', (int) $email->id, (int) $email->tentativas, trim($this->print_debugger(['headers']))));
                }

                $this->CI->db->where('id', $email->id)->where('reservado_por', $reserva)->update($this->table_email_queue, [
                    'status' => $enviado ? 'sent' : 'failed',
                    'date' => date('Y-m-d H:i:s', $this->agora()),
                ]);
            }
        } finally {
            $this->liberarLock();
        }

        return $resultado;
    }

    /**
     * Reabre para nova tentativa os e-mails com falha e as reservas expiradas.
     *
     * @return array{reabertos: int, definitivos: int}
     *
     * @throws RuntimeException erro de banco
     */
    public function reabrirFila(): array
    {
        $limite = date('Y-m-d H:i:s', $this->agora() - self::RESERVA_EXPIRA_MINUTOS * 60);
        $expirado = ' WHERE status = ? AND (reservado_em < ? OR (reservado_em IS NULL AND date < ?))';

        $falhos = $this->atualizarFila('UPDATE email_queue SET status = ? WHERE status = ? AND tentativas < ?', ['pending', 'failed', self::MAX_TENTATIVAS]);
        $expirados = $this->atualizarFila(
            'UPDATE email_queue SET status = ?, reservado_por = NULL, reservado_em = NULL' . $expirado . ' AND tentativas < ?',
            ['pending', 'sending', $limite, $limite, self::MAX_TENTATIVAS]
        );
        $definitivos = $this->atualizarFila(
            'UPDATE email_queue SET status = ?' . $expirado . ' AND tentativas >= ?',
            ['failed', 'sending', $limite, $limite, self::MAX_TENTATIVAS]
        );

        return ['reabertos' => $falhos + $expirados, 'definitivos' => $definitivos];
    }

    protected function obterLock(): bool
    {
        $linha = $this->CI->db->query('SELECT GET_LOCK(?, 0) AS ok', [self::NOME_DO_LOCK])->row();

        return $linha !== null && (int) $linha->ok === 1;
    }

    protected function liberarLock(): void
    {
        $this->CI->db->query('SELECT RELEASE_LOCK(?)', [self::NOME_DO_LOCK]);
    }

    protected function enviarDaFila(object $email): bool
    {
        $this->clear(true);

        $this->to(explode(', ', $email->to));
        $this->cc(! empty($email->cc) ? explode(', ', $email->cc) : []);
        $this->bcc(! empty($email->bcc) ? explode(', ', $email->bcc) : []);
        $this->message($email->message);
        $this->_headers = json_decode_legacy($email->headers);

        return (bool) $this->send(true);
    }

    protected function agora(): int
    {
        return time();
    }

    /**
     * @param list<string|int> $binds
     *
     * @throws RuntimeException
     */
    private function atualizarFila(string $sql, array $binds): int
    {
        if ($this->CI->db->query($sql, $binds) === false) {
            throw new RuntimeException('email_fila: falha ao atualizar');
        }

        return (int) $this->CI->db->affected_rows();
    }
}
