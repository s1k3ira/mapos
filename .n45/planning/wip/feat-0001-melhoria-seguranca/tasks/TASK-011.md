---
checkpoint: null
complexity: G
created_at: "2026-11-03 18:44:20"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testMetodoDiferenteDePostResponde405 tests/Unit/WebhooksTest.php
      text: 'GET em webhook responde 405 com Allow: POST'
    - done: true
      test: application/vendor/bin/phpunit --filter 'testAssinaturaMpInvalidaRecusaSemConsultarNemGravar|testMpTimestampForaDaJanelaRecusa|testSegredoEfiErradoRecusa|testTokenAsaasErradoRecusa' tests/Unit/WebhooksTest.php
      text: 'Segredo Efí errado, token Asaas errado, assinatura MP de outro segredo, sem x-signature e ts a 301 s respondem 401 {status: recusado}, sem chamar a consulta e sem linha em webhook_eventos'
    - done: true
      test: application/vendor/bin/phpunit --filter testSegredoVazioRecusaTudo tests/Unit/WebhooksTest.php
      text: MP_WEBHOOK_SECRET vazio responde 401 mesmo com assinatura calculada com a string vazia e loga 'webhook desabilitado gateway=mercadopago'
    - done: true
      test: test "$(grep -c 'hash_equals' application/controllers/Webhooks.php)" -ge 3
      text: A comparação de segredo, token e assinatura usa hash_equals nos três gateways
    - done: true
      test: application/vendor/bin/phpunit --filter testPagamentoConfirmadoBaixaOLancamentoDaOs tests/Unit/WebhooksTest.php
      text: 'MP assinado com ts em milissegundos e data.id só na query é aceito: 200 aplicado, cobrança approved, lançamento 40 com baixado = 1 e data_pagamento de hoje, evento com processado_em e resultado aplicado, uma linha em logs'
    - done: true
      test: application/vendor/bin/phpunit --filter testMesmoEventoDuasVezesConsultaUmaVezEBaixaUmaVez tests/Unit/WebhooksTest.php
      text: 'Mesmo evento duas vezes: segunda resposta 200 repetido, consulta chamada uma vez, uma única baixa'
    - done: true
      test: application/vendor/bin/phpunit --filter testEventoSemProcessadoEmEReprocessado tests/Unit/WebhooksTest.php
      text: Evento com processado_em nulo (falha anterior) é reprocessado no reenvio e termina aplicado
    - done: true
      test: application/vendor/bin/phpunit --filter testStatusDoCorpoEIgnoradoValeAConsulta tests/Unit/WebhooksTest.php
      text: Corpo dizendo approved com consulta devolvendo pending não baixa o lançamento
    - done: true
      test: application/vendor/bin/phpunit --filter testValorDivergenteNaoBaixa tests/Unit/WebhooksTest.php
      text: 'Valor consultado diferente de cobrancas.total: 200 divergente, sem baixa nem mudança de status, evento divergente e log ''webhook alerta divergencia'''
    - done: true
      test: application/vendor/bin/phpunit --filter 'testPagoNaoRegride|testSegundoEventoNaoAlteraDataDePagamento' tests/Unit/WebhooksTest.php
      text: Cobrança paga não regride para pendente e segundo evento distinto não altera data_pagamento (200 sem_efeito)
    - done: true
      test: application/vendor/bin/phpunit --filter testFalhaNaBaixaDesfazTudoEResponde503 tests/Unit/WebhooksTest.php
      text: 'Falha simulada no UPDATE do lançamento: 503, status da cobrança e evento revertidos (sem processado_em)'
    - done: true
      test: application/vendor/bin/phpunit --filter 'testConsultaIndisponivelResponde503|testConsultaRecusadaIgnora' tests/Unit/WebhooksTest.php
      text: Consulta lançando GatewayIndisponivelException responde 503 tente_novamente e não grava processado_em; GatewayRecusouException responde 200 ignorado
    - done: true
      test: application/vendor/bin/phpunit --filter testCobrancaDesconhecidaIgnora tests/Unit/WebhooksTest.php
      text: Cobrança desconhecida responde 200 ignorado e grava o evento com resultado ignorado e cobranca_id nulo
    - done: true
      test: application/vendor/bin/phpunit --filter 'testCorpoGrandeResponde413|testAcimaDoLimiteResponde429' tests/Unit/WebhooksTest.php
      text: Corpo acima de 65536 bytes responde 413 e acima de WEBHOOK_RATE_LIMIT responde 429 com Retry-After
    - done: true
      test: application/vendor/bin/phpunit --filter testPayloadIrrelevanteIgnoraSemConsultar tests/Unit/WebhooksTest.php
      text: MP com type diferente de payment, Asaas sem payment.id e Efí sem notification respondem 200 ignorado sem consultar
    - done: true
      test: application/vendor/bin/phpunit --filter testPagamentoDeVendaBaixaOLancamentoDaVenda tests/Unit/WebhooksTest.php
      text: Venda paga baixa o lançamento de vendas.lancamentos_id
    - done: true
      test: application/vendor/bin/phpunit --filter testRotasExistemComApiDesligada tests/Unit/WebhooksTest.php
      text: As rotas de webhook existem com API_ENABLED=false (fora do if da API)
    - done: true
      test: grep -qF "['api.*+', 'csp/report', 'webhooks/(efi/[^/]+|mercadopago|asaas)']" application/config/config.php
      text: csrf_exclude_uris exclui só as três rotas de webhook além das existentes
    - done: true
      test: '! grep -nE ''notification_url|curl_|https?://'' application/controllers/Webhooks.php'
      text: O controller não usa URL, host nem notification_url vindos do payload
    - done: true
      test: test "$(grep -cE '^\s*public function (efi|mercadopago|asaas)\(' application/controllers/Webhooks.php)" -eq 3 && test "$(grep -cE '^\s*public function ' application/controllers/Webhooks.php)" -eq 4
      text: Só os três endpoints são públicos no controller (atender e helpers protegidos)
    - done: true
      test: application/vendor/bin/phpunit --filter testLogNaoTemSegredos tests/Unit/WebhooksTest.php
      text: O log do webhook nunca contém o segredo da Efí, o token do Asaas nem o segredo do MP
    - done: true
      test: grep -qx 'ASAAS_WEBHOOK_TOKEN=' application/.env.example && grep -qx 'WEBHOOK_RATE_LIMIT=120' application/.env.example && application/vendor/bin/phpunit tests/Unit/CookieSeguroTest.php
      text: .env.example lista ASAAS_WEBHOOK_TOKEN vazio e WEBHOOK_RATE_LIMIT=120 e a suíte que faz parse do arquivo continua verde
