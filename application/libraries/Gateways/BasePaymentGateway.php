<?php

namespace Libraries\Gateways;

use Libraries\Gateways\Contracts\PaymentGateway;
use Libraries\Gateways\Exceptions\GatewayIndisponivelException;
use Libraries\Gateways\Exceptions\GatewayRecusouException;

abstract class BasePaymentGateway implements PaymentGateway
{
    /** @var object Instância do CodeIgniter, injetada por get_instance() no construtor dos gateways */
    protected $ci;

    /**
     * Existe só para satisfazer o PHPStan.
     *
     * As subclasses chamam parent::__construct(), mas esta classe não tem
     * construtor próprio, e o PHPStan resolve a chamada contra CI_Model/_
     * Controller e acusa "undefined static method __construct()".
     */
    public function __construct()
    {
    }
    public function gerarCobranca($id, $tipo, $metodoPagamento, $data = [])
    {
        switch ($metodoPagamento) {
            case PaymentGateway::PAYMENT_METHOD_BILLET:
                return $this->gerarCobrancaBoleto($id, $tipo);
                break;
            case PaymentGateway::PAYMENT_METHOD_LINK:
                return $this->gerarCobrancaLink($id, $tipo);
                break;
            default:
                throw new \Exception('Método de pagamento inválido!');
        }
    }

    public function findEntity($id, $tipo)
    {
        switch ($tipo) {
            case PaymentGateway::PAYMENT_TYPE_OS:
                return $this->ci->Os_model->getById($id);
                break;
            case PaymentGateway::PAYMENT_TYPE_VENDAS:
                return $this->ci->vendas_model->getById($id);
                break;
            default:
                throw new \Exception('Tipo de entidade a ser gerado a cobrança inválido!');
                break;
        }
    }

    /**
     * Valor da cobrança: o mesmo total que a tela da OS ou da venda mostra
     * (Os_model::totais() / Vendas_model::totais()), com o preço do cadastro
     * para serviço sem preço, quantidade fracionada, desconto por item e
     * desconto geral (#3014). Os gateways não recalculam os itens.
     *
     * @param object $entity Resultado de findEntity()
     */
    protected function valorDaCobranca(object $entity, string $tipo): float
    {
        switch ($tipo) {
            case PaymentGateway::PAYMENT_TYPE_OS:
                return (float) $this->ci->Os_model->totais($entity)['total'];
            case PaymentGateway::PAYMENT_TYPE_VENDAS:
                return (float) $this->ci->vendas_model->totais($entity)['total'];
            default:
                throw new \Exception('Tipo de entidade a ser gerado a cobrança inválido!');
        }
    }

    public function errosCadastro($entity = null)
    {
        if ($entity == null) {
            return null;
        }
        $error_list = "Por favor preencher os seguintes dados do(a) seu(ua) cliente!\n\n";
        $check = false;
        $list = ['rua', 'numero', 'bairro', 'cep', 'cidade', 'estado', 'documento', 'telefone', 'nomeCliente', 'email'];
        foreach ($entity as $key => $value) {
            if (in_array($key, $list)) {
                if ((empty($value) || strlen($value) < 2) && ! is_numeric($value)) {
                    $error_list .= '-' . $key . "\n";
                    $check = true;
                }
            }
        }
        if (! $check) {
            return null;
        }

        return $error_list;
    }

    public function enviarPorEmail($id)
    {
        throw new \Exception('Não implementado');
    }

    public function atualizarDados($id)
    {
        throw new \Exception('Não implementado');
    }

    public function cancelar($id)
    {
        throw new \Exception('Não implementado');
    }

    public function confirmarPagamento($id)
    {
        throw new \Exception('Não implementado');
    }

    /** Executa a chamada ao provedor; falha vira GatewayRecusou/Indisponivel e uma linha de log sem dado pessoal. */
    protected function chamarGateway(string $operacao, $idCobranca, callable $chamada)
    {
        $inicio = microtime(true);
        try {
            return $chamada();
        } catch (GatewayRecusouException | GatewayIndisponivelException $e) {
            $origem = $e->getPrevious() ?? $e;
            $this->registrarFalha($operacao, $idCobranca, $this->codigoHttp($origem), $inicio, $origem);
            throw $e;
        } catch (\Throwable $e) {
            $this->registrarFalha($operacao, $idCobranca, $this->codigoHttp($e), $inicio, $e);
            throw $this->traduzirFalha($e, $operacao);
        }
    }

    protected function registrarFalha(string $operacao, $idCobranca, ?int $http, float $inicio, \Throwable $erro): void
    {
        log_message('error', sprintf(
            'gateway op=%s gateway=%s cobranca=%s http=%s duracao_ms=%d erro=%s',
            $operacao,
            $this->nomeDoGateway(),
            $idCobranca ?? '-',
            $http ?? '-',
            (int) round((microtime(true) - $inicio) * 1000),
            $erro::class
        ));
    }

    protected function traduzirFalha(\Throwable $e, string $operacao): \RuntimeException
    {
        return new GatewayIndisponivelException(GatewayIndisponivelException::MENSAGEM, $e);
    }

    protected function codigoHttp(\Throwable $e): ?int
    {
        return null;
    }

    protected function nomeDoGateway(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    /** URL de notificação montada do próprio site; null quando o segredo do webhook não está configurado. */
    protected function urlDeNotificacao(string $variavelDoSegredo, string $rota): ?string
    {
        $segredo = trim((string) ($_ENV[$variavelDoSegredo] ?? ''));

        return $segredo === '' ? null : site_url($rota);
    }

    abstract protected function gerarCobrancaBoleto($id, $tipo);

    abstract protected function gerarCobrancaLink($id, $tipo);
}
