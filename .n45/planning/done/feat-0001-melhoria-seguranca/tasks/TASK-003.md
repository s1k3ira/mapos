---
checkpoint: null
complexity: G
created_at: "2026-11-03 15:56:51"
criteria:
    - done: true
      test: application/vendor/bin/phpunit --filter testExcecaoNaoTratadaViraErroInternoEmJsonComLinhaNoLog tests/Unit/ApiBaseTest.php
      text: Método da API que lança TypeError responde 500 {status:false, message:'Erro interno'} sem arquivo/linha no corpo e grava log 'api excecao classe=TypeError'
    - done: true
      test: grep -nE 'catch \(\\Throwable' application/libraries/REST_Controller.php && ! grep -n 'show_exception' application/libraries/REST_Controller.php
      text: REST_Controller captura Throwable e não chama mais show_exception
    - done: true
      test: application/vendor/bin/phpunit --filter ExigirPermissao tests/Unit/ApiBaseTest.php
      text: exigirPermissao() responde 403 com a mensagem informada quando checkPermission nega, e não responde quando permite
    - done: true
      test: '! grep -n ''HTTP_UNAUTHORIZED'' application/controllers/api/v1/ApiController.php'
      text: ApiController não responde mais 401 para falta de permissão
    - done: true
      test: application/vendor/bin/phpunit --filter LoggedUser tests/Unit/ApiBaseTest.php
      text: logged_user() responde 401 para token ausente, inválido, de cliente, usuário desativado, conta expirada e grupo desativado
    - done: true
      test: sed -n '/public function logged_user/,/^    }/p' application/libraries/REST_Controller.php | grep -c 'HTTP_FORBIDDEN' | grep -qx 0
      text: logged_user() não responde mais 403
    - done: true
      test: application/vendor/bin/phpunit --filter PodeUsarApi tests/Unit/ApiBaseTest.php
      text: apiUsuarioPodeUsarApi devolve false para grupo desativado e true para usuário ativo, sem expiração e grupo ativo
    - done: true
      test: application/vendor/bin/phpunit --filter Refresh tests/Unit/ApiBaseTest.php
      text: POST /api/v1/refresh de usuário com grupo desativado responde 401 e revoga os refresh tokens do usuário
    - done: true
      test: application/vendor/bin/phpunit --filter Paginacao tests/Unit/ApiBaseTest.php
      text: 'apiPaginacao: perPage 1000000 vira 100, page=2&perPage=10 dá offset 20, padrão 20/0, perPage abc/0/-5 e page -1/1.5 dão erro por campo'
    - done: true
      test: application/vendor/bin/phpunit --filter PaginacaoDaApi tests/Unit/ApiBaseTest.php
      text: paginacaoDaApi() responde 422 com erros.perPage para perPage não inteiro
    - done: true
      test: application/vendor/bin/phpunit --filter Data tests/Unit/ApiBaseTest.php
      text: apiDataValida recusa 31/02/2026 e 2026-01-01 no formato d/m/Y e aceita 15/03/2026 devolvendo 2026-03-15; apiJanelaValida recusa 400 dias e fim antes do início
    - done: true
      test: application/vendor/bin/phpunit --filter Calendario tests/Unit/ApiBaseTest.php
      text: GET /api/v1/calendario devolve result.events com os eventos montados (sem allOs) e responde 422 para start inválido e janela acima de 366 dias
    - done: true
      test: '! grep -nE "''allOs''" application/controllers/api/v1/ApiController.php'
      text: calendario_get não devolve mais a lista crua de OS
    - done: true
      test: application/vendor/bin/phpunit tests/Unit/LimiteRequisicoesTest.php
      text: 'Limite_requisicoes: duas chamadas liberam, a terceira no mesmo minuto devolve os segundos até a próxima janela (60 em t=120, 55 em t=125); escopos diferentes não somam; limite 0 e tabela ausente devolvem 0'
    - done: true
      test: grep -nF "hash_hmac('sha256', \$escopo . '|' . \$ip" application/libraries/Limite_requisicoes.php
      text: A chave do contador combina escopo e IP num HMAC (IP nunca em claro)
    - done: true
      test: grep -nE "segundosParaLiberar\('api'" application/libraries/REST_Controller.php
      text: REST_Controller usa Limite_requisicoes no limite da API
    - done: true
      test: '! grep -nE ''assertIsArray|assertIsInt|assertNotNull\(\$resposta'' tests/Unit/ApiBaseTest.php tests/Unit/LimiteRequisicoesTest.php'
      text: Os testes da task não usam asserção só de tipo para valores calculáveis