deps:
    - TASK-002
    - TASK-003
    - TASK-010
diff_base: ce93744eb280e3c6cda3cbded5aa422f2a3642a3
diff_tip: c492bfbc8d3e4aaa1a17f9c3f985aadfdfa531ba
files:
    - application/controllers/Webhooks.php
    - application/models/Webhooks_model.php
    - application/config/routes.php
    - application/config/config.php
    - application/.env.example
    - tests/Unit/WebhooksTest.php
id: TASK-011
linter: composer analyse
n45_version: 1.1.0
outcome: |
    **Backend — webhooks de pagamento (controller público `Webhooks`, sem sessão/CSRF/API)**

    Endpoints (JSON, `Cache-Control: no-store`):
    - `POST /webhooks/efi/{segredo}` (form `notification=<token>`), `POST /webhooks/mercadopago?data.id=&type=payment` (headers `x-signature`, `x-request-id`), `POST /webhooks/asaas` (header `asaas-access-token`).
    - 200 `{status: aplicado|repetido|ignorado|divergente|sem_efeito}` · 401 `{status: recusado}` (segredo/token/assinatura inválido ou ausente) · 405 + `Allow: POST` · 413 (corpo > 65536 B) · 429 + `Retry-After` (`WEBHOOK_RATE_LIMIT`, padrão 120/min/IP, escopo `webhook`) · 503 `{status: tente_novamente}` (consulta/banco/rollback; evento fica sem `processado_em` e é reprocessado).
    - Rotas em `routes.php` fora do `if` da API; `csrf_exclude_uris` inclui `webhooks/(efi/[^/]+|mercadopago|asaas)`.

    Comportamento: autentica com `hash_equals` antes de tudo; reconsulta o pagamento via `consultarPagamento()` (corpo nunca é confiado); idempotência por `webhook_eventos(gateway, evento_id)`; valor divergente de `cobrancas.total` não baixa; pago nunca regride; baixa transacional de `os.lancamento`/`vendas.lancamentos_id` (`baixado=1`, `data_pagamento` = hoje) e auditoria em `logs` (`usuario=webhook:<gateway>`).

    Símbolos: `Webhooks_model::{buscarEvento, cobrancaPorCharge, registrarResultado, aplicarPagamento ('aplicado'|'sem_efeito'|'repetido'|'falha'), auditar}`. Env novas: `ASAAS_WEBHOOK_TOKEN`, `WEBHOOK_RATE_LIMIT` (+ `EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET` consumidas).
