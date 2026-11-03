---
checkpoint: null
complexity: G
created_at: "2026-11-03 15:58:10"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testRecusaComConteudoArrayViraMensagemStringSemTypeError tests/Unit/GatewayMercadoPagoTest.php
      text: MPApiException 400 com conteúdo array na criação de boleto vira GatewayRecusouException com mensagem string em pt-BR (sem TypeError e sem o texto do provedor)
    - done: false
      test: '! grep -nE ''new \\Exception\(\$e->getApiResponse\(\)'' application/libraries/Gateways/MercadoPago.php'
      text: Nenhum catch do Mercado Pago lança Exception com o conteúdo cru da API
    - done: false
      test: application/vendor/bin/phpunit --filter Indisponivel tests/Unit/GatewayMercadoPagoTest.php
      text: MPApiException 5xx/429/401 e MPException viram GatewayIndisponivelException com a mensagem genérica; 404 vira GatewayRecusouException('Cobrança não encontrada no Mercado Pago.')
    - done: false
      test: application/vendor/bin/phpunit --filter Log tests/Unit/GatewayMercadoPagoTest.php
      text: Toda falha gera uma linha 'gateway op=<op> gateway=MercadoPago cobranca=<id> http=<cod> duracao_ms=' sem CPF, e-mail, token nem corpo do provedor
    - done: false
      test: grep -qE 'class GatewayRecusouException extends \\RuntimeException' application/libraries/Gateways/Exceptions/GatewayRecusouException.php && grep -qE 'class GatewayIndisponivelException extends \\RuntimeException' application/libraries/Gateways/Exceptions/GatewayIndisponivelException.php
      text: As duas exceções estendem RuntimeException no namespace Libraries\Gateways\Exceptions
    - done: false
      test: application/vendor/bin/phpunit --filter Descricao tests/Unit/GatewayMercadoPagoTest.php
      text: 'dadosDoBoleto() descreve venda como ''Venda #31'' e OS como ''OS #12'''
    - done: false
      test: '! grep -nE ''PaymentGateway::PAYMENT_TYPE_OS \?'' application/libraries/Gateways/MercadoPago.php'
      text: A descrição não testa mais a constante PAYMENT_TYPE_OS sozinha
    - done: false
      test: application/vendor/bin/phpunit --filter NotificationUrl tests/Unit/GatewayMercadoPagoTest.php
      text: Sem MP_WEBHOOK_SECRET a cobrança é montada sem notification_url; com o segredo, notification_url = site_url('webhooks/mercadopago')
    - done: false
      test: '! grep -nE ''seusite\.com\.br|mapos\.com\.br'' application/libraries/Gateways/MercadoPago.php'
      text: Nenhum domínio fixo de terceiro permanece no Mercado Pago
    - done: false
      test: application/vendor/bin/phpunit --filter TimeoutDoConfig tests/Unit/GatewayMercadoPagoTest.php
      text: payment_gateways.php expõe timeout no MercadoPago lido de PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT com padrão 10 para ausente, vazio, 0 ou não numérico
    - done: false
      test: application/vendor/bin/phpunit --filter TimeoutDoCliente tests/Unit/GatewayMercadoPagoTest.php
      text: O cliente HTTP do SDK fica configurado com o timeout de conexão e de resposta informado (25 s lido de volta do cliente)
    - done: false
      test: grep -qx 'PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT=10' application/.env.example && grep -qx 'MP_WEBHOOK_SECRET=' application/.env.example
      text: .env.example lista PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT=10 e MP_WEBHOOK_SECRET vazio
    - done: false
      test: application/vendor/bin/phpunit tests/Unit/CobrancaValorTest.php
      text: O teste de cobrança existente continua passando com os métodos novos não abstratos na base
    - done: false
      test: grep -cE 'assertSame\(.O (Mercado Pago|provedor|pagamento)' tests/Unit/GatewayMercadoPagoTest.php | grep -qvx 0
      text: 'Os testes não validam só o tipo da exceção: conferem a mensagem exata'
deps:
    - TASK-001
