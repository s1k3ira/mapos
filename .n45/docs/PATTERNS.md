---
created_at: "2026-11-03 14:48:04"
n45_version: 1.1.0
patterns:
    - description: View -> Controller (MY_Controller) -> Model (Query Builder) -> MySQL; regra reutilizável em helpers e libraries
      name: Camadas
      scope: architecture
    - description: Todo método público de controller do painel é declarado em application/config/permissions_map.php; sem entrada, é negado
      name: Autorização por rota
      scope: architecture
    - description: Mudança de schema só por migration timestamp com up e down; nunca editar banco.sql
      name: Migrations
      scope: architecture
    - description: Configuração no application/.env lida por config/*.php via $_ENV; variável nova entra no .env.example
      name: Configuration
      scope: code_conventions
    - description: php-cs-fixer @PSR2 com array curto via composer format; PHPStan nível 5 via composer analyse
      name: Formatação
      scope: code_conventions
    - description: Classes de controller em PascalCase; model Nome_model; helper nome_helper; métodos de controller em camelCase; domínio em português
      name: Nomenclatura
      scope: code_conventions
    - description: Conventional Commits (feat, fix, docs, refactor, chore); CI roda no branch v5 e em pull requests
      name: Git
      scope: code_conventions
    - description: Formulário por POST com CSRF; erro volta à tela com valores e erros por campo; sucesso redireciona com flashdata e toast
      name: Response da tela
      scope: response
    - description: '{ result, message, erros?, html? } com 403 sem permissão ou registro fechado, 404 item de outro registro, 422 erros de campo'
      name: Response JSON do painel
      scope: response
    - description: '{ status, message, result } por REST_Controller; erros com o código HTTP correspondente'
      name: Response da API
      scope: response
    - description: Model devolve dados ou false; controller valida e decide status; erro técnico vai para log_message; página de erro sem detalhes em produção
      name: Erros por camada
      scope: errors
    - description: Grupos em config/form_validation.php aplicados por validarFormulario(grupo); mensagens em pt-BR
      name: Validação no servidor
      scope: errors
    - description: 'Todo ponto de login passa por Limite_login: bloqueado() antes da senha, registrarFalha() e registrarSucesso() depois; mensagem genérica'
      name: Limite de login
      scope: architecture
    - description: log_message com log_threshold 1 em application/logs; trilha de ações do usuário por log_info() na tabela logs
      name: Log
      scope: observability
    - description: tests/Unit/<Assunto>Test.php estendendo MaposTestCase; métodos testComportamento em português executando o comportamento
      name: Nomenclatura de testes
      scope: testing
    - description: MY_Controller::paginacao() com offset na URL e filtros na query string lidos por listagemFiltros() com allow-list
      name: Pagination
      scope: response
    - description: API por IP por minuto (API_RATE_LIMIT, tabela api_rate_limit); login por Limite_login; 429 com Retry-After
      name: Rate limiting
      scope: architecture
    - description: Cabeçalhos e CSP pelo hook security_headers; CORS da API só pelas origens de API_CORS_ORIGINS
      name: CORS / Security headers
      scope: architecture
    - description: Proteção do CodeIgniter ligada; rotas api.* e csp/report excluídas; cookie do CSRF lido pelo JS
      name: CSRF
      scope: architecture
    - description: Sessão no banco com cookie HttpOnly, SameSite e Secure automático; sessão revalidada no banco a cada requisição
      name: Session / Cookie
      scope: architecture
    - description: Prefixo /api/v1 em routes_api.php, carregado só com API_ENABLED=true
      name: API versioning
      scope: architecture
    - description: Tailwind 4 com tokens de assets/src/tokens.css; só classes de token, sem cor ou fonte fixa em view
      name: Estilização
      scope: styling
    - description: component() de input; form novalidate com js_module formulario/padrao; servidor sempre revalida e devolve erro por campo
      name: Formulários
      scope: forms
    - description: get() e post() de assets/js/lib/http.js; sem jQuery
      name: API client
      scope: api_client
    - description: Estados vazios distintos (nada ainda x sem resultado), toast para feedback, pill-status para estado de registro
      name: Estados de UI
      scope: ui_states
updated_at: "2026-11-03 14:48:04"
---

## Arquitetura

**Responsabilidade de cada camada:**

- Controller: estende `MY_Controller` (painel) ou `REST_Controller` (API); valida a entrada, chama model e helper, escolhe a view ou o JSON. `MY_Controller::__construct()` exige sessão, revalida o usuário no banco, exige banco atualizado, exige 2FA quando o grupo pede e confere o `permissions_map`.
- Model (`application/models/X_model.php`): só acesso a dados com o Query Builder do CodeIgniter ou query com bindings; nunca concatena SQL com entrada do usuário.
- Helper e library: regra reutilizável sem estado de requisição (`os_helper`, `financeiro_helper`, `Limite_login`, `Totp`).
- View: PHP com Tailwind, monta a tela com `component()`; todo valor impresso passa por `e()`.

**Rotas:** convenção do CodeIgniter `/<controller>/<método>/<id>`; `default_controller` é `mapos`. As rotas da API ficam em `application/config/routes_api.php` e só carregam com `API_ENABLED=true`.

**Estrutura de pastas:**

```
index.php                         front controller: carrega o .env, define ENVIRONMENT
application/
  .env / .env.example             configuração (nunca versionar o .env)
  config/                         config.php, database.php, routes*.php, permissions_map.php, form_validation.php, hooks.php ...
  controllers/                    controllers do painel (Os.php, Clientes.php ...), Login, Mine (área do cliente), Email (cron), Tools (CLI)
  controllers/api/v1/             controllers REST; client/ para a API do cliente
  core/                           MY_Controller.php, MY_Security.php
  hooks/                          security_headers, pagina_de_erro, whoops, compress
  models/                         Nome_model.php
  libraries/                      REST_Controller, Limite_login, Totp, Permission, Gateways/ ...
  helpers/                        nome_helper.php
  views/                          <controller>/ (telas), components/ (biblioteca), tema/ (moldura), entrada/ (logins), errors/
  database/migrations/            YYYYMMDDHHMMSS_descricao.php
  database/seeds/                 seeds de desenvolvimento
  language/pt-br/                 mensagens do framework
assets/
  src/                            CSS fonte (tokens.css, app.css, layout.css, imprimir.css, pdf.css)
  dist/                           CSS compilado (commitado)
  js/                             app.js, lib/ (http, formulario, toast ...), modules/<pasta>/<nome>.js
  vendor/                         bibliotecas JS copiadas por npm run build:vendor (commitado, gerado)
install/                          instalador web independente
docker/                           compose de desenvolvimento (nginx, php-fpm, mysql, phpmyadmin)
docs/                             guias (API, armazenamento, migração 4x para 5, servidor web)
tests/Unit/                       testes PHPUnit; tests/js/ testes do node:test
scripts/                          check-escape, check-inline-script, build-vendor, contraste-temas
updates/                          SQLs de atualização das versões 3.x e 4.x
```

## Convenções de Código

| Item | Padrão |
| ---- | ------ |
| Controller | `PascalCase.php`, uma classe com o mesmo nome (`Os.php`, `Clientes.php`) |
| Model | `Nome_model.php`, classe `Nome_model` (`Os_model.php`) |
| Helper | `nome_helper.php` em minúsculas, funções globais em camelCase (`clienteDadosDoFormulario`) |
| Library | `Nome.php` ou `Nome_composto.php` (`Limite_login.php`) |
| View | `application/views/<controller>/<tela>.php`; partes que mudam sem reload em `partes/` |
| JS | `assets/js/modules/<pasta>/<nome>.js` com `export default` recebendo o elemento |
| Idioma | Domínio, mensagens e comentários em português; identificadores novos no padrão do arquivo vizinho |
| Formatação | `composer format` antes do commit; PSR-2 com array curto |

Regras que valem para toda mudança:

1. Sem SQL por concatenação: Query Builder ou bindings (`?`).
2. Todo valor impresso em view passa por `e()`; HTML confiável por `printSafeHtml()` ou `html_purificado()`. `php scripts/check-escape.php` reprova ocorrência nova e a contagem de `escape-baseline.json` nunca sobe.
3. Sem `<script>` inline em view; JS de página é módulo em `assets/js/modules` ligado por `<?= js_module('pasta/nome') ?>` e dados por `data-*` ou `page_data()`. `inline-script-baseline.json` nunca sobe.
4. Nunca expor hash de senha ou segredo em model público ou resposta de API.
5. Anexos e arquivos enviados ficam em `STORAGE_PATH` (fora de `assets/`) e saem por controller que checa permissão; nunca por URL pública.

## Padrões de Response

**Tela (formulário):** POST comum com CSRF. Com erro, a tela volta com os valores enviados e o erro por campo repassado a cada componente (`error`); com sucesso, `redirect()` com `set_flashdata('success', ...)` exibido como toast.

**JSON do painel** (telas que mudam sem recarregar, como a OS): `{ "result": true|false, "message": "...", "erros": {campo: msg}, "html": {parte: "..."} }`. Status 403 sem permissão ou registro fechado, 404 para item de outro registro, 422 com erros por campo. O id do registro vem do POST mas é conferido no banco; totais, descontos e estoque são sempre calculados no servidor.

**API:** `{ "status": true|false, "message": "...", "result": ... }` enviado por `$this->response($corpo, REST_Controller::HTTP_OK)`; método do controller nomeado `<recurso>_<verbo>` (`index_get`, `index_post`).

## Padrões de Erro

**Propagação:** model devolve dados, `false` ou lança `RuntimeException` quando uma operação obrigatória falha (as migrations lançam quando a tabela não foi criada). O controller decide o status e a mensagem para o usuário.

**Logging:** erro técnico por `log_message('error', ...)` (threshold 1, em `application/logs`); trilha das ações do usuário por `log_info('<tarefa>')` (`audit_helper`), gravada na tabela `logs`. Nunca logar senha, token, chave ou dado de cartão.

**Páginas de erro:** em produção o hook `pagina_de_erro` responde 500 com `errors/html/error_500.php`, sem detalhe técnico; o Whoops só com `WHOOPS_ERROR_PAGE_ENABLED` em development.

**Validação:** `$this->validarFormulario('grupo')` aplica os grupos de `application/config/form_validation.php` (mensagens em pt-BR) e devolve o array de erros por campo; revalidar sempre no servidor, mesmo com validação HTML no cliente.

## Autenticação

**Painel:** sessão do CodeIgniter no banco (`ci_sessions`). `MY_Controller` revalida o usuário a cada requisição (conta desativada ou expirada, grupo desativado, senha trocada em outra sessão encerram a sessão).

**Autorização:** `application/config/permissions_map.php` mapeia `Controller => método => permissão` (`'vOs'`, `['aOs', 'eOs']`, `['todas' => [...]]`, `'*'` ou `false`). Método público sem entrada é negado; `PermissionsMapTest` confere. Botões de tela usam `$this->permite('xPerm')` só para esconder a UI; a barreira real é o mapa.

**Login:** todo ponto de login passa por `Limite_login`: `bloqueado()` antes de verificar a senha, `registrarFalha()` em e-mail ou senha errados, `registrarSucesso()` ao entrar, e a mesma mensagem genérica para e-mail desconhecido e senha errada.

**2FA:** TOTP opcional (`Totp`), com códigos de recuperação guardados só como hash; um grupo pode exigir o cadastro.

**API:** JWT HS256 de curta duração em `Authorization: Bearer`, com refresh token rotativo (`Refresh_token`, tabela `api_refresh_tokens`). O token não carrega permissões: usuário e permissão são consultados no banco a cada requisição. Detalhes em `docs/api-autenticacao.md`.

## Observabilidade

**Formato de log:** texto do CodeIgniter em `application/logs`, nível de erro (`log_threshold` 1). Relatórios de violação de CSP em `application/logs/csp-violacoes.json`.

**O que NÃO logar:** senhas, tokens, chaves, hash de código de recuperação, dados de cartão e qualquer PII além do necessário.

## Convenções de Teste

| Item | Padrão |
| ---- | ------ |
| Localização | `tests/Unit/<Assunto>Test.php` (PHPUnit 12); JS em `tests/js/*.mjs` (node:test) |
| Base | Classe `final` estendendo `MaposTestCase` (SQLite em memória por teste, reflexão para métodos privados, `makeInstance()` sem construtor) |
| Mocks | Instância real com SQLite em memória no lugar de mock de banco; dependências injetadas por `setPrivateProperty()` |
| Estilo | Executar o comportamento (chamar a função ou o método do controller e assertar o efeito); teste que só lê o código-fonte não vale. Mudança de segurança exige teste do caso negado (sem permissão, registro de outro cliente, valor inválido) |
| Exemplo | `tests/Unit/ClientesFormularioTest.php` |
| Cobertura | Não medida; o CI roda `composer test`, `composer analyse`, `composer format` em dry-run e `composer audit` |

## Pagination

**Estilo:** offset na URL, como o `CI_Pagination`, por `MY_Controller::paginacao($baseUrl, $total, $offset, null, $filtros)` e `component('pagination', ...)`. Nenhuma tela do painel usa `CI_Pagination::create_links()`.

**Limites:** `per_page` 10 em `MY_Controller::$data['configuration']`.

**Filtros:** na query string, lidos por `listagemFiltros()` (allow-list) e contados com os mesmos filtros; manter os filtros nos links da paginação. Estado vazio distinto para "nada ainda" e "sem resultado".

## Migrations

**Naming:** `application/database/migrations/YYYYMMDDHHMMSS_descricao_curta.php`, classe `Migration_descricao_curta extends CI_Migration`, criada por `php index.php tools migration "nome"`.

**Reversibilidade:** `up()` e `down()` obrigatórios; quando um CREATE pode falhar em silêncio (`db_debug` desligado), conferir `table_exists()` e lançar `RuntimeException`.

**Regras:** schema só por migration, nunca editando `banco.sql`; tabelas novas em InnoDB com `idX` auto incremento; charset e collation vêm da conexão. Migrations pendentes bloqueiam o painel até alguém com `cSistema` aplicar em Configurações > Sistema ou por `php index.php tools migrate`. Mudança que obriga o admin a agir (variável do `.env`, migration, rota) atualiza `docs/migracao-4x-para-5.md`.

## Configuration / Env vars

**Naming:** `UPPER_SNAKE_CASE` com prefixo por assunto (`APP_`, `DB_`, `EMAIL_`, `API_`, `PAYMENT_GATEWAYS_<GATEWAY>_`).

**Onde declarar:** `application/.env.example` lista toda variável; `index.php` carrega o `.env` com phpdotenv e os arquivos `application/config/*.php` leem por `$_ENV['NOME'] ?? padrão`.

**Validação:** chaves inseguras (`API_JWT_KEY`, `APP_ENCRYPTION_KEY` iguais ao exemplo) derrubam a API com 503 e bloqueiam o 2FA em produção (`chaveInsegura()`).

**Segredos:** nunca registrar, imprimir ou commitar `.env`, credenciais, chaves ou dumps.

## Backend input validation

**Onde:** na borda do controller, antes de chamar o model, por `validarFormulario('grupo')`; a função que monta os dados salvos lê só os campos do formulário (nunca id, `asaas_id` ou data vindos do POST). O id editado vem da URL, não do POST.

**Erro retornado:** erros por campo (tela) ou 422 com `erros` (JSON); mensagens em pt-BR.

## Time / Timezone

**Storage:** colunas `DATE` e `DATETIME` em `Y-m-d` e `Y-m-d H:i:s`, no fuso `APP_TIMEZONE` (padrão `America/Sao_Paulo`).

**Parsing:** datas ISO 8601 da agenda convertidas por `dataIsoParaYmd()`; exibição em `d/m/Y` na view.

## Background jobs / Async

**Disparo:** cron do sistema operacional chama o CLI: `php index.php email/process` a cada 2 minutos e `php index.php email/retry` a cada 5; os controllers `Email` e `Tools` respondem só em linha de comando.

**Fila:** e-mails entram na tabela `email_queue` (`MY_Email`) e saem pelo job; falhas são reenviadas pelo `retry`.

## Rate limiting

**Escopo:** API por IP, janela fixa de um minuto, tabela `api_rate_limit` (`API_RATE_LIMIT`, padrão 300, 0 desliga); login por `Limite_login`.

**Resposta:** 429 com `Retry-After` no login e na API.

## CORS / Security headers

**CORS:** só a API, só para as origens de `API_CORS_ORIGINS`; vazio significa sem CORS.

**Cabeçalhos:** hook `security_headers` envia HSTS (só em HTTPS, `APP_HSTS_MAX_AGE`), CSP (`APP_CSP`: enforce, report-only ou off) e demais cabeçalhos; violações chegam em `csp/report`. Detalhes em `docs/cabecalhos-de-seguranca.md`.

## CSRF

**Estratégia:** proteção do CodeIgniter ligada (`APP_CSRF_PROTECTION`); `MY_Security` deixa o cookie do CSRF sem HttpOnly e com SameSite Strict para o JS ler o token.

**Escopo:** todo POST do painel; excluídos `api.*` (usa Bearer) e `csp/report`. O token regenera a cada requisição; `lib/http.js` o acrescenta e atualiza os campos ocultos.

## Session / Cookie

**Tipo:** sessão no banco; cookie `HttpOnly`, `SameSite=Lax` e `Secure` em modo `auto` (só com HTTPS; atrás de proxy, o `X-Forwarded-Proto` só vale vindo de IP de `APP_PROXY_IPS`).

**Logout:** `login/sair` encerra a sessão; trocar a senha aumenta a versão da sessão e derruba as outras. Detalhes em `docs/cookies-e-sessao.md`.

## API versioning

**Estratégia:** prefixo de path `/api/v1/`; a API inteira responde 404 com `API_ENABLED=false`.

## Estilização (frontend)

**Config:** tokens em `assets/src/tokens.css`; o `DESIGN.md` é a fonte da verdade e o valor visual fica nele. A paleta padrão do Tailwind está desligada: só existem as cores dos tokens (`bg-surface`, `text-muted`, `border-border`, `bg-primary` com `text-on-primary`...).

**Build:** depois de mudar view ou CSS, `npm ci && npm run build` e commitar `assets/dist`; o CI reconstrói e falha se estiver defasado. Escrever nomes de classe completos no PHP, nunca montar por concatenação (o Tailwind escaneia `application/views/**/*.php`).

**Escopos:** a moldura do painel fica dentro de `.v5-shell` (`layout.css`); o conteúdo da tela fica em `.v5-conteudo` e usa `app.css`; nunca pôr conteúdo de tela dentro de `.v5-shell`. Impressão usa `assets/dist/imprimir.css`; PDF do mPDF usa `assets/dist/pdf.css` (só CSS 2.1, classes `rel-*`).

## Formulários (frontend)

**Escopo:** todo formulário segue `Clientes::formulario()` e `views/clientes/formulario.php`: `<form novalidate <?= js_module('formulario/padrao') ?>>` valida pelas restrições HTML, mostra o erro no campo, aplica `data-mascara` e põe o botão em loading.

**Exibição de erro:** inline no componente (`error`), sempre com o texto do servidor quando o cliente passa.

**Submit:** POST pessimista com CSRF; o servidor revalida e re-renderiza com os valores ou redireciona com toast.

## API Client (frontend)

**Wrapper:** `get()` e `post()` de `assets/js/lib/http.js`: mandam `X-Requested-With`, acrescentam o token do CSRF e tratam 403 (negação vira JSON, não redirect). Sem jQuery.

**Erro na UI:** `ErroHttp` com status e corpo; mensagens do servidor viram toast ou erro de campo.

## Estados de UI (frontend)

**Loading:** botão em loading pelo `formulario/padrao`; partes que atualizam sem reload usam `data-os-parte` e `data-os-acao` (`Os::visualizar()`, `assets/js/modules/os/tela.js`).

**Empty state:** `component('empty-state')` com estados distintos para "nada ainda" e "sem resultado".

**Notificações:** `component('toast')` por flashdata; estado de OS, venda ou cobrança sempre em `pill-status` com a palavra, nunca só cor.