persona: backend
phase: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: true
    red: true
    refactor: true
tests: application/vendor/bin/phpunit tests/Unit/WebhooksTest.php tests/Unit/CookieSeguroTest.php
title: Webhooks de pagamento com autenticação, idempotência e baixa transacional
updated_at: "2026-11-03 20:35:32"
---
## Contexto

Hoje nenhum pagamento chega ao Map-OS sozinho: a cobrança só muda quando o operador clica em "Atualizar". Esta task cria os **webhooks de pagamento** de Efí, Mercado Pago e Asaas num controller público `Webhooks` (estende `CI_Controller`, **não** `MY_Controller` nem `REST_Controller`), que funciona com `API_ENABLED` ligado ou desligado, fica fora do CSRF e não usa sessão para autenticar.

Regras de segurança que guiam tudo: autenticar o gateway **antes** de qualquer consulta ou escrita (comparação em tempo constante, `hash_equals`); **nunca** confiar em status/valor/identificação do corpo — reconsultar o pagamento no provedor e usar só o retorno; idempotência por evento; baixa transacional; nenhuma transação aberta durante a chamada HTTP ao gateway.

O que já existe e esta task consome (sem alterar):

```php
// Consulta no provedor (task "Consulta de pagamento nos gateways para o webhook")
// MercadoPago / GerencianetSdk / Asaas (application/libraries/Gateways/*.php):
public function consultarPagamento(string $idExterno): array; // ['charge_id' => string, 'status' => string, 'valor_centavos' => int]
//   GatewayRecusouException      → pagamento inexistente/notificação sem cobrança
//   GatewayIndisponivelException → falha técnica/timeout
// Libraries\Gateways\BasePaymentGateway:
public static function statusPago(string $gateway, string $status): bool; // gateway = 'GerencianetSdk' | 'MercadoPago' | 'Asaas'

// Limite por IP (application/libraries/Limite_requisicoes.php), tabela api_rate_limit:
public function __construct($opcoes = null);  // ['db' => ..., 'relogio' => callable, 'segredo' => string] nos testes
public function segundosParaLiberar(string $escopo, string $ip, int $limitePorMinuto): int; // 0 = liberado
```

Schema já aplicado (migration):

```sql
webhook_eventos(id BIGINT UNSIGNED AI PK, gateway ENUM('efi','mercadopago','asaas') NOT NULL, evento_id VARCHAR(128) NOT NULL,
                cobranca_id INT NULL, payload_hash CHAR(64) NOT NULL, resultado VARCHAR(20) NULL,
                processado_em DATETIME NULL, created_at DATETIME NOT NULL)
UNIQUE webhook_eventos_gateway_evento (gateway, evento_id)
-- cobrancas: idCobranca, charge_id, status VARCHAR(36), total VARCHAR(15) em CENTAVOS ('15090'), payment_gateway, os_id, vendas_id
-- índice cobrancas_charge_gateway (charge_id(64), payment_gateway(32))
-- os.lancamento (id do lançamento da fatura da OS) · vendas.lancamentos_id (idem da venda)
-- lancamentos: idLancamentos, baixado TINYINT(1) NULL DEFAULT 0, data_pagamento DATE NULL
-- logs (trilha de auditoria): usuario, ip, tarefa, data (Y-m-d), hora (H:i:s)
```

Mapa gateway da rota → `cobrancas.payment_gateway`: `efi → GerencianetSdk`, `mercadopago → MercadoPago`, `asaas → Asaas`.

Os gateways já montam a `notification_url` própria quando o segredo existe (`site_url('webhooks/efi/<EFI_WEBHOOK_SECRET>')` e `site_url('webhooks/mercadopago')`) e o `.env.example` já tem `EFI_WEBHOOK_SECRET=` e `MP_WEBHOOK_SECRET=`. Faltam `ASAAS_WEBHOOK_TOKEN` e `WEBHOOK_RATE_LIMIT`.

**Atenção ao `.env.example`:** `tests/Unit/CookieSeguroTest.php` lê o arquivo com `parse_ini_file`; comentário com parênteses (ou `!`, `"`, `{`, `}`, `|`, `&`, `~`, `^`, `[`, `]`) quebra o parse e derruba a suíte. Escrever os comentários sem esses caracteres.

## Comportamento Esperado

