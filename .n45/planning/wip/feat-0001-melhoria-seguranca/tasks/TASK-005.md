---
checkpoint: null
complexity: G
created_at: "2026-11-03 15:59:11"
criteria:
    - done: false
      test: application/vendor/bin/phpunit --filter testRecusaDoAsaasNaoRepassaOTextoDoProvedor tests/Unit/GatewayAsaasEfiTest.php
      text: Asaas com errors na criação do boleto lança GatewayRecusouException com a mensagem nossa e não repassa a descrição do provedor nem na mensagem nem no log
    - done: false
      test: application/vendor/bin/phpunit --filter AsaasIndisponivel tests/Unit/GatewayAsaasEfiTest.php
      text: Asaas com retorno falso ou exceção do SDK lança GatewayIndisponivelException e loga 'gateway op=<op> gateway=Asaas cobranca=<id>'
    - done: false
      test: '! grep -nE ''\$error->description'' application/libraries/Gateways/Asaas.php'
      text: O Asaas não monta mais mensagem de erro com a descrição do provedor
    - done: false
      test: application/vendor/bin/phpunit --filter Efi tests/Unit/GatewayAsaasEfiTest.php
      text: Efí com EfiException 400 lança GatewayRecusouException; 401/403/429/5xx e code != 200 lançam GatewayIndisponivelException; log traz http=<código>
    - done: false
      test: application/vendor/bin/phpunit --filter NotificationUrl tests/Unit/GatewayAsaasEfiTest.php
      text: Com EFI_WEBHOOK_SECRET a notification_url do boleto e do link é site_url('webhooks/efi/<segredo>'); sem ele o corpo vai sem metadata
    - done: false
      test: '! grep -nE ''mapos\.com\.br|seusite\.com\.br'' application/libraries/Gateways/GerencianetSdk.php application/libraries/Gateways/Asaas.php'
      text: Nenhum domínio fixo de terceiro permanece nos gateways Asaas e Efí
    - done: false
      test: application/vendor/bin/phpunit --filter TimeoutDoConfig tests/Unit/GatewayAsaasEfiTest.php
      text: 'payment_gateways.php: Efí com timeout padrão 10 e Asaas com timeout lido de PAYMENT_GATEWAYS_ASAAS_TIMEOUT (padrão 10 para ausente, vazio, 0 ou não numérico)'
    - done: false
      test: application/vendor/bin/phpunit --filter TimeoutDoCliente tests/Unit/GatewayAsaasEfiTest.php
      text: O cliente HTTP usado pelo SDK do Asaas fica configurado com o timeout informado (20 s lido de volta)
    - done: false
      test: application/vendor/bin/phpunit --filter Descricao tests/Unit/GatewayAsaasEfiTest.php
      text: 'Descrição da cobrança é ''OS #<id>'' para OS e ''Venda #<id>'' para venda no Asaas e na Efí'
    - done: false
      test: grep -qx 'PAYMENT_GATEWAYS_EFI_TIMEOUT=10' application/.env.example && grep -qx 'PAYMENT_GATEWAYS_ASAAS_TIMEOUT=10' application/.env.example && grep -qx 'EFI_WEBHOOK_SECRET=' application/.env.example
      text: .env.example tem PAYMENT_GATEWAYS_EFI_TIMEOUT=10, PAYMENT_GATEWAYS_ASAAS_TIMEOUT=10 e EFI_WEBHOOK_SECRET vazio
    - done: false
      test: '! grep -nE ''log_message\([^)]*EFI_WEBHOOK_SECRET'' application/libraries/Gateways/GerencianetSdk.php'
      text: O segredo do webhook da Efí não é logado
    - done: false
      test: grep -cE 'assertSame\(.(O Asaas|A Efí|O provedor)' tests/Unit/GatewayAsaasEfiTest.php | grep -qvx 0
      text: Os testes conferem a mensagem exata das exceções, não só o tipo
deps:
    - TASK-004
files:
    - application/libraries/Gateways/Asaas.php
    - application/libraries/Gateways/GerencianetSdk.php
    - application/config/payment_gateways.php
    - application/.env.example
    - tests/Unit/GatewayAsaasEfiTest.php
id: TASK-005
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
tests: application/vendor/bin/phpunit tests/Unit/GatewayAsaasEfiTest.php
title: 'Asaas e Efí: exceções tipadas, log, timeout e notification_url'
updated_at: "2026-11-03 15:59:11"
---
## Contexto