deps:
    - TASK-001
diff_base: c8b5f7e074735ec930a43a1110200d7ecb60b9e9
diff_tip: 097272eb6c9b7dfd57c57d279ae4a6648d411038
files:
    - application/libraries/REST_Controller.php
    - application/libraries/Limite_requisicoes.php
    - application/helpers/api_helper.php
    - application/controllers/api/v1/ApiController.php
    - application/controllers/api/v1/UsuariosController.php
    - tests/Unit/ApiBaseTest.php
    - tests/Unit/LimiteRequisicoesTest.php
id: TASK-003
linter: composer analyse
n45_version: 1.1.0
outcome: |
    ## Contrato entregue (Backend)

    `REST_Controller` (application/libraries/REST_Controller.php):
    - `protected exigirPermissao(string $permissao, string $mensagem): void` — 403 `{status:false,message:$mensagem}` quando `checkPermission` nega.
    - `protected paginacaoDaApi(): array` — `[int $limite, int $offset]`; inválido responde 422 `{status:false,message:'Parâmetros de paginação inválidos.',erros}`.
    - `public logged_user()` — 401 `{status:false,message}` em qualquer falha (sem header: `Faça login para acessar a API.`; demais: `Faça o login novamente!`), inclusive conta expirada e grupo desativado; nunca 403.
    - `protected executarMetodo()` — qualquer `\Throwable` vira 500 `{status:false,message:'Erro interno'}`; log `error` `api excecao classe=... mensagem=... local=... rota=...`.
    - `limiteDeRequisicoes()` usa `Limite_requisicoes` (escopo `api`), 429 + `Retry-After`.

    `api_helper.php`: `apiUsuarioPodeUsarApi(?object, callable): bool`; `apiPaginacao($perPage,$page)` -> `['ok'=>true,'limite','offset']` | `['ok'=>false,'erros']` (teto 100, padrão 20/0); `apiDataValida(?string,string): ?string` (Y-m-d ou null); `apiJanelaValida(string,string,int=366): bool`.

    `Limite_requisicoes::segundosParaLiberar(string $escopo, string $ip, int $limitePorMinuto): int` — 0 = liberado; HMAC(escopo|ip); tabela `api_rate_limit`; sem tabela ou limite 0 = 0.

    Endpoints: `GET /api/v1/calendario` — 422 `Datas inválidas.` com `erros.start`/`erros.end`; 200 `result={start,end,events}` (sem `allOs`); 403 sem `vOs`. `GET /api/v1/audit` — paginação via `paginacaoDaApi()`, 403 sem `cAuditoria`. `POST /api/v1/refresh` — 401 e revoga refresh do usuário se grupo desativado.
persona: backend
phase: 'Phase 1 — Base de segurança: schema, API, gateways e avisos de sessão'
roadmap: feat-0001-melhoria-seguranca
status: done
tdd:
    green: false
    red: false
    refactor: false
tests: application/vendor/bin/phpunit tests/Unit/ApiBaseTest.php tests/Unit/LimiteRequisicoesTest.php
title: 'Base da API: 500 em JSON, 401 x 403, grupo ativo, paginação e limite por IP'
updated_at: "2026-11-03 17:41:29"
---
## Contexto

