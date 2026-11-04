---
checkpoint: null
complexity: M
created_at: "2026-11-03 18:42:19"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter 'testMercadoPagoDevolveOFormatoComumComValorEmCentavos|testMercadoPagoArredondaCentavos' tests/Unit/GatewayConsultaPagamentoTest.php
      text: MercadoPago::consultarPagamento('123456') devolve ['charge_id' => '123456', 'status' => 'approved', 'valor_centavos' => 15090] e 0.1 vira 10 centavos
    - done: true
      test: application/vendor/bin/phpunit --filter testMercadoPagoIdNaoNumericoRecusaSemConsultar tests/Unit/GatewayConsultaPagamentoTest.php
      text: MercadoPago com id não numérico lança GatewayRecusouException('Pagamento não encontrado no Mercado Pago.') sem chamar o SDK
    - done: true
      test: application/vendor/bin/phpunit --filter testMercadoPagoFalhasDaConsulta tests/Unit/GatewayConsultaPagamentoTest.php
      text: 'MercadoPago: MPApiException 404 vira GatewayRecusouException e 500 vira GatewayIndisponivelException, com log ''op=consultar_webhook gateway=MercadoPago'''
    - done: true
      test: application/vendor/bin/phpunit --filter testEfiConsultaANotificacaoEDetalhaACobranca tests/Unit/GatewayConsultaPagamentoTest.php
      text: 'Efí: notificação com charge_id 111 + detailCharge {status paid, total 5000} devolve [''charge_id'' => ''111'', ''status'' => ''paid'', ''valor_centavos'' => 5000] e o SDK recebe token e id corretos'
    - done: true
      test: application/vendor/bin/phpunit --filter testEfiSemChargeIdNaNotificacaoRecusaSemDetalhar tests/Unit/GatewayConsultaPagamentoTest.php
      text: 'Efí: notificação sem charge_id lança GatewayRecusouException(''Notificação da Efí sem cobrança.'') sem chamar detailCharge'
    - done: true
      test: application/vendor/bin/phpunit --filter testEfiIndisponivelNaConsulta tests/Unit/GatewayConsultaPagamentoTest.php
      text: 'Efí: getNotification com code 500 lança GatewayIndisponivelException e loga ''op=consultar_webhook gateway=GerencianetSdk'' com http=500'
    - done: true
      test: application/vendor/bin/phpunit --filter testAsaas tests/Unit/GatewayConsultaPagamentoTest.php
      text: Asaas::consultarPagamento('pay_9') devolve ['charge_id' => 'pay_9', 'status' => 'RECEIVED', 'valor_centavos' => 15090]; errors → Recusou; false → Indisponivel
    - done: true
      test: application/vendor/bin/phpunit --filter testStatusPago tests/Unit/GatewayConsultaPagamentoTest.php
      text: statusPago() aceita só os status pagos de cada gateway (paid/settled, approved, RECEIVED/CONFIRMED/RECEIVED_IN_CASH/DUNNING_RECEIVED), com comparação exata e gateway desconhecido = false
    - done: true
      test: application/vendor/bin/phpunit --filter testLogDaConsultaNaoTemTokenNemId tests/Unit/GatewayConsultaPagamentoTest.php
      text: O log da consulta não contém o token da notificação nem o id do pagamento
    - done: true
      test: '! grep -nE ''consultarPagamento[^\n]*(curl_|file_get_contents|http://|https://)'' application/libraries/Gateways/MercadoPago.php application/libraries/Gateways/GerencianetSdk.php application/libraries/Gateways/Asaas.php'
      text: A consulta usa só o SDK (sem URL montada a partir de entrada) nos três gateways
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/GatewayMercadoPagoTest.php tests/Unit/GatewayAsaasEfiTest.php
      text: As suítes de gateway existentes continuam verdes
deps:
    - TASK-005
diff_base: 7cc099b143f25fe3e377acf9df266dc9cc04c8ae
diff_tip: a5c8f783a5fa6ac3a9122bb936a3a071b4a83967
files:
    - application/libraries/Gateways/BasePaymentGateway.php
    - application/libraries/Gateways/MercadoPago.php
    - application/libraries/Gateways/GerencianetSdk.php
    - application/libraries/Gateways/Asaas.php
    - tests/Unit/GatewayConsultaPagamentoTest.php