A task anterior criou a base comum de erros dos gateways e aplicou ao Mercado Pago. Esta task aplica **o mesmo contrato** ao **Asaas** (`application/libraries/Gateways/Asaas.php`, SDK `codephix/asaas-sdk` v2.0.12) e à **Efí/Gerencianet** (`application/libraries/Gateways/GerencianetSdk.php`, SDK `efipay/sdk-php-apis-efi` 1.17.0), e corrige a `notification_url` da Efí, que hoje é fixa em `http://mapos.com.br/` (domínio de terceiro) nos dois pontos (`gerarCobrancaBoleto` → `metadata.notification_url` e `gerarCobrancaLink` → `$metadata`).

O que já existe (produzido pela task do Mercado Pago, **consumir sem alterar a assinatura**):

```php
// Libraries\Gateways\BasePaymentGateway
protected function chamarGateway(string $operacao, $idCobranca, callable $chamada);   // executa; falha → log + GatewayRecusou/Indisponivel
protected function traduzirFalha(\Throwable $e, string $operacao): \RuntimeException;  // padrão: GatewayIndisponivelException
protected function codigoHttp(\Throwable $e): ?int;                                     // padrão: null
protected function nomeDoGateway(): string;                                             // padrão: nome curto da classe
protected function urlDeNotificacao(string $variavelDoSegredo, string $rota): ?string;  // null sem segredo no .env
// Libraries\Gateways\Exceptions\GatewayRecusouException (mensagem pt-BR nossa, segura para exibir)
// Libraries\Gateways\Exceptions\GatewayIndisponivelException (GatewayIndisponivelException::MENSAGEM = 'O provedor de pagamento não respondeu. Tente novamente em instantes.')
```

Log de falha produzido pelo wrapper (uma linha, nada além disso): `gateway op=<operacao> gateway=<nome> cobranca=<id|-> http=<cod|-> duracao_ms=<n> erro=<classe>`. Nunca entra token, CPF/CNPJ, segredo, corpo do provedor ou a descrição de erro do provedor. `application/controllers/Cobrancas.php` **não** muda aqui. Modelo de teste: `tests/Unit/GatewayMercadoPagoTest.php` (gateway instanciado com `makeInstance` e dependências por `setPrivateProperty`); suporte de log da `TASK-001` (`$this->logs('error')`, `assertLogContem`, `assertLogNaoContem`).

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| Asaas `Cobranca()->create()` devolve objeto com `errors = [{description: 'O CPF/CNPJ informado é inválido.'}]` | `GatewayRecusouException('O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.')`; log `gateway op=criar_boleto gateway=Asaas cobranca=- http=- ...`; o texto `O CPF/CNPJ informado é inválido.` não aparece na mensagem nem no log |
| Asaas `create()` devolve `false`/`null` (falha de chamada) | `GatewayIndisponivelException` (mensagem genérica) |
| Asaas `confirmacao()` com `errors` em `confirmarPagamento(9)` | `GatewayRecusouException('O Asaas recusou a operação. Confira os dados e tente novamente.')`; log com `op=confirmar cobranca=9` |
| Asaas SDK lança exceção/erro de cURL (timeout) em `atualizarDados(9)` | `GatewayIndisponivelException`; log `op=atualizar cobranca=9` |
| Efí `createOneStepCharge()` lança `EfiException` com código 400 | `GatewayRecusouException('A Efí recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.')`; log `gateway op=criar_boleto gateway=GerencianetSdk cobranca=- http=400 ...` |
| Efí lança `EfiException` 401/403/429/5xx ou exceção de rede | `GatewayIndisponivelException` |
| Efí responde `['code' => 500, ...]` sem lançar (`detailCharge` em `atualizarDados(4)`) | `GatewayIndisponivelException`; log `op=atualizar cobranca=4 http=500` |
| `EFI_WEBHOOK_SECRET` vazio/ausente e `gerarCobrancaBoleto` | corpo enviado ao SDK **sem** `metadata` (nem `notification_url`) |
| `EFI_WEBHOOK_SECRET=s3cr3t` e `gerarCobrancaBoleto` | `body['metadata']['notification_url'] === site_url('webhooks/efi/s3cr3t')` |
| Mesmos dois casos em `gerarCobrancaLink` | idem no `metadata` do link |
| `payment_gateways.php` sem `PAYMENT_GATEWAYS_EFI_TIMEOUT` | `['GerencianetSdk']['timeout'] === 10` (era 30) |
| `PAYMENT_GATEWAYS_ASAAS_TIMEOUT` ausente / `''` / `0` / `abc` | `['Asaas']['timeout'] === 10`; com `20` → `20` |
| `Asaas` construído com `timeout = 20` | o cliente HTTP usado pelo SDK fica com conexão e resposta de 20 s (lido de volta) |
| Asaas e Efí: descrição da cobrança | `OS #<id>` para OS e `Venda #<id>` para venda (já correto hoje; manter e cobrir com teste) |