A API REST do operador (`application/controllers/api/v1/*`, todos estendendo `application/libraries/REST_Controller.php`) tem quatro defeitos de base que as tasks seguintes por controller vão consumir:

1. **Exceção não tratada vira HTML com arquivo/linha e status 200.** `REST_Controller::_remap()` só captura `Exception` (um `TypeError` escapa) e entrega a `show_exception()`.
2. **401 e 403 trocados.** Token inválido/expirado/de cliente responde **403** em `logged_user()`, e falta de permissão responde **401** em ~36 `checkPermission(...) → HTTP_UNAUTHORIZED` espalhados pelos controllers. O contrato novo: **401** só para token ausente, inválido, expirado, de outro tipo ou de conta/grupo desativado; **403** para falta de permissão.
3. **Grupo desativado continua usando a API.** `logged_user()` confere `situacao` mas não a expiração da conta nem o grupo de permissão (`Mapos_model::grupoAtivo(int $idPermissao): bool` já existe em `application/models/Mapos_model.php`). O mesmo vale para `POST /api/v1/refresh` (`UsuariosController::refresh_post()`).
4. **Paginação sem teto.** `perPage` aceita qualquer valor. Esta task cria o padrão `paginacaoDaApi()` que os controllers vão usar.

Também entram aqui: o **limite por IP reutilizável** (`Limite_requisicoes`, extraído de `REST_Controller::limiteDeRequisicoes()` para o webhook usar depois), a **validação estrita de datas** da API (helpers reaproveitados pela API de OS numa task seguinte) e dois ajustes de `ApiController::calendario_get` (devolve os eventos montados e valida `start`/`end`).

**Fora desta task:** trocar os `checkPermission` dos outros controllers (`OsController`, `ClientesController`, `ProdutosController`, `ServicosController`, `UsuariosController` fora do `refresh_post`) — cada um é de uma task própria que vai chamar `exigirPermissao()`; e `logged_client()` (área do cliente), que é de outra task. Não tocar neles.

Padrões do projeto: resposta da API `{ "status": true|false, "message": "...", "result": ... }` por `$this->response($corpo, REST_Controller::HTTP_X)`; `response()` encerra a requisição (`exit`). Erro técnico por `log_message('error', ...)`, nunca logar senha/token/segredo. Testes executam o método real do controller com `response()` substituído por exceção — modelo pronto em `tests/Unit/ApiOsSegurancaTest.php` (subclasse anônima do controller que sobrescreve `response()`, `logged_user()`, `get()`/`post()`). O suporte de teste da `TASK-001` já existe: `$this->logs('error')`, `assertLogContem('error', '...')`, `assertLogNaoContem('...')`.