id: TASK-010
linter: composer analyse
n45_version: 1.1.0
outcome: |
    **Backend — consulta de pagamento nos gateways (para o webhook)**

    - `BasePaymentGateway::STATUS_PAGOS` (GerencianetSdk: paid/settled · MercadoPago: approved · Asaas: RECEIVED/CONFIRMED/RECEIVED_IN_CASH/DUNNING_RECEIVED) e `BasePaymentGateway::statusPago(string $gateway, string $status): bool` (comparação estrita; gateway desconhecido = false).
    - `consultarPagamento(string $idExterno): array` público em `MercadoPago`, `GerencianetSdk` e `Asaas` (fora da interface `PaymentGateway`), retorno `['charge_id' => string, 'status' => string, 'valor_centavos' => int]`.
      - `$idExterno`: MP = `data.id` (não numérico → `GatewayRecusouException`); Efí = token da notificação (getNotification → último `identifiers.charge_id` → detailCharge; sem cobrança → `GatewayRecusouException('Notificação da Efí sem cobrança.')`); Asaas = `payment.id`.
      - Recusa do provedor → `GatewayRecusouException`; falha técnica/5xx/rede/code≠200 → `GatewayIndisponivelException`. Log `op=consultar_webhook cobranca=-`, sem token nem id.
    - `MercadoPago::buscarPagamentoMp(int $id): object` protected (ponto de substituição em teste).
persona: backend
phase: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/GatewayConsultaPagamentoTest.php tests/Unit/GatewayMercadoPagoTest.php tests/Unit/GatewayAsaasEfiTest.php
title: Consulta de pagamento nos gateways para o webhook
updated_at: "2026-11-03 19:46:57"
---
## Contexto

Os webhooks de pagamento (task seguinte) **nunca confiam no corpo da notificação**: depois de autenticar o gateway, reconsultam o pagamento na API do provedor, pelo SDK e no host fixo do provedor, e usam só o retorno da consulta. Esta task cria essa consulta nos três gateways, com **um único formato de retorno**, mais o conjunto de status "pago" que a baixa usa.

O que já existe e deve ser consumido sem mudar a assinatura (entregue na fase anterior):

```php
// Libraries\Gateways\BasePaymentGateway (application/libraries/Gateways/BasePaymentGateway.php)
protected function chamarGateway(string $operacao, $idCobranca, callable $chamada);  // falha → log "gateway op=... gateway=... cobranca=... http=... duracao_ms=... erro=<classe>" + GatewayRecusou/Indisponivel
protected function traduzirFalha(\Throwable $e, string $operacao): \RuntimeException;
// Libraries\Gateways\Exceptions\GatewayRecusouException      — recusa do provedor (ex.: MP 404 → 'Cobrança não encontrada no Mercado Pago.')
// Libraries\Gateways\Exceptions\GatewayIndisponivelException — falha técnica/timeout; MENSAGEM = 'O provedor de pagamento não respondeu. Tente novamente em instantes.'
```

- `MercadoPago` (`application/libraries/Gateways/MercadoPago.php`): usa `new PaymentClient()` inline; `traduzirFalha` já mapeia `MPApiException` 4xx (exceto 401/403/429) para Recusou.
- `GerencianetSdk` (`application/libraries/Gateways/GerencianetSdk.php`): `private function chamarEfi(string $operacao, $idCobranca, callable $chamada)` já transforma `code != 200` em Indisponivel; cliente do SDK em `private $gerenciaNetApi` (`Efi\EfiPay`), com os métodos `getNotification(['token' => $t])` e `detailCharge(['id' => $id], [])`.
- `Asaas` (`application/libraries/Gateways/Asaas.php`): `private function chamarAsaas(string $operacao, $idCobranca, callable $chamada)` já confere `errors`/falso; cliente em `private $asaasApi` com `->Cobranca()->getById($id)`.
- Valor gravado em `cobrancas.total` é em **centavos** (string, ex. `'15090'`). `cobrancas.charge_id` guarda o id do pagamento no provedor; `cobrancas.payment_gateway` vale `'GerencianetSdk'`, `'MercadoPago'` ou `'Asaas'`.
- Testes de gateway seguem `tests/Unit/GatewayAsaasEfiTest.php` / `GatewayMercadoPagoTest.php`: gateway por `makeInstance()`, SDK trocado por dublê de classe anônima via `setPrivateProperty`, logs por `$this->logs('error')` / `assertLogContem`.

Fora de escopo: o controller de webhook, gravação em banco, mudança de `atualizarDados()`/`confirmarPagamento()`.

## Comportamento Esperado