## TDD

Testes novos em `tests/Unit/GatewayAsaasEfiTest.php` (`final class GatewayAsaasEfiTest extends MaposTestCase`). Substituir o cliente do SDK (`asaasApi` no Asaas, `gerenciaNetApi` na Efí) por **dublês em classe anônima** que gravam o corpo recebido e devolvem/lançam o que o teste quer; o `ci` recebe `Os_model`/`vendas_model`/`cobrancas_model` falsos (ver `tests/Unit/CobrancaValorTest.php`). Estilo:

```php
public function testNotificationUrlDaEfiSoComSegredoEApontaParaOProprioSite(): void
{
    $_ENV['EFI_WEBHOOK_SECRET'] = 's3cr3t';
    [$gateway, $sdk] = $this->efiComCobrancaDeOs(12);

    $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, 'os']);

    $this->assertSame('http://mapos.test/index.php/webhooks/efi/s3cr3t', $sdk->corpos['createOneStepCharge']['metadata']['notification_url']);
}

public function testRecusaDoAsaasNaoRepassaOTextoDoProvedor(): void
{
    [$gateway] = $this->asaasQueRecusa('O CPF/CNPJ informado é inválido.');

    try {
        $this->invokeMethod($gateway, 'gerarCobrancaBoleto', [12, 'os']);
        $this->fail('Deveria recusar.');
    } catch (GatewayRecusouException $e) {
        $this->assertSame('O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.', $e->getMessage());
    }
    $this->assertLogNaoContem('CPF/CNPJ informado');
}
```

Cobrir os demais casos da tabela; restaurar `$_ENV` no `tearDown`. `site_url()` definido no topo do teste com `if (! function_exists('site_url'))` devolvendo `'http://mapos.test/index.php/' . ltrim($uri, '/')`.