## Comportamento Esperado

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| Método de controller da API lança `\TypeError('x')` | `500` `{"status":false,"message":"Erro interno"}`; corpo sem `.php` nem número de linha; log `error` começando por `api excecao classe=TypeError mensagem=x local=<arquivo>:<linha> rota=<uri>` |
| Método lança `RuntimeException('boom')` | `500` `{"status":false,"message":"Erro interno"}` |
| `exigirPermissao('vOs', 'Você não está autorizado a Visualizar OSs')` com `checkPermission` = false | `403` `{"status":false,"message":"Você não está autorizado a Visualizar OSs"}` |
| `exigirPermissao(...)` com `checkPermission` = true | não responde; o método segue |
| `logged_user()` sem header `Authorization` | `401` `{"status":false,"message":"Faça login para acessar a API."}` |
| `logged_user()` com token inválido, expirado ou `type` de cliente | `401` `{"status":false,"message":"Faça o login novamente!"}` |
| `logged_user()` com usuário `situacao = 0`, `dataExpiracao = '2020-01-01'` ou grupo `permissoes.situacao = 0` | `401` `{"status":false,"message":"Faça o login novamente!"}` |
| `apiUsuarioPodeUsarApi(usuario{situacao:1, dataExpiracao:null, permissoes_id:2}, fn(2) => true)` | `true` |
| `apiUsuarioPodeUsarApi(... grupo 2 desativado: fn(2) => false)` | `false` |
| `POST /api/v1/refresh` com refresh válido de usuário cujo grupo está desativado | `401` `{"status":false,"message":"Faça o login novamente!"}` e `revogarDoUsuario(<id>)` chamado (refresh seguinte do mesmo usuário → 401) |
| `apiPaginacao(null, null)` | `['ok' => true, 'limite' => 20, 'offset' => 0]` |
| `apiPaginacao('10', '2')` | `['ok' => true, 'limite' => 10, 'offset' => 20]` |
| `apiPaginacao('1000000', '0')` | `['ok' => true, 'limite' => 100, 'offset' => 0]` |
| `apiPaginacao('abc', '0')` / `apiPaginacao('0', '0')` / `apiPaginacao('-5', '0')` | `['ok' => false, 'erros' => ['perPage' => 'Informe um número inteiro de 1 a 100.']]` |
| `apiPaginacao('10', '-1')` / `apiPaginacao('10', '1.5')` | `['ok' => false, 'erros' => ['page' => 'Informe um número inteiro maior ou igual a 0.']]` |
| `paginacaoDaApi()` com `perPage=abc` | `422` `{"status":false,"message":"Parâmetros de paginação inválidos.","erros":{"perPage":"Informe um número inteiro de 1 a 100."}}` |
| `apiDataValida('31/02/2026', 'd/m/Y')` | `null` |
| `apiDataValida('2026-01-01', 'd/m/Y')` | `null` (formato errado) |
| `apiDataValida('15/03/2026', 'd/m/Y')` | `'2026-03-15'` |
| `apiDataValida('2026-03-15', 'Y-m-d')` | `'2026-03-15'` |
| `apiJanelaValida('2026-01-01', '2027-02-05')` (400 dias) | `false` |
| `apiJanelaValida('2026-01-01', '2026-12-31')` | `true` ; `apiJanelaValida('2026-02-01', '2026-01-01')` → `false` |
| `GET /api/v1/calendario?start=2026-13-01` | `422` `{"status":false,"message":"Datas inválidas.","erros":{"start":"Use o formato aaaa-mm-dd."}}` |
| `GET /api/v1/calendario?start=2026-01-01&end=2027-02-05` | `422` `{"status":false,"message":"Datas inválidas.","erros":{"end":"O período pode ter no máximo 366 dias."}}` |
| `GET /api/v1/calendario` com 2 OS no período | `200`, `result` = `{"start":..., "end":..., "events":[2 eventos com title "OS: <id>, Cliente: <nome>"]}` (sem a chave `allOs`) |
| `GET /api/v1/calendario` sem `vOs` | `403` |
| `Limite_requisicoes::segundosParaLiberar('api', '10.0.0.1', 2)` três vezes no mesmo minuto (relógio fixo em `t = 120`) | `0`, `0`, `60` |
| Mesma sequência com relógio em `t = 125` na 3ª chamada | 3ª devolve `55` (segundos até a próxima janela) |
| `segundosParaLiberar('webhook', '10.0.0.1', 2)` depois de 2 chamadas do escopo `api` do mesmo IP | `0` (escopos não somam) |
| `segundosParaLiberar('api', ip, 0)` | `0` (limite desligado), nada gravado |
| Sem a tabela `api_rate_limit` | `0` (desligado, como hoje) |

## TDD

Testes novos em `tests/Unit/ApiBaseTest.php` (REST_Controller, `ApiController`, `refresh_post`, helpers de `api_helper.php`) e `tests/Unit/LimiteRequisicoesTest.php` (biblioteca), classes `final` estendendo `MaposTestCase`. Estilo:

```php
public function testExcecaoNaoTratadaViraErroInternoEmJsonComLinhaNoLog(): void
{
    $resposta = $this->executar(fn () => throw new \TypeError('tipo errado'));

    $this->assertSame(500, $resposta['code']);
    $this->assertSame(['status' => false, 'message' => 'Erro interno'], $resposta['data']);
    $this->assertLogContem('error', 'api excecao classe=TypeError mensagem=tipo errado');
}

public function testLimitePorIpLiberaNaJanelaSeguinte(): void
{
    $this->db->query('CREATE TABLE api_rate_limit (chave TEXT, janela INTEGER, total INTEGER DEFAULT 0, PRIMARY KEY (chave, janela))');
    $agora = 120;
    $limite = new Limite_requisicoes(['db' => $this->db, 'relogio' => function () use (&$agora) { return $agora; }, 'segredo' => 'teste']);

    $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
    $this->assertSame(0, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
    $agora = 125;
    $this->assertSame(55, $limite->segundosParaLiberar('api', '10.0.0.1', 2));
}
```

Cobrir os demais casos da tabela. Mudança de segurança exige o caso negado: sem permissão (403), grupo desativado (401), token de cliente (401), `perPage` inválido (422).

**Refatoração:** depois do verde, remover de `logged_user()` a segunda chamada morta `$this->response(['Authentication failed'], REST_Controller::HTTP_OK)` e as variáveis não usadas (`$headers`, `$token_header`) se o PHPStan apontar.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `application/libraries/REST_Controller.php` | Modificar | `executarMetodo()` com `\Throwable` → 500 JSON; `exigirPermissao()`; `logged_user()` 401 + conta expirada + grupo ativo; `paginacaoDaApi()`; `limiteDeRequisicoes()` usando `Limite_requisicoes` |
| `application/libraries/Limite_requisicoes.php` | Criar | Contador por escopo + IP por minuto na tabela `api_rate_limit` |
| `application/helpers/api_helper.php` | Modificar | `apiUsuarioPodeUsarApi()`, `apiPaginacao()`, `apiDataValida()`, `apiJanelaValida()` |
| `application/controllers/api/v1/ApiController.php` | Modificar | `exigirPermissao()` nos 2 pontos; `calendario_get` com datas validadas e `result.events`; `audit_get` com `paginacaoDaApi()` |
| `application/controllers/api/v1/UsuariosController.php` | Modificar | Só `refresh_post()`: recusa grupo desativado com `apiUsuarioPodeUsarApi()` e revoga |
| `tests/Unit/ApiBaseTest.php` | Criar | REST_Controller, ApiController, refresh, helpers |
| `tests/Unit/LimiteRequisicoesTest.php` | Criar | Biblioteca `Limite_requisicoes` |

### Detalhamento Técnico

1. **`_remap()` → `executarMetodo()`.** Extrair o bloco final de `_remap()` (o `try { call_user_func_array(...) } catch (Exception $ex) { ... show_exception ... }`) para `protected function executarMetodo(string $metodo, array $argumentos): void`, chamado de `_remap()` no mesmo ponto. O novo `catch (\Throwable $e)`: `log_message('error', sprintf('api excecao classe=%s mensagem=%s local=%s:%d rota=%s', $e::class, mb_substr($e->getMessage(), 0, 300), $e->getFile(), $e->getLine(), $rota))` com `$rota = (string) ($this->uri->uri_string() ?? '')` (usar `?? ''` e cast: pode não haver `uri` em teste — proteger com `isset($this->uri)`), depois `$this->response(['status' => false, 'message' => 'Erro interno'], self::HTTP_INTERNAL_SERVER_ERROR)`. Remover o ramo `rest_handle_exceptions === false → throw` e o `show_exception()`: vale em qualquer ambiente. O `if ($this->is_valid_request)` continua envolvendo a chamada.
2. **`exigirPermissao`**:
   ```php
   protected function exigirPermissao(string $permissao, string $mensagem): void
   {
       if (! $this->permission->checkPermission($this->logged_user()->level, $permissao)) {
           $this->response(['status' => false, 'message' => $mensagem], self::HTTP_FORBIDDEN);
       }
   }
   ```
   Em `ApiController`, substituir os dois blocos `if (! $this->permission->checkPermission(...vOs/cAuditoria)) { ... HTTP_UNAUTHORIZED }` por `$this->exigirPermissao('vOs', 'Você não está autorizado a Visualizar OSs')` e `$this->exigirPermissao('cAuditoria', 'Você não está autorizado a Visualizar Auditoria')`. O `if (checkPermission(... 'vOs'))` de `index_get` (que só acrescenta dados) fica como está.