Toda resposta é JSON com `Content-Type: application/json` e `Cache-Control: no-store`. Corpo de sucesso: `{"status": "aplicado" | "repetido" | "ignorado" | "divergente" | "sem_efeito"}`.

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `GET /webhooks/asaas` (qualquer gateway) | 405, header `Allow: POST`, nada lido nem gravado |
| POST acima de `WEBHOOK_RATE_LIMIT` (padrão 120) no minuto, mesmo IP | 429 com `Retry-After: <segundos>`, sem consulta |
| POST com corpo de 70 KB (> 65536 bytes) | 413 `{"status":"recusado"}` |
| `POST /webhooks/efi/errado` com `EFI_WEBHOOK_SECRET=s3cr3t` | 401 `{"status":"recusado"}`; consulta **não** chamada; `webhook_eventos` vazia |
| `POST /webhooks/asaas` com header `asaas-access-token: errado` | 401, sem consulta, sem linha |
| `POST /webhooks/mercadopago` sem `x-signature`, com assinatura de outro segredo, ou com `ts` a 301 s do relógio | 401, sem consulta, sem linha |
| `MP_WEBHOOK_SECRET` vazio e assinatura calculada com a string vazia | 401 e log `error` contendo `webhook desabilitado gateway=mercadopago` |
| Segredo ausente de qualquer gateway | 401 e log `webhook desabilitado gateway=<g>` (o segredo nunca vai ao log) |
| MP assinado certo (`ts` em ms, `data.id` só na query), `type=payment`, consulta devolve `{charge_id:'123456', status:'approved', valor_centavos:15090}`, cobrança `MercadoPago/123456` com `total='15090'` e `os_id` cuja OS tem `lancamento=40` (`baixado=0`) | 200 `aplicado`; `cobrancas.status='approved'`; `lancamentos(40).baixado=1`, `data_pagamento=<hoje>`; `webhook_eventos` com `resultado='aplicado'`, `processado_em` preenchido, `cobranca_id` da cobrança; uma linha em `logs` com `tarefa='Pagamento confirmado por webhook. Cobrança <id>'` |
| Mesmo evento MP enviado de novo | 200 `repetido`; consulta chamada **uma** vez no total; uma única baixa |
| Evento existente com `processado_em = NULL` (falha anterior) reenviado | reprocessa: consulta chamada, 200 `aplicado` |
| Corpo MP diz `"status": "approved"`, consulta devolve `pending` | 200 `sem_efeito`; `cobrancas.status='pending'` (se antes não era pago); lançamento **não** baixado |
| MP com `type != "payment"` | 200 `ignorado`, sem consulta, sem linha |
| Asaas sem `payment.id` no corpo | 200 `ignorado`, sem consulta |
| Efí sem `notification` no POST | 200 `ignorado`, sem consulta |
| Consulta devolve `charge_id` sem cobrança local daquele gateway | 200 `ignorado`; evento gravado com `resultado='ignorado'`, `processado_em` preenchido, `cobranca_id = NULL` |
| Consulta lança `GatewayRecusouException` | 200 `ignorado`; evento gravado `ignorado` |
| Consulta lança `GatewayIndisponivelException` ou qualquer outra exceção | 503 `{"status":"tente_novamente"}`; **nenhuma** linha com `processado_em` preenchido |
| `valor_centavos = 15000` e `cobrancas.total = '15090'` | 200 `divergente`; sem baixa e sem mudar status; evento `resultado='divergente'` processado; log `error` contendo `webhook alerta divergencia gateway=mercadopago cobranca=<id>` |
| Cobrança já `approved` + lançamento baixado em `2026-11-01`; novo evento distinto com consulta `approved` | 200 `sem_efeito`; `data_pagamento` continua `2026-11-01` |
| Cobrança já `approved`; evento distinto com consulta `pending` | 200 `sem_efeito`; status continua `approved` (pago nunca regride) |
| Falha no UPDATE do lançamento dentro da transação | 503; `cobrancas.status` e `webhook_eventos` revertidos (sem `processado_em`) |
| Dois POSTs simultâneos do mesmo evento: o INSERT do segundo viola o UNIQUE | rollback do segundo e 200 `repetido` |
| Venda (`vendas_id`, `vendas.lancamentos_id = 41`) paga | baixa o lançamento 41 |
| Cobrança paga sem lançamento vinculado (OS não faturada) | 200 `aplicado` só pela mudança de status |
| Rotas com `API_ENABLED=false` | `application/config/routes.php` define `webhooks/efi/(:any)`, `webhooks/mercadopago`, `webhooks/asaas` fora do `if` da API |