**Refatoração:** depois do verde, unificar nos dois gateways a montagem de `OS #<id>`/`Venda #<id>` num método privado por classe, se houver repetição.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/libraries/Gateways/Asaas.php` | Modificar | Chamadas pelo `chamarGateway()`; `errors` → `GatewayRecusouException`; `traduzirFalha()`; timeout |
| `application/libraries/Gateways/GerencianetSdk.php` | Modificar | Chamadas pelo `chamarGateway()`; `code !== 200` e `EfiException` traduzidos; `notification_url` por `urlDeNotificacao()` |
| `application/config/payment_gateways.php` | Modificar | Blocos `GerencianetSdk` (timeout padrão 10) e `Asaas` (chave `timeout`) |
| `application/.env.example` | Modificar | `PAYMENT_GATEWAYS_EFI_TIMEOUT=10`, `PAYMENT_GATEWAYS_ASAAS_TIMEOUT=10`, `EFI_WEBHOOK_SECRET=` |
| `tests/Unit/GatewayAsaasEfiTest.php` | Criar | Testes da tabela |

### Detalhamento Técnico

1. **Operações (`op` do log):** `criar_boleto`, `criar_link`, `criar_cliente` (Asaas `criarOuRetornarClienteAsaasId`), `cancelar`, `atualizar`, `confirmar`. Cada chamada ao SDK vai dentro de `$this->chamarGateway('<op>', $idCobrancaOuNull, fn () => ...)`. Validações locais (`Cobrança não existe!`, `OS ou venda não existe!`, valor ≤ 0, `errosCadastro`, "Devido à limitação da Asaas...") continuam `\Exception` como hoje — não são falha de provedor.
2. **Asaas**: o SDK não lança em erro de validação; ele devolve o objeto com `errors`. Dentro da closure passada ao `chamarGateway`, conferir o retorno: `errors` não vazio → `throw new GatewayRecusouException(<mensagem da operação>)` (`criar_boleto`/`criar_link`/`criar_cliente` → `O Asaas recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.`; demais → `O Asaas recusou a operação. Confira os dados e tente novamente.`); retorno falso/nulo → `throw new GatewayIndisponivelException()`. O wrapper repassa as duas sem traduzir e loga. `nomeDoGateway()` → `'Asaas'`.
3. **Asaas timeout**: `'timeout' => ((int) ($_ENV['PAYMENT_GATEWAYS_ASAAS_TIMEOUT'] ?? 0)) > 0 ? (int) $_ENV['PAYMENT_GATEWAYS_ASAAS_TIMEOUT'] : 10`. Ler o SDK instalado (`application/vendor/codephix/asaas-sdk/src/`, classe de conexão usada por `CodePhix\Asaas\Asaas`) para achar onde o cURL é montado. Se houver opção de timeout, usar; se não houver, estender a classe de conexão do SDK aplicando `CURLOPT_CONNECTTIMEOUT` e `CURLOPT_TIMEOUT` e injetá-la no `AsaasSdk` (se precisar de arquivo novo, `application/libraries/Gateways/AsaasConexao.php`, declarado no retorno). Isolar em `protected function configurarTimeout(int $segundos): void`; o teste lê o valor de volta do cliente configurado. O critério é o timeout **efetivo**, não o mecanismo.
4. **Efí**: `nomeDoGateway()` → `'GerencianetSdk'`. `codigoHttp()` → `(int) $e->getCode()` quando `$e instanceof \Efi\Exception\EfiException` (conferir o nome da classe no SDK instalado). `traduzirFalha()`: `EfiException` com código 400–499 exceto 401/403/429 → `GatewayRecusouException` (`criar_boleto`/`criar_link` → `A Efí recusou os dados da cobrança. Confira CPF/CNPJ, e-mail e endereço do cliente.`; demais → `A Efí recusou a operação. Confira os dados e tente novamente.`); resto → `GatewayIndisponivelException`. Onde o código confere `intval($result['code']) !== 200`, dentro da closure: `throw new GatewayIndisponivelException()` e o `http` do log sai do `code` da resposta — para isso, a closure lança uma exceção que carregue o código (ex.: `new \RuntimeException('efi code', (int) $result['code'])` traduzida por `traduzirFalha` usando `getCode()`), mantendo o log `http=500`.
5. **`notification_url` da Efí**: nos dois pontos, `$url = $this->urlDeNotificacao('EFI_WEBHOOK_SECRET', 'webhooks/efi/' . trim((string) ($_ENV['EFI_WEBHOOK_SECRET'] ?? '')));` e só incluir `metadata` (`['notification_url' => $url]`) no corpo quando `$url !== null`. O segredo **nunca** vai para log.
6. **Efí timeout**: em `payment_gateways.php`, `'timeout' => ((int) ($_ENV['PAYMENT_GATEWAYS_EFI_TIMEOUT'] ?? 0)) > 0 ? (int) $_ENV['PAYMENT_GATEWAYS_EFI_TIMEOUT'] : 10` (o construtor já repassa `timeout` ao `EfiPay`).
7. `application/.env.example`: trocar `PAYMENT_GATEWAYS_EFI_TIMEOUT=30` por `10`; acrescentar `PAYMENT_GATEWAYS_ASAAS_TIMEOUT=10` no bloco do Asaas e `EFI_WEBHOOK_SECRET=` no bloco da Efí (comentário: `# Segredo do webhook da Efí, parte da URL cadastrada (webhooks/efi/<segredo>). Vazio: cobranças sem notification_url e webhook recusando tudo.`). Não mexer nas linhas do Mercado Pago.
8. Leituras de `$entity->...` que vão para `preg_replace`/`strlen`: `(string) ($entity->campo ?? '')` (sem `Deprecated` de `null`).

**Contrato com camadas adjacentes:**

```
Produz para: Cobrancas.php (task de Cobranças) e webhook
  - Recusa do provedor: GatewayRecusouException (mensagem pt-BR nossa)
  - Falha técnica/timeout/5xx/credencial: GatewayIndisponivelException (GatewayIndisponivelException::MENSAGEM)
  - Validação local: \Exception como hoje
Consome de: Asaas SDK — retorno com errors (recusa) | retorno falso (indisponível) | Throwable (indisponível)
Consome de: Efí SDK — EfiException 4xx exceto 401/403/429 (recusa) | demais/código != 200 (indisponível)
```