3. **`logged_user()`**: todas as respostas de falha passam a `self::HTTP_UNAUTHORIZED` (as mensagens atuais são mantidas: sem header → `Faça login para acessar a API.`; demais → `Faça o login novamente!`). Na conferência do usuário do banco, trocar `empty(...) || situacao !== 1` por `! apiUsuarioPodeUsarApi($this->user->usuario, fn (int $grupo): bool => $this->Mapos_model->grupoAtivo($grupo))` (carregar `$this->load->model('Mapos_model')` e `$this->load->helper('api')` antes). As conferências de versão do token e `sid` seguem iguais.
4. **`apiUsuarioPodeUsarApi(?object $usuario, callable $grupoAtivo): bool`** (`api_helper.php`): `false` se `$usuario` vazio, `(int) $usuario->situacao !== 1`, `contaExpirada($usuario->dataExpiracao ?? null)` (função de `general_helper.php`, já autocarregado) ou `! $grupoAtivo((int) $usuario->permissoes_id)`. Usar `?? null`/cast em toda leitura (sem `Deprecated` de `null`).
5. **`refresh_post()`**: trocar a condição `empty($usuario) || situacao !== 1 || chk_date(...)` por `! apiUsuarioPodeUsarApi($usuario, fn (int $g): bool => $this->Mapos_model->grupoAtivo($g))`, mantendo `revogarDoUsuario((int) $girado['usuario_id'])` e o 401. Nada mais muda em `UsuariosController`.
6. **`apiPaginacao($perPage, $page): array`**: `null`/`''` → padrão (`perPage` 20, `page` 0). `perPage` precisa casar `/^\d+$/` e ser ≥ 1 (senão erro `perPage`); acima de 100 vira 100. `page` precisa casar `/^\d+$/` (senão erro `page`). `offset = page * limite` (uma multiplicação só). Retorno: `['ok' => true, 'limite' => int, 'offset' => int]` ou `['ok' => false, 'erros' => [campo => mensagem]]` (as duas mensagens da tabela, podendo vir as duas).
7. **`paginacaoDaApi(): array`** (`REST_Controller`, `protected`): `apiPaginacao($this->get('perPage', true), $this->get('page', true))`; inválido → `422` `{status:false, message:'Parâmetros de paginação inválidos.', erros}`; válido → `[int $limite, int $offset]`. Em `ApiController::audit_get`, trocar o cálculo manual por `[$limite, $offset] = $this->paginacaoDaApi();`.
8. **Datas** (`api_helper.php`):
   ```php
   function apiDataValida(?string $valor, string $formato): ?string
   {
       $valor = trim((string) $valor);
       $data = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
       $erros = DateTimeImmutable::getLastErrors();
       // createFromFormat aceita 31/02 (vira 03/03) e só avisa em getLastErrors(): conferir a volta.
       if ($data === false || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0)) || $data->format($formato) !== $valor) {
           return null;
       }

       return $data->format('Y-m-d');
   }
   ```
   `apiJanelaValida(string $inicioYmd, string $fimYmd, int $maxDias = 366): bool` → `false` se fim < início ou diferença em dias > `$maxDias`.