Log de cada requisição (uma linha, `log_message('info', ...)`; `error` para recusa/falha/divergência): `webhook gateway=<g> evento=<id> cobranca=<id|-> resultado=<aplicado|repetido|ignorado|divergente|sem_efeito|recusado|falha> http=<cod> duracao_ms=<n>`. **Nunca** logar segredo, token, assinatura, corpo bruto, o path da Efí com o segredo (se precisar da rota, `rota=webhooks/efi/***`) nem dado do pagador.

## TDD

Arquivo novo `tests/Unit/WebhooksTest.php` (`final class WebhooksTest extends MaposTestCase`), com `require_once APPPATH . 'controllers/Webhooks.php'`, `APPPATH . 'models/Webhooks_model.php'` e `APPPATH . 'libraries/Limite_requisicoes.php'`. O controller é montado com `makeInstance(Webhooks::class)` e as dependências por `setPrivateProperty`: `modelo` (um `Webhooks_model` com `$modelo->db = $this->db`), `limite` (`new Limite_requisicoes(['db' => $this->db, 'segredo' => 'x', 'relogio' => fn () => $this->agora])`), `relogio` (`fn () => $this->agora`) e `consultor` (closure dublê que conta chamadas). O núcleo testado é o método `protected atender()` via `$this->invokeMethod()`. Tabelas no `setUp()`: `webhook_eventos` (com o UNIQUE), `cobrancas`, `os`, `vendas`, `lancamentos`, `logs`, `api_rate_limit` (ver `tests/Unit/LimiteRequisicoesTest.php`). `$_ENV` de segredos salvo no `setUp()` e restaurado no `tearDown()`.

```php
public function testMesmoEventoDuasVezesConsultaUmaVezEBaixaUmaVez(): void
{
    $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
    $this->cobrancaDeOsComLancamento(chargeId: '123456', total: '15090', lancamento: 40);
    $consultas = 0;
    $this->setPrivateProperty($this->webhooks, 'consultor', function (string $gateway, string $id) use (&$consultas): array {
        $consultas++;

        return ['charge_id' => '123456', 'status' => 'approved', 'valor_centavos' => 15090];
    });
    $requisicao = $this->requisicaoMp(dataId: '123456', requestId: 'req-1', corpo: '{"id":987,"type":"payment","data":{"id":"123456"}}');

    $primeira = $this->invokeMethod($this->webhooks, 'atender', ['mercadopago', $requisicao]);
    $segunda = $this->invokeMethod($this->webhooks, 'atender', ['mercadopago', $requisicao]);

    $this->assertSame([200, ['status' => 'aplicado']], [$primeira['status'], $primeira['corpo']]);
    $this->assertSame([200, ['status' => 'repetido']], [$segunda['status'], $segunda['corpo']]);
    $this->assertSame(1, $consultas);
    $this->assertSame('1', (string) $this->db->where('idLancamentos', 40)->get('lancamentos')->row()->baixado);
}

public function testAssinaturaMpInvalidaRecusaSemConsultarNemGravar(): void
{
    $_ENV['MP_WEBHOOK_SECRET'] = 'segredo-mp';
    $this->setPrivateProperty($this->webhooks, 'consultor', fn () => $this->fail('A consulta não pode ser chamada.'));

    $resposta = $this->invokeMethod($this->webhooks, 'atender', ['mercadopago', $this->requisicaoMp(dataId: '123456', requestId: 'req-1', segredo: 'outro')]);

    $this->assertSame([401, ['status' => 'recusado']], [$resposta['status'], $resposta['corpo']]);
    $this->assertSame(0, $this->db->count_all('webhook_eventos'));
}
```

O helper `requisicaoMp()` do teste calcula a assinatura com a mesma fórmula oficial (`hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", $segredo)`). Falha no meio da transação: `CREATE TRIGGER falha_baixa BEFORE UPDATE ON lancamentos BEGIN SELECT RAISE(ABORT, 'falha simulada'); END`. Cobrir os demais casos da tabela com os nomes fixados nos critérios.