files:
    - application/libraries/Gateways/Exceptions/GatewayRecusouException.php
    - application/libraries/Gateways/Exceptions/GatewayIndisponivelException.php
    - application/libraries/Gateways/BasePaymentGateway.php
    - application/libraries/Gateways/MercadoPago.php
    - application/config/payment_gateways.php
    - application/.env.example
    - tests/Unit/GatewayMercadoPagoTest.php
id: TASK-004
linter: composer analyse
n45_version: 1.1.0
outcome: ""
persona: backend
phase: 'Phase 1 — Base de segurança: schema, API, gateways e avisos de sessão'
roadmap: feat-0001-melhoria-seguranca
status: pending
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/GatewayMercadoPagoTest.php tests/Unit/CobrancaValorTest.php
title: 'Mercado Pago: exceções tipadas, log, timeout e descrição da cobrança'
updated_at: "2026-11-03 15:58:10"
---
## Contexto

Os gateways de pagamento (`application/libraries/Gateways/`, classes globais `MercadoPago`, `Asaas`, `GerencianetSdk` que estendem `Libraries\Gateways\BasePaymentGateway`, autoload PSR-4 `Libraries\` → `application/libraries`) hoje falham mal: não logam nada, não têm timeout (Mercado Pago e Asaas), devolvem ao usuário o texto cru do provedor e, no Mercado Pago, `gerarCobrancaBoleto()` faz `throw new \Exception($e->getApiResponse()->getContent())` — `getContent()` é **array**, então o PHP lança `TypeError` em vez da mensagem. A descrição da cobrança também está errada: `PaymentGateway::PAYMENT_TYPE_OS ? "OS #$id" : "Venda #$id"` testa a **constante** (sempre verdadeira), então venda sai como "OS #<id>". E a `notification_url` aponta para `https://seusite.com.br/mercadopago/retorno`, um domínio de terceiro.

Esta task cria a **base comum de erros dos gateways** (duas exceções tipadas + um wrapper de chamada com log) e aplica ao **Mercado Pago**. A task seguinte (`Asaas e Efí`) reutiliza exatamente o mesmo wrapper — por isso as assinaturas abaixo são contrato. `application/controllers/Cobrancas.php` **não** muda aqui (outra task trata as respostas 422/502): os `catch (\Exception $e)` atuais continuam pegando as exceções novas, que estendem `\RuntimeException`.

Regras do projeto: erro técnico em `log_message('error', ...)` numa linha `chave=valor`; **nunca** logar token, CPF/CNPJ, segredo, corpo bruto do provedor. Configuração no `application/.env` lida em `application/config/*.php` por `$_ENV['NOME'] ?? padrão`; variável nova entra no `application/.env.example` (sem valor real em segredo). Sem dependência nova no composer: o SDK é o `mercadopago/dx-php` 3.8.0 já declarado (rodar `composer install` se `application/vendor/` não existir). O suporte de teste da `TASK-001` já existe em `MaposTestCase`: `$this->logs('error')`, `assertLogContem()`, `assertLogNaoContem()`.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `MercadoPago` cria boleto e o SDK lança `MPApiException` status 400 com `getContent()` = `['message' => 'invalid payer', 'cause' => [...]]` | lança `GatewayRecusouException` com mensagem **string** `O Mercado Pago recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.` (sem `TypeError`, sem `invalid payer`) |
| SDK lança `MPApiException` status 404 em `atualizarDados()` | `GatewayRecusouException('Cobrança não encontrada no Mercado Pago.')` |
| SDK lança `MPApiException` status 500/502/503 ou 429 | `GatewayIndisponivelException('O provedor de pagamento não respondeu. Tente novamente em instantes.')` |
| SDK lança `MPApiException` status 401/403 (credencial errada da instalação) | `GatewayIndisponivelException` com a mensagem genérica acima |
| SDK lança `MPException` (rede, timeout de cURL) | `GatewayIndisponivelException` com a mensagem genérica |
| Qualquer falha acima na operação `criar_boleto` da OS 12 | uma linha de log `error`: `gateway op=criar_boleto gateway=MercadoPago cobranca=- http=400 duracao_ms=<n> erro=MercadoPago\Exceptions\MPApiException` (sem CPF, e-mail, token ou corpo) |
| Falha em `atualizarDados(7)` | log com `op=atualizar cobranca=7` |
| `dadosDoBoleto()` para `tipo = 'venda'`, id 31 | `description` = `Venda #31` |
| `dadosDoBoleto()` para `tipo = 'os'`, id 12 | `description` = `OS #12` |
| `dadosDoBoleto()` com `MP_WEBHOOK_SECRET` vazio/ausente | array **sem** a chave `notification_url` |
| `dadosDoBoleto()` com `MP_WEBHOOK_SECRET=abc` | `notification_url` = `site_url('webhooks/mercadopago')` |
| `payment_gateways.php` com `PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT` ausente / `''` / `0` / `abc` | `['MercadoPago']['timeout'] === 10` |
| `PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT=25` | `['MercadoPago']['timeout'] === 25` |
| `MercadoPago` construído com `timeout = 25` | o cliente HTTP do SDK fica configurado com conexão e resposta de 25 s (valor lido de volta do cliente configurado) |
| `confirmarPagamento()` de pagamento em status que não permite captura | `GatewayRecusouException('O pagamento não está em um estado que permite captura.')` |

## TDD

Testes novos em `tests/Unit/GatewayMercadoPagoTest.php` (`final class GatewayMercadoPagoTest extends MaposTestCase`). Instanciar `MercadoPago` sem construtor (`$this->makeInstance(MercadoPago::class)`) e preencher `ci`/`mercadoPagoConfig` por `setPrivateProperty`, como `tests/Unit/CobrancaValorTest.php` faz com o gateway base. `MPApiException`/`MPResponse` vêm do SDK real (`application/vendor/mercadopago/dx-php`). Estilo:

```php
public function testRecusaComConteudoArrayViraMensagemStringSemTypeError(): void
{
    $gateway = $this->gateway();
    $erro = new MPApiException('Api error', new MPResponse(400, ['message' => 'invalid payer', 'cause' => [['code' => 2067]]]));

    try {
        $this->invokeMethod($gateway, 'chamarGateway', ['criar_boleto', null, static fn () => throw $erro]);
        $this->fail('Deveria recusar.');
    } catch (GatewayRecusouException $e) {
        $this->assertSame('O Mercado Pago recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.', $e->getMessage());
    }

    $this->assertLogContem('error', 'gateway op=criar_boleto gateway=MercadoPago cobranca=- http=400');
    $this->assertLogNaoContem('invalid payer');
}
```

Cobrir os demais casos da tabela (descrição de venda, `notification_url` com e sem segredo, timeout do config e do cliente, 5xx/`MPException` → indisponível). `site_url()` não existe no harness: definir no topo do teste com `if (! function_exists('site_url'))`, como `tests/Unit/LoginViewTest.php`. `$_ENV` alterado no teste é restaurado no `tearDown`.

**Refatoração:** depois do verde, remover os `try { ... } catch (\Exception $e) { throw new \Exception($e->getMessage()); }` que só relançam, já cobertos por `chamarGateway()`.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/libraries/Gateways/Exceptions/GatewayRecusouException.php` | Criar | `namespace Libraries\Gateways\Exceptions; class GatewayRecusouException extends \RuntimeException` |
| `application/libraries/Gateways/Exceptions/GatewayIndisponivelException.php` | Criar | Idem, com mensagem padrão genérica |
| `application/libraries/Gateways/BasePaymentGateway.php` | Modificar | `chamarGateway()`, `registrarFalha()`, ganchos `traduzirFalha()`/`codigoHttp()`/`nomeDoGateway()`, `urlDeNotificacao()` |
| `application/libraries/Gateways/MercadoPago.php` | Modificar | Tudo pelo `chamarGateway()`; `mpErrorMessage()` amigável; `dadosDoBoleto()`; descrição; `notification_url`; timeout |
| `application/config/payment_gateways.php` | Modificar | Só o bloco `MercadoPago`: chave `timeout` |
| `application/.env.example` | Modificar | `PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT=10` e `MP_WEBHOOK_SECRET=` |
| `tests/Unit/GatewayMercadoPagoTest.php` | Criar | Testes da tabela |

### Detalhamento Técnico

1. **Exceções** (um arquivo cada, namespace `Libraries\Gateways\Exceptions`):
   ```php
   final class GatewayIndisponivelException extends \RuntimeException
   {
       public const MENSAGEM = 'O provedor de pagamento não respondeu. Tente novamente em instantes.';

       public function __construct(string $mensagem = self::MENSAGEM, ?\Throwable $anterior = null)
       {
           parent::__construct($mensagem, 0, $anterior);
       }
   }
   ```
   `GatewayRecusouException` igual, sem padrão (a mensagem é obrigatória e **sempre** um texto nosso em pt-BR, nunca o do provedor). Não usar `final` se o PHPStan reclamar de algo; o importante é estender `\RuntimeException`.
2. **`BasePaymentGateway`** (namespace `Libraries\Gateways`), métodos novos — **não abstratos** (o teste `CobrancaValorTest` cria uma subclasse anônima que só implementa `gerarCobrancaBoleto`/`gerarCobrancaLink`):
   ```php
   /** Executa a chamada ao provedor; falha vira GatewayRecusou/Indisponivel e uma linha de log sem dado pessoal. */
   protected function chamarGateway(string $operacao, $idCobranca, callable $chamada)
   {
       $inicio = microtime(true);
       try {
           return $chamada();
       } catch (GatewayRecusouException | GatewayIndisponivelException $e) {
           $this->registrarFalha($operacao, $idCobranca, $this->codigoHttp($e->getPrevious() ?? $e), $inicio, $e->getPrevious() ?? $e);
           throw $e;
       } catch (\Throwable $e) {
           $this->registrarFalha($operacao, $idCobranca, $this->codigoHttp($e), $inicio, $e);
           throw $this->traduzirFalha($e, $operacao);
       }
   }

   protected function registrarFalha(string $operacao, $idCobranca, ?int $http, float $inicio, \Throwable $erro): void
   {
       log_message('error', sprintf('gateway op=%s gateway=%s cobranca=%s http=%s duracao_ms=%d erro=%s',
           $operacao, $this->nomeDoGateway(), $idCobranca ?? '-', $http ?? '-', (int) round((microtime(true) - $inicio) * 1000), $erro::class));
   }

   protected function traduzirFalha(\Throwable $e, string $operacao): \RuntimeException { return new GatewayIndisponivelException(GatewayIndisponivelException::MENSAGEM, $e); }
   protected function codigoHttp(\Throwable $e): ?int { return null; }
   protected function nomeDoGateway(): string { return (new \ReflectionClass($this))->getShortName(); }

   /** URL de notificação montada do próprio site; null quando o segredo do webhook não está configurado. */
   protected function urlDeNotificacao(string $variavelDoSegredo, string $rota): ?string
   {
       $segredo = trim((string) ($_ENV[$variavelDoSegredo] ?? ''));

       return $segredo === '' ? null : site_url($rota);
   }
   ```
   Nada é logado além desses campos (a mensagem do erro **não** entra: pode conter dado do pagador).
3. **`MercadoPago`**: `nomeDoGateway()` → `'MercadoPago'`. `codigoHttp()` → `$e->getStatusCode()` quando `$e instanceof MPApiException` (conferir o nome do getter no SDK instalado; no 3.x é `getStatusCode()`), senão `null`. `traduzirFalha()`: `MPApiException` com status 400–499 exceto 401/403/429 → `new GatewayRecusouException($this->mpErrorMessage($e, $operacao), $e)`; demais (`MPApiException` 401/403/429/5xx, `MPException`, outros) → `GatewayIndisponivelException`. `mpErrorMessage(\Throwable $e, string $operacao): string` passa a devolver **só textos nossos**: 404 → `Cobrança não encontrada no Mercado Pago.`; operação `criar_boleto` → `O Mercado Pago recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.`; outras → `O Mercado Pago recusou a operação. Confira os dados e tente novamente.` Nenhum `catch` de `MPApiException` pode fazer `new \Exception(<array>)`.
4. Operações e nomes de `op` (usados no log): `criar_boleto` (`gerarCobrancaBoleto`, `cobranca=-`), `cancelar`, `atualizar`, `confirmar` (com o id da cobrança). Cada chamada ao `PaymentClient` (`create`, `get`, `cancel`, `capture`) vai dentro de `$this->chamarGateway('<op>', $id, fn () => ...)`. As validações locais que já existem (`OS ou venda não existe!`, `Valor inválido!`, `errosCadastro`) continuam como estão (não são falha de gateway). O "estado que não permite captura" vira `GatewayRecusouException('O pagamento não está em um estado que permite captura.')` (sem o status do provedor na mensagem).
5. **`dadosDoBoleto(object $entity, $id, string $tipo, float $valor, string $documento, string $expiracao): array`** (`protected`): extrai o array hoje montado inline em `gerarCobrancaBoleto()` (sem mudar os demais campos), com `description` = `$tipo === PaymentGateway::PAYMENT_TYPE_OS ? "OS #$id" : "Venda #$id"` e `notification_url` só quando `$this->urlDeNotificacao('MP_WEBHOOK_SECRET', 'webhooks/mercadopago')` não for `null`. Use `(string) ($entity->campo ?? '')` nas leituras que vão para `explode`/`preg_replace` (sem `Deprecated` de `null`).
6. **Timeout**: em `payment_gateways.php`, bloco `MercadoPago`, `'timeout' => ((int) ($_ENV['PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT'] ?? 0)) > 0 ? (int) $_ENV['PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT'] : 10`. No construtor do `MercadoPago`, aplicar o valor ao cliente HTTP do SDK. Ler `application/vendor/mercadopago/dx-php/src/MercadoPago/MercadoPagoConfig.php` e `Net/MPDefaultHttpClient.php`/`Net/CurlRequest.php`: se o SDK expõe timeout (ex.: `MercadoPagoConfig::setConnectionTimeout()` ou opção do `RequestOptions`), usar; **se não expõe**, criar o cliente com `CURLOPT_CONNECTTIMEOUT` e `CURLOPT_TIMEOUT` = `timeout` e registrá-lo por `MercadoPagoConfig::setHttpClient()` (implementação de `MercadoPago\Net\MPHttpClient` dentro de `MercadoPago.php` ou em `application/libraries/Gateways/MercadoPagoHttpClient.php` — se criar o arquivo, declará-lo no retorno). O critério é o **timeout efetivo** lido do cliente configurado, não o mecanismo. Isolar a configuração em `protected function configurarTimeout(int $segundos): void` para o teste chamar.
7. `application/.env.example`: no bloco do Mercado Pago, `PAYMENT_GATEWAYS_MERCADO_PAGO_TIMEOUT=10` (comentário: `# Tempo máximo, em segundos, para conectar e receber a resposta do Mercado Pago.`) e `MP_WEBHOOK_SECRET=` (comentário: `# Assinatura secreta do webhook (painel do Mercado Pago > Webhooks). Vazio: cobranças sem notification_url e webhook recusando tudo.`).

**Contrato com camadas adjacentes** (consumido pela task do Asaas/Efí e pela de Cobranças):

```
Produz para: Cobrancas.php / webhook
  - Recusa do provedor (dado inválido, cobrança inexistente): Libraries\Gateways\Exceptions\GatewayRecusouException (mensagem pt-BR segura para exibir)
  - Falha técnica (timeout, rede, 5xx, credencial): Libraries\Gateways\Exceptions\GatewayIndisponivelException (mensagem GatewayIndisponivelException::MENSAGEM)
  - Validação local já existente (OS inexistente, valor <= 0, cadastro incompleto): \Exception como hoje
Consome de: SDK mercadopago/dx-php
  - Detecta recusa: MPApiException com status 400–499 exceto 401/403/429
  - Detecta indisponibilidade: MPApiException 401/403/429/5xx, MPException, qualquer outro Throwable
```

```php
// Libraries\Gateways\BasePaymentGateway — assinaturas fixas
protected function chamarGateway(string $operacao, $idCobranca, callable $chamada);
protected function registrarFalha(string $operacao, $idCobranca, ?int $http, float $inicio, \Throwable $erro): void;
protected function traduzirFalha(\Throwable $e, string $operacao): \RuntimeException;
protected function codigoHttp(\Throwable $e): ?int;
protected function nomeDoGateway(): string;
protected function urlDeNotificacao(string $variavelDoSegredo, string $rota): ?string;
```