Retorno comum de `consultarPagamento()`: `['charge_id' => string, 'status' => string, 'valor_centavos' => int]`.

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `MercadoPago::consultarPagamento('123456')`, provedor devolve `{id: 123456, status: 'approved', transaction_amount: 150.9}` | `['charge_id' => '123456', 'status' => 'approved', 'valor_centavos' => 15090]` |
| MP com `transaction_amount: 0.1` | `valor_centavos = 10` (arredondado, nunca truncado para 9) |
| `MercadoPago::consultarPagamento('abc')` (id não numérico) | `GatewayRecusouException('Pagamento não encontrado no Mercado Pago.')`, sem chamar o SDK |
| MP lança `MPApiException` 404 | `GatewayRecusouException` (mensagem do mapeamento existente); log `gateway op=consultar_webhook gateway=MercadoPago cobranca=- http=404` |
| MP lança `MPApiException` 500 ou erro de rede | `GatewayIndisponivelException`; log com `op=consultar_webhook` |
| `GerencianetSdk::consultarPagamento('tok-1')`: `getNotification` devolve `code 200, data: [{identifiers: {charge_id: 111}, status: {current: 'waiting'}}, {identifiers: {charge_id: 111}, status: {current: 'paid'}}]` e `detailCharge(111)` devolve `code 200, data: {charge_id: 111, status: 'paid', total: 5000}` | `['charge_id' => '111', 'status' => 'paid', 'valor_centavos' => 5000]`; `getNotification` recebeu `['token' => 'tok-1']` e `detailCharge` recebeu `['id' => 111]` |
| Efí: histórico sem `identifiers.charge_id` em nenhum item | `GatewayRecusouException('Notificação da Efí sem cobrança.')`, sem chamar `detailCharge` |
| Efí: `getNotification` responde `code 500` | `GatewayIndisponivelException`; log `op=consultar_webhook gateway=GerencianetSdk ... http=500` |
| `Asaas::consultarPagamento('pay_9')`, `Cobranca()->getById('pay_9')` devolve `{id: 'pay_9', status: 'RECEIVED', value: 150.9}` | `['charge_id' => 'pay_9', 'status' => 'RECEIVED', 'valor_centavos' => 15090]` |
| Asaas devolve objeto com `errors` | `GatewayRecusouException`; devolve `false` → `GatewayIndisponivelException` |
| `BasePaymentGateway::statusPago('MercadoPago', 'approved')` | `true` |
| `statusPago('GerencianetSdk', 'settled')`, `statusPago('Asaas', 'RECEIVED_IN_CASH')`, `statusPago('Asaas', 'DUNNING_RECEIVED')` | `true` |
| `statusPago('MercadoPago', 'pending')`, `statusPago('Asaas', 'PENDING')`, `statusPago('Outro', 'paid')` | `false` |
| `statusPago('GerencianetSdk', 'PAID')` | `false` (comparação exata, o provedor usa minúsculas) |

## TDD

Arquivo novo `tests/Unit/GatewayConsultaPagamentoTest.php` (`final class GatewayConsultaPagamentoTest extends MaposTestCase`), com os mesmos stubs de `site_url`/`log_info` e `require_once` dos três gateways que os testes de gateway existentes usam. Para o MP, o SDK é criado inline: a consulta passa por um método `protected` substituível (ver Detalhamento) e o teste usa uma **subclasse anônima** que o sobrescreve:

```php
public function testMercadoPagoDevolveOFormatoComumComValorEmCentavos(): void
{
    $gateway = new class() extends MercadoPago {
        public function __construct()
        {
        }

        protected function buscarPagamentoMp(int $id): object
        {
            return (object) ['id' => $id, 'status' => 'approved', 'transaction_amount' => 150.9];
        }
    };

    $this->assertSame(
        ['charge_id' => '123456', 'status' => 'approved', 'valor_centavos' => 15090],
        $gateway->consultarPagamento('123456')
    );
}

public function testEfiSemChargeIdNaNotificacaoRecusaSemDetalhar(): void
{
    [$gateway, $sdk] = $this->efi(fn (string $metodo) => $metodo === 'getNotification'
        ? ['code' => 200, 'data' => [['status' => ['current' => 'paid']]]]
        : throw new LogicException('detailCharge não deveria ser chamado'));

    $this->expectException(GatewayRecusouException::class);
    $this->expectExceptionMessage('Notificação da Efí sem cobrança.');
    $gateway->consultarPagamento('tok-1');
}
```