**Refatoração:** depois do green, concentrar a montagem das respostas num único método privado (`responder(int $status, ?array $corpo, array $headers = [])`) e tirar duplicação dos helpers de teste.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/controllers/Webhooks.php` | Criar | controller público: entrada HTTP, autenticação por gateway, fluxo comum |
| `application/models/Webhooks_model.php` | Criar | acesso a `webhook_eventos`, `cobrancas`, `lancamentos`, `logs`; transação da baixa |
| `application/config/routes.php` | Modificar | três rotas de webhook **fora** do `if (API_ENABLED)` |
| `application/config/config.php` | Modificar | `csrf_exclude_uris` += `'webhooks/(efi/[^/]+\|mercadopago\|asaas)'` (só isso) |
| `application/.env.example` | Modificar | `ASAAS_WEBHOOK_TOKEN=` (vazio) e `WEBHOOK_RATE_LIMIT=120`, com comentário de uma linha cada |
| `tests/Unit/WebhooksTest.php` | Criar | testes da tabela |

### Detalhamento Técnico

1. **Rotas e CSRF** (`routes.php`, antes do bloco da API):

```php
// Webhooks de pagamento: públicos, fora da API e do CSRF (ver docs/webhooks-de-pagamento.md).
$route['webhooks/efi/(:any)'] = 'webhooks/efi/$1';
$route['webhooks/mercadopago'] = 'webhooks/mercadopago';
$route['webhooks/asaas'] = 'webhooks/asaas';
```

   `config.php`: `$config['csrf_exclude_uris'] = ['api.*+', 'csp/report', 'webhooks/(efi/[^/]+|mercadopago|asaas)'];`.

2. **Controller — superfície pública só com os três endpoints.** O roteamento padrão do CodeIgniter expõe **todo método público** (`/webhooks/<metodo>`): `atender()` e os demais ficam `protected`/`private`.

```php
class Webhooks extends CI_Controller
{
    public const LIMITE_CORPO = 65536;
    public const GATEWAYS = ['efi' => 'GerencianetSdk', 'mercadopago' => 'MercadoPago', 'asaas' => 'Asaas'];

    /** @var Webhooks_model */ private $modelo;
    /** @var Limite_requisicoes */ private $limite;
    /** @var callable(): int */ private $relogio = 'time';
    /** @var (callable(string $gateway, string $idExterno): array)|null */ private $consultor = null;

    public function __construct()            // parent::__construct(); load->model('Webhooks_model'); load->library('Limite_requisicoes'); atribui modelo/limite
    public function efi($segredo = null): void
    public function mercadopago(): void
    public function asaas(): void