9. **`calendario_get`**: `start`/`end` em `Y-m-d`; ausentes → `date('Y-m-01')`/`date('Y-m-t')` como hoje; presentes e inválidos → 422 com `erros.start`/`erros.end` = `Use o formato aaaa-mm-dd.`; janela inválida → 422 com `erros.end` = `O período pode ter no máximo 366 dias.`. `result` passa a ser `['start' => $start, 'end' => $end, 'events' => $events]`. Não mudar a montagem dos eventos.
10. **`Limite_requisicoes`** (`application/libraries/Limite_requisicoes.php`, guarda `if (! defined('BASEPATH')) exit(...)` como `Limite_login`): construtor `__construct($opcoes = null)` com `db` (padrão `get_instance()->db`), `relogio` (padrão `'time'`) e `segredo` (padrão `config_item('encryption_key')`), no mesmo estilo de `application/libraries/Limite_login.php`. `segundosParaLiberar(string $escopo, string $ip, int $limitePorMinuto): int`: `$limitePorMinuto <= 0` ou tabela ausente (`table_exists('api_rate_limit')`, cacheado) → `0`; `$chave = hash_hmac('sha256', $escopo . '|' . $ip, $segredo)`; `$janela = intdiv($agora, 60)`; incremento **portável** (o SQLite do teste não tem `ON DUPLICATE KEY`): `UPDATE api_rate_limit SET total = total + 1 WHERE chave = ? AND janela = ?`; se `affected_rows() === 0`, `INSERT (chave, janela, total) VALUES (?, ?, 1)`; se o INSERT falhar (corrida com outra requisição), repetir o UPDATE. Ler `total`; limpeza ocasional (`random_int(1, 100) === 1` → `DELETE ... WHERE janela < $janela - 5`); `total > limite` → `60 - ($agora % 60)`, senão `0`.
11. **`limiteDeRequisicoes()`** passa a: `options` → retorna; `$espera = (new Limite_requisicoes())->segundosParaLiberar('api', $this->input->ip_address(), (int) $this->config->item('api_rate_limit'))` (ou via `$this->load->library('Limite_requisicoes')`); `$espera > 0` → `header('Retry-After: ' . $espera)` + 429 com a mesma mensagem de hoje.
12. Testes do `REST_Controller`: subclasse anônima de `ApiController` (ou de `REST_Controller`) criada com `makeInstance`-like construtor vazio, sobrescrevendo `response()` para lançar `RuntimeException(json_encode(['code' => $http_code, 'data' => $data]))` — **atenção**: o `catch (\Throwable)` de `executarMetodo()` capturaria essa exceção; no teste de `executarMetodo`, a resposta 500 sai de dentro do `catch`, então ela propaga normalmente. Para `logged_user()`, preencher `$_SERVER['HTTP_AUTHORIZATION']` e restaurar no `tearDown`, com `authorization_token` e `Usuarios_model`/`Mapos_model` falsos injetados como propriedades públicas da subclasse.

**Contrato com camadas adjacentes (consumido pelas tasks dos controllers da API):**

```php
// application/libraries/REST_Controller.php
protected function exigirPermissao(string $permissao, string $mensagem): void; // 403 {status:false, message:$mensagem}
protected function paginacaoDaApi(): array;                                    // [int $limite, int $offset] ou 422 {status:false, message, erros}
public function logged_user();                                                 // 401 {status:false, message} em qualquer falha de token/conta/grupo

// application/helpers/api_helper.php
function apiUsuarioPodeUsarApi(?object $usuario, callable $grupoAtivo): bool;
function apiPaginacao($perPage, $page): array;   // ['ok'=>true,'limite'=>int,'offset'=>int] | ['ok'=>false,'erros'=>array]
function apiDataValida(?string $valor, string $formato): ?string; // 'Y-m-d' ou null
function apiJanelaValida(string $inicioYmd, string $fimYmd, int $maxDias = 366): bool;

// application/libraries/Limite_requisicoes.php (consumido pelo webhook: escopo 'webhook')
public function segundosParaLiberar(string $escopo, string $ip, int $limitePorMinuto): int; // 0 = liberado
```

Resposta de exceção não tratada (toda a API): `500 {"status":false,"message":"Erro interno"}`.