Cobrir os demais casos da tabela com os nomes fixados nos critérios.

**Refatoração:** Nenhuma.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/libraries/Gateways/BasePaymentGateway.php` | Modificar | constante `STATUS_PAGOS` e `public static function statusPago(string $gateway, string $status): bool` |
| `application/libraries/Gateways/MercadoPago.php` | Modificar | `consultarPagamento()` + `protected function buscarPagamentoMp(int $id): object` |
| `application/libraries/Gateways/GerencianetSdk.php` | Modificar | `consultarPagamento()` (notificação → `charge_id` → `detailCharge`) |
| `application/libraries/Gateways/Asaas.php` | Modificar | `consultarPagamento()` |
| `tests/Unit/GatewayConsultaPagamentoTest.php` | Criar | testes da tabela |

### Detalhamento Técnico

1. **Contrato fixado (consumido pelo controller de webhook — não renomear):**

```php
// Libraries\Gateways\BasePaymentGateway
public const STATUS_PAGOS = [
    'GerencianetSdk' => ['paid', 'settled'],
    'MercadoPago' => ['approved'],
    'Asaas' => ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED'],
];
public static function statusPago(string $gateway, string $status): bool; // in_array estrito

// Em MercadoPago, GerencianetSdk e Asaas (método público, não entra na interface PaymentGateway):
/** @return array{charge_id: string, status: string, valor_centavos: int} @throws GatewayRecusouException|GatewayIndisponivelException */
public function consultarPagamento(string $idExterno): array;
```

   O `$idExterno` é: MP → `data.id` do pagamento; Efí → o **token da notificação** (`notification` do POST); Asaas → `payment.id`.

2. **Operação de log**: todas as chamadas usam `$operacao = 'consultar_webhook'` e `$idCobranca = null` (o id local ainda não é conhecido) — o log sai `cobranca=-`. Nunca logar o token da notificação, o id do pagamento nem o corpo.

3. **Valor em centavos**: `(int) round(((float) $valor) * 100)`. Efí já devolve `data.total` em centavos → `(int)`.

4. **Mercado Pago**:
   - `ctype_digit($idExterno)` falso → lançar `GatewayRecusouException('Pagamento não encontrado no Mercado Pago.')` antes de qualquer chamada.
   - `$pagamento = $this->chamarGateway('consultar_webhook', null, fn () => $this->buscarPagamentoMp((int) $idExterno));`
   - `protected function buscarPagamentoMp(int $id): object { return (new PaymentClient())->get($id); }` — o host vem do SDK (`api.mercadopago.com`), nunca de entrada.

5. **Efí** — duas chamadas pelo `chamarEfi` existente:
   - `$notificacao = $this->chamarEfi('consultar_webhook', null, fn () => $this->gerenciaNetApi->getNotification(['token' => $idExterno]));`
   - `charge_id` = `identifiers.charge_id` do **último** item de `$notificacao['data']` que o tenha (percorrer de trás para frente). Nenhum → `GatewayRecusouException('Notificação da Efí sem cobrança.')`.
   - `$cobranca = $this->chamarEfi('consultar_webhook', null, fn () => $this->gerenciaNetApi->detailCharge(['id' => $chargeId], []));` → `status` = `data.status`, `valor_centavos` = `(int) data.total`. **Decisão:** o status vem do `detailCharge` (estado atual da cobrança), não do `status.current` do histórico — o histórico pode chegar fora de ordem; o `detailCharge` é a fonte da verdade e é a mesma chamada que já dá o valor.

6. **Asaas**: `$r = $this->chamarAsaas('consultar_webhook', null, fn () => $this->asaasApi->Cobranca()->getById($idExterno));` → `charge_id` = `(string) $r->id`, `status` = `(string) $r->status`, `valor_centavos` a partir de `$r->value`.

**Contrato com camadas adjacentes:**

```
Produz para: controller Webhooks (task seguinte)
  - Sucesso: ['charge_id' => string, 'status' => string, 'valor_centavos' => int]
  - Recusa do provedor (pagamento inexistente, notificação sem cobrança): GatewayRecusouException → o webhook registra como 'ignorado' e responde 200
  - Falha técnica (timeout, 5xx, rede, code != 200): GatewayIndisponivelException → o webhook responde 503 (o gateway reentrega)
Consome de: SDKs (mercadopago/dx-php 3.8.0, efipay/sdk-php-apis-efi 1.17.0, codephix/asaas-sdk v2.0.12) com o timeout já configurado
```