    /**
     * @param array{metodo: string, ip: string, corpo: ?string, headers: array<string,string>, query: array, segredo_path: ?string} $requisicao
     *        corpo null = acima de LIMITE_CORPO; headers com chaves em minúsculas
     * @return array{status: int, corpo: ?array, headers: array<string,string>}
     */
    protected function atender(string $gateway, array $requisicao): array
}
```

   Os três métodos públicos só montam `$requisicao` e emitem a resposta:
   - corpo: `$bruto = (string) file_get_contents('php://input', false, null, 0, self::LIMITE_CORPO + 1);` → `strlen($bruto) > self::LIMITE_CORPO` ? `null` : `$bruto`;
   - headers: `array_change_key_case((array) $this->input->request_headers(), CASE_LOWER)`; query: `$this->input->get()` (o PHP converte `data.id` em `data_id`); ip: `$this->input->ip_address()`; método: `strtoupper($this->input->method())`.
   - saída: `$this->output->set_status_header($r['status'])->set_content_type('application/json')->set_header('Cache-Control: no-store')`, cada header extra por `set_header("$nome: $valor")`, corpo `json_encode($r['corpo'])` (405/429 podem ir sem corpo).

3. **Ordem em `atender()`** (cada passo encerra com a resposta da tabela):
   1. método ≠ `POST` → 405 + `Allow: POST`.
   2. `$espera = $this->limite->segundosParaLiberar('webhook', $ip, (int) ($_ENV['WEBHOOK_RATE_LIMIT'] ?? 120))` > 0 → 429 + `Retry-After`.
   3. corpo `null` → 413.
   4. `autenticar($gateway, $requisicao)` falso → 401 (log `resultado=recusado`).
   5. `extrairEvento($gateway, $requisicao)` → `['evento_id' => string, 'id_externo' => string]` ou `null` (irrelevante) → 200 `ignorado`, sem consulta, sem gravação.
   6. `processar($gateway, $eventoId, $idExterno, $corpo)`.

4. **Autenticação** (todas com `hash_equals`, segredo lido por `trim((string) ($_ENV[X] ?? ''))`; vazio → `log_message('error', "webhook desabilitado gateway=<g>")` e falso):
   - `efi`: `hash_equals($segredo, (string) $requisicao['segredo_path'])`, `EFI_WEBHOOK_SECRET`.
   - `asaas`: `hash_equals($token, (string) ($headers['asaas-access-token'] ?? ''))`, `ASAAS_WEBHOOK_TOKEN`.
   - `mercadopago` (`MP_WEBHOOK_SECRET`) — o trecho que mais erra:

```php
private function assinaturaMercadoPagoValida(string $segredo, array $requisicao): bool
{
    $partes = [];
    foreach (explode(',', (string) ($requisicao['headers']['x-signature'] ?? '')) as $parte) {
        [$chave, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');
        $partes[$chave] = $valor;
    }
    $ts = $partes['ts'] ?? '';
    $v1 = $partes['v1'] ?? '';
    $requestId = (string) ($requisicao['headers']['x-request-id'] ?? '');
    if ($ts === '' || $v1 === '' || ! ctype_digit($ts) || $requestId === '') {
        return false;
    }

    $segundos = strlen($ts) > 12 ? intdiv((int) $ts, 1000) : (int) $ts; // ts pode vir em milissegundos
    if (abs(($this->relogio)() - $segundos) > 300) {
        return false;
    }

    $corpo = json_decode((string) $requisicao['corpo'], true);
    $dataId = (string) ($requisicao['query']['data_id'] ?? ($corpo['data']['id'] ?? ''));
    if (ctype_alnum($dataId)) {
        $dataId = strtolower($dataId);
    }
    $manifesto = "id:{$dataId};request-id:{$requestId};ts:{$ts};"; // ts exatamente como veio no header

    return hash_equals(hash_hmac('sha256', $manifesto, $segredo), $v1);
}
```

5. **Extração do evento:**
   - `efi`: `parse_str($corpo, $post)`; `notification` vazio → irrelevante; `evento_id = id_externo = notification`.
   - `mercadopago`: corpo JSON; `($corpo['type'] ?? $query['type'] ?? '') !== 'payment'` → irrelevante; `id_externo = data_id` (mesmo valor usado na assinatura); `evento_id = (string) ($corpo['id'] ?? $headers['x-request-id'])`.
   - `asaas`: corpo JSON; sem `payment.id` → irrelevante; `id_externo = payment.id`; `evento_id = $corpo['id'] ?? ($corpo['event'] . ':' . $corpo['payment']['id'])`.
   - `evento_id` truncado a 128 caracteres (`mb_substr`).

6. **`processar()` — fluxo comum** (transação só no passo 5, **depois** da consulta HTTP):
   1. `$evento = $this->modelo->buscarEvento($gateway, $eventoId)`; `processado_em` preenchido → 200 `repetido`.
   2. Consulta: `$this->consultor` se definido, senão `$this->load->library('Gateways/' . self::GATEWAYS[$gateway], null, 'gatewayDoWebhook')` e `->consultarPagamento($idExterno)`. `GatewayRecusouException` → grava `ignorado` (passo 3) e 200; qualquer outro `\Throwable` → log `resultado=falha erro=<classe>` e 503.
   3. `$cobranca = $this->modelo->cobrancaPorCharge($pagamento['charge_id'], self::GATEWAYS[$gateway])`; `null` → `registrarResultado(..., null, 'ignorado')` e 200 `ignorado`.
   4. `$pagamento['valor_centavos'] !== (int) $cobranca->total` → `registrarResultado(..., $cobranca->idCobranca, 'divergente')`, log de alerta, 200 `divergente`.
   5. `$resultado = $this->modelo->aplicarPagamento($gateway, $eventoId, $payloadHash, $cobranca, $pagamento['status'], BasePaymentGateway::statusPago(self::GATEWAYS[$gateway], $pagamento['status']))` → `'aplicado' | 'sem_efeito'` → 200 com esse status; `'repetido'` → 200 `repetido`; `'falha'` → 503.
   6. Só com `'aplicado'`: `$this->modelo->auditar('Pagamento confirmado por webhook. Cobrança ' . $cobranca->idCobranca, $gateway, $ip)`.
   `$payloadHash = hash('sha256', $corpo)` — o corpo nunca é persistido.

7. **Model `Webhooks_model`** (`extends CI_Model`, só Query Builder/bindings):

```php
public function buscarEvento(string $gateway, string $eventoId): ?object;
public function cobrancaPorCharge(string $chargeId, string $paymentGateway): ?object;
    // SELECT cobrancas.*, os.lancamento AS lancamento_os, vendas.lancamentos_id AS lancamento_venda
    // LEFT JOIN os ON os.idOs = cobrancas.os_id  LEFT JOIN vendas ON vendas.idVendas = cobrancas.vendas_id
    // WHERE cobrancas.charge_id = ? AND cobrancas.payment_gateway = ? LIMIT 1
public function registrarResultado(string $gateway, string $eventoId, string $payloadHash, ?int $cobrancaId, string $resultado): bool;
    // upsert: INSERT novo ou UPDATE do existente (processado_em NULL) com resultado, cobranca_id e processado_em = agora
public function aplicarPagamento(string $gateway, string $eventoId, string $payloadHash, object $cobranca, string $novoStatus, bool $pago): string;
    // 'aplicado' | 'sem_efeito' | 'repetido' | 'falha'
public function auditar(string $tarefa, string $gateway, string $ip): void; // INSERT em logs: usuario = 'webhook:<gateway>', ip, tarefa, data, hora
```

   `aplicarPagamento` (dentro de `trans_begin` … `trans_commit`/`trans_rollback`, com `try/catch (\Throwable)` → rollback + `'falha'`):
   - evento existente com `processado_em` preenchido → rollback, `'repetido'`; inexistente → INSERT com `created_at` = agora. Se o INSERT falhar (desligar `db_debug` só em volta dele, como `Limite_requisicoes::incrementar()` faz), rollback e: o evento agora existe → `'repetido'` (corrida); não existe → `'falha'`.
   - status: atual ∈ pagos e novo ∉ pagos → não muda (monotônico; conferir com `BasePaymentGateway::statusPago($cobranca->payment_gateway, $cobranca->status)`); senão, se diferente, `UPDATE cobrancas SET status = ? WHERE idCobranca = ?`.
   - `$pago` e `$idLancamento = (int) ($cobranca->lancamento_os ?: $cobranca->lancamento_venda)` > 0 → `UPDATE lancamentos SET baixado = 1, data_pagamento = <hoje Y-m-d> WHERE idLancamentos = ? AND COALESCE(baixado, 0) = 0`; `affected_rows() === 1` conta como efeito (0 = já baixado, não é erro).
   - `resultado` = `'aplicado'` se mudou status **ou** baixou; senão `'sem_efeito'`. `UPDATE webhook_eventos SET processado_em = agora, resultado = ?, cobranca_id = ?`.
   - `trans_status() === false` → rollback, log `transacao rollback operacao=webhook id=<idCobranca>`, `'falha'`.
   - Datas pelo relógio do PHP (`date('Y-m-d H:i:s')`), nunca `NOW()`/`CURDATE()` (o teste roda em SQLite).

8. **`.env.example`** (perto das variáveis do Asaas):

```
# Token do webhook do Asaas, o mesmo informado no painel do Asaas em Integrações. Vazio: webhook do Asaas recusando tudo.
ASAAS_WEBHOOK_TOKEN=
# Limite de notificações de webhook por minuto e por IP. 0 desliga.
WEBHOOK_RATE_LIMIT=120
```

**Contrato HTTP:**

```
POST /webhooks/efi/{segredo}
Content-Type: application/x-www-form-urlencoded
notification=<token da notificação Efí>

POST /webhooks/mercadopago?data.id=123456&type=payment
x-signature: ts=1762200000000,v1=<hex hmac-sha256>
x-request-id: 9f1c0b7e-3c1a-4f7e-9b1d-2a6c8e4d5f00
Content-Type: application/json
{"id": 987, "type": "payment", "action": "payment.updated", "data": {"id": "123456"}}

POST /webhooks/asaas
asaas-access-token: <ASAAS_WEBHOOK_TOKEN>
Content-Type: application/json
{"id": "evt_05b708f961d739ea7eba7e4db318f621", "event": "PAYMENT_RECEIVED", "payment": {"id": "pay_9"}}

Respostas (todas application/json, Cache-Control: no-store):
200 {"status": "aplicado" | "repetido" | "ignorado" | "divergente" | "sem_efeito"}
401 {"status": "recusado"}               // assinatura/segredo/token inválido ou segredo ausente no .env
405 (Allow: POST)
413 {"status": "recusado"}               // corpo > 65536 bytes
429 (Retry-After: <s>)                   // WEBHOOK_RATE_LIMIT por minuto e por IP, escopo 'webhook'
503 {"status": "tente_novamente"}        // consulta falhou/timeout, banco, rollback — evento sem processado_em
```

**Contrato com camadas adjacentes:**

```
Produz para: gateways (Efí, Mercado Pago, Asaas) — reentregam em 5xx; 200 encerra a reentrega
Consome de: consultarPagamento() dos gateways (GatewayRecusou → 'ignorado' 200; demais → 503)
            Webhooks_model::aplicarPagamento → 'aplicado'|'sem_efeito'|'repetido'|'falha'
            Limite_requisicoes::segundosParaLiberar('webhook', $ip, WEBHOOK_RATE_LIMIT)
```
