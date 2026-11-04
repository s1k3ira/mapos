---
checkpoint: null
complexity: M
created_at: "2026-11-04 06:56:22"
criteria:
    - done: false
      test: NGINX_HOST=localhost NGINX_PORT=8000 envsubst '$NGINX_HOST $NGINX_PORT' < docker/etc/nginx/default.template.conf | diff - docker/etc/nginx/default.conf
      text: Template renderizado com localhost/8000 é idêntico ao default.conf (a sobrescrita do compose vira no-op)
    - done: false
      test: grep -q 'location ^~ /application/' docker/etc/nginx/default.template.conf && grep -q 'location ^~ /updates/' docker/etc/nginx/default.template.conf && grep -qF '(scripts|tests|docker|docs)' docker/etc/nginx/default.template.conf
      text: Template passa a bloquear application/, updates/ e scripts|tests|docker|docs como o default.conf
    - done: false
      test: grep -qE 'map[[:space:]]+\$request_uri[[:space:]]+\$loggable' docker/etc/nginx/default.template.conf && grep -qE '~webhooks/efi/[[:space:]]+0;' docker/etc/nginx/default.template.conf
      text: map do $request_uri define $loggable = 0 para webhooks/efi/ no template
    - done: false
      test: grep -qE 'access_log[^;]*if=\$loggable;' docker/etc/nginx/default.template.conf && grep -qE 'access_log[^;]*if=\$loggable;' docker/etc/nginx/default.conf
      text: access_log do server usa if=$loggable no template e no default.conf
    - done: false
      test: docker run --rm --add-host php-fpm:127.0.0.1 -v "$(pwd)/docker/etc/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro" nginx:alpine nginx -t
      text: nginx aceita a configuração gerada
    - done: false
      test: '! grep -nE ''location[^{]*webhooks'' docker/etc/nginx/default.template.conf docker/etc/nginx/default.conf && grep -q ''client_max_body_size 64M;'' docker/etc/nginx/default.template.conf'
      text: Webhook continua no location / com client_max_body_size do server (nenhum location próprio de webhooks)
    - done: false
      test: grep -qE '^\*/2 .*index\.php email/process .*2>&1$' docker/etc/php/cron-jobs && grep -qE '^\*/5 .*index\.php email/retry .*2>&1$' docker/etc/php/cron-jobs
      text: cron mantém email/process a cada 2 min e email/retry a cada 5 min, com stderr no log
    - done: false
      test: bash -n scripts/smoke-seguranca.sh && test -x scripts/smoke-seguranca.sh
      text: smoke-seguranca.sh é bash sintaticamente válido e executável
    - done: false
      test: grep -qE 'up -d' scripts/smoke-seguranca.sh && grep -qE 'trap .*EXIT' scripts/smoke-seguranca.sh && grep -qE ' down' scripts/smoke-seguranca.sh
      text: Smoke sobe e derruba pelo comando do RUNBOOK com trap em EXIT
    - done: false
      test: node --test --test-name-pattern='smoke sai 0' tests/js/smoke-seguranca.test.mjs
      text: Com as 12 rotas no veredito esperado o smoke imprime 12 linhas OK e sai 0
    - done: false
      test: node --test --test-name-pattern='smoke sai 1' tests/js/smoke-seguranca.test.mjs
      text: GET login/sair respondendo 200 gera FALHOU nessa linha, 11 OK e exit 1
    - done: false
      test: node --test --test-name-pattern='login' tests/js/smoke-seguranca.test.mjs
      text: Login aceita 403 e 422 e reprova 500
    - done: false
      test: node --test --test-name-pattern='GET / com 200 passa' tests/js/smoke-seguranca.test.mjs
      text: GET / com 200 passa (200 e 302 aceitos)
    - done: false
      test: node --test --test-name-pattern='não sobe' tests/js/smoke-seguranca.test.mjs
      text: Ambiente que não responde dentro de SMOKE_ESPERA_SEGUNDOS sai 2 sem rodar verificação
    - done: false
      test: '! grep -nE ''spawnSync|execFileSync|execSync'' tests/js/smoke-seguranca.test.mjs'
      text: Teste do smoke roda o script de forma assíncrona (spawnSync bloquearia o servidor do próprio teste)
    - done: false
      test: node --test tests/js/smoke-seguranca.test.mjs
      text: Suíte do smoke passando
deps:
    - TASK-006
    - TASK-011
    - TASK-020
    - TASK-023
    - TASK-025
    - TASK-027
    - TASK-028
files:
    - docker/etc/nginx/default.template.conf
    - docker/etc/nginx/default.conf
    - docker/etc/php/cron-jobs
    - scripts/smoke-seguranca.sh
    - tests/js/smoke-seguranca.test.mjs
id: TASK-031
linter: bash -n scripts/smoke-seguranca.sh
n45_version: 1.1.0
outcome: ""
persona: devops
phase: 'Phase 5 — Entrega: smoke de segurança, servidor e documentação'
roadmap: feat-0001-melhoria-seguranca
status: pending
tests: node --test tests/js/smoke-seguranca.test.mjs
title: Nginx sem segredo no log, cron da fila e smoke de segurança
updated_at: "2026-11-04 06:56:22"
---
## Contexto

Última fase da feature de segurança: o ambiente Docker precisa (1) não gravar no access log do nginx o caminho do webhook da Efí, que carrega o segredo (`POST /webhooks/efi/<EFI_WEBHOOK_SECRET>`, entregue na TASK-011), (2) manter o cron da fila de e-mail (`email/process` a cada 2 min, `email/retry` a cada 5 min, que agora saem com código 0/1 — TASK-012/TASK-013) e (3) ganhar um smoke de segurança executável, `scripts/smoke-seguranca.sh`, que sobe o ambiente pelo comando do RUNBOOK, bate nas rotas protegidas e reprova se algum status fugir do esperado.

**Defeito encontrado ao planejar (corrigido nesta task):** o `docker/docker-compose.yml` monta `./etc/nginx/default.conf` em `/etc/nginx/conf.d/default.conf` **e** roda `envsubst '$$NGINX_HOST $$NGINX_PORT' < default.template > /etc/nginx/conf.d/default.conf` ao subir. Como o destino é um bind mount, o `default.conf` do repositório é **sobrescrito** pelo template a cada `docker-compose up`. O template não tem os bloqueios que o `default.conf` tem (`/application/`, `/updates/`, `scripts|tests|docker|docs`, arquivos de projeto na raiz, `userImage|uploads` no bloqueio de execução em upload): na prática, o nginx do Docker serve sem eles e o `docs/servidor-web.md` afirma o contrário. A correção é tornar o template a **fonte única**: depois desta task, `envsubst` do template com `NGINX_HOST=localhost NGINX_PORT=8000` (valores do `docker/.env`) gera **byte a byte** o `default.conf` versionado — a sobrescrita vira no-op. O `docker-compose.yml` não muda.

Os cabeçalhos de segurança (CSP, HSTS etc.) vêm do hook PHP `security_headers`, não do nginx: para "manter cabeçalhos e `client_max_body_size` nas rotas de webhook" basta **não** criar `location` própria para `/webhooks/` — o webhook continua passando pelo `location /` → `index.php`, com o `client_max_body_size 64M` do `server`. O limite de 64 KB do corpo é do PHP (TASK-011), não do nginx.

Fora de escopo: senhas do `docker/.env`; mudar `docker-compose.yml`; documentação (TASK-032); `error_log` do nginx (registra a linha da requisição só em erro do próprio nginx — a TASK-032 documenta).

## Comportamento Esperado

**nginx (template e `default.conf`)**

| Entrada / Ação | Saída / Efeito esperado |
| --- | --- |
| `POST /webhooks/efi/abc123` | não aparece em `/var/log/nginx/access.log` (`$loggable` = 0) |
| `POST /index.php/webhooks/efi/abc123` | não aparece no access log (o `map` casa `webhooks/efi/` em qualquer ponto do `$request_uri`) |
| `POST /index.php/webhooks/mercadopago` | aparece no access log (`$loggable` = 1) |
| `GET /application/.env` | 403 (bloqueio que hoje só existe no `default.conf`, agora também no template) |
| `NGINX_HOST=localhost NGINX_PORT=8000 envsubst '$NGINX_HOST $NGINX_PORT' < default.template.conf` | idêntico ao `default.conf` (diff vazio) |
| `nginx -t` com o `default.conf` | `syntax is ok` / `test is successful` |

**cron (`docker/etc/php/cron-jobs`)**

| Linha | Resultado |
| --- | --- |
| `*/2 * * * * /usr/local/bin/php /var/www/html/index.php email/process >> /var/log/cron.log 2>&1` | mantida, agora com `2>&1` (erro fatal do PHP também vai para o log) |
| `*/5 * * * * /usr/local/bin/php /var/www/html/index.php email/retry >> /var/log/cron.log 2>&1` | idem |

**Smoke (`scripts/smoke-seguranca.sh`)** — 12 verificações, nesta ordem, `curl -s -o /dev/null -w '%{http_code}' --max-time 10 -X <MÉTODO> "$URL<caminho>"` sem corpo e sem token:

| # | Descrição | Método e caminho | Aceitos |
| --- | --- | --- | --- |
| 1 | processo | `GET /` | `200 302` |
| 2 | API sem token | `GET /index.php/api/v1` | `401` |
| 3 | webhook MP sem assinatura | `POST /index.php/webhooks/mercadopago` | `401` |
| 4 | webhook Asaas sem token | `POST /index.php/webhooks/asaas` | `401` |
| 5 | webhook Efí com segredo inválido | `POST /index.php/webhooks/efi/invalido` | `401` |
| 6 | login sem corpo | `POST /index.php/login/verificarLogin` | `403 422` |
| 7 | logout do painel por GET | `GET /index.php/login/sair` | `405` |
| 8 | logout da área do cliente por GET | `GET /index.php/mine/sair` | `405` |
| 9 | refresh do cliente sem corpo | `POST /index.php/api/v1/client/refresh` | `401` |
| 10 | troca de senha do cliente sem token | `PUT /index.php/api/v1/client/password` | `401` |
| 11 | abertura de OS do cliente sem token | `POST /index.php/api/v1/client/os` | `401` |
| 12 | edição de OS do operador sem token | `PUT /index.php/api/v1/os/1` | `401` |

| Cenário | Saída / código de saída |
| --- | --- |
| as 12 respostas dentro dos aceitos | 12 linhas começando com `OK`, nenhuma `FALHOU`, resumo, **exit 0** |
| `GET /index.php/login/sair` responde `200` | linha `FALHOU  logout do painel por GET  GET /index.php/login/sair → 200 (esperado: 405)`, demais 11 `OK`, **exit 1** |
| login responde `422` | `OK` (422 é aceito), exit 0 |
| login responde `500` | `FALHOU` na linha do login, exit 1 |
| rota não implementada responde `404` | `FALHOU` naquela linha, exit 1 |
| `GET /` nunca responde dentro de `SMOKE_ESPERA_SEGUNDOS` | mensagem `O ambiente não respondeu em <n>s em <URL>/`, nenhuma verificação rodada, **exit 2** |
| sem `docker-compose` nem `docker compose` (e sem `SMOKE_SEM_DOCKER=1`) | mensagem `docker-compose não encontrado`, **exit 2** |
| subiu pelo compose | `docker-compose down` roda no fim, inclusive em exit 1/2 (trap `EXIT`) |

## Estratégia de Teste

O script é shell novo, mas o comportamento que importa (veredito por rota e código de saída) é verificável sem Docker: um servidor HTTP do próprio Node responde os status de cada rota e o script roda com `SMOKE_SEM_DOCKER=1` apontando para ele. Arquivo: `tests/js/smoke-seguranca.test.mjs` (entra no `npm run test:js`, que roda `node --test tests/js/*.mjs`). Sem dependência nova: `node:http`, `node:child_process`, `node:test`, `node:assert/strict`.

**Armadilha não óbvia:** o servidor roda no mesmo processo do teste. `spawnSync`/`execFileSync` **bloqueiam o event loop** e o servidor nunca responde (o script fica esperando até o timeout). Use `spawn`/`execFile` assíncrono e aguarde o `close`.

**Skeleton canônico:**

```js
// Smoke de segurança (scripts/smoke-seguranca.sh, RF-60).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';

const ESPERADO = {
    'GET /': 302,
    'GET /index.php/api/v1': 401,
    'POST /index.php/webhooks/mercadopago': 401,
    'POST /index.php/webhooks/asaas': 401,
    'POST /index.php/webhooks/efi/invalido': 401,
    'POST /index.php/login/verificarLogin': 403,
    'GET /index.php/login/sair': 405,
    'GET /index.php/mine/sair': 405,
    'POST /index.php/api/v1/client/refresh': 401,
    'PUT /index.php/api/v1/client/password': 401,
    'POST /index.php/api/v1/client/os': 401,
    'PUT /index.php/api/v1/os/1': 401,
};

async function servidor(respostas) {
    const srv = http.createServer((req, res) => {
        res.statusCode = respostas[`${req.method} ${req.url}`] ?? 404;
        res.end();
    });
    await new Promise((ok) => srv.listen(0, '127.0.0.1', ok));
    return srv;
}

function rodarSmoke(url, espera = '3') {
    return new Promise((ok) => {
        const filho = spawn('bash', ['scripts/smoke-seguranca.sh'], {
            env: { ...process.env, SMOKE_SEM_DOCKER: '1', SMOKE_URL: url, SMOKE_ESPERA_SEGUNDOS: espera },
        });
        let saida = '';
        filho.stdout.on('data', (d) => (saida += d));
        filho.stderr.on('data', (d) => (saida += d));
        filho.on('close', (codigo) => ok({ codigo, saida }));
    });
}

test('smoke sai 0 com todas as rotas no veredito esperado', async (t) => {
    const srv = await servidor(ESPERADO);
    t.after(() => srv.close());
    const { codigo, saida } = await rodarSmoke(`http://127.0.0.1:${srv.address().port}`);
    assert.equal(codigo, 0, saida);
    assert.equal(saida.split('\n').filter((l) => l.startsWith('OK')).length, 12);
    assert.doesNotMatch(saida, /FALHOU/);
});

test('smoke sai 1 quando GET login/sair responde 200', async (t) => {
    const srv = await servidor({ ...ESPERADO, 'GET /index.php/login/sair': 200 });
    t.after(() => srv.close());
    const { codigo, saida } = await rodarSmoke(`http://127.0.0.1:${srv.address().port}`);
    assert.equal(codigo, 1, saida);
    assert.match(saida, /FALHOU.*GET \/index\.php\/login\/sair.*200/);
    assert.equal(saida.split('\n').filter((l) => l.startsWith('OK')).length, 11);
});
```

Casos a cobrir além dos dois acima (nomes **exatos**, os critérios filtram por eles):

- `login aceita 422 no lugar de 403` — `verificarLogin` → 422: exit 0, 12 `OK`.
- `login com 500 reprova` — `verificarLogin` → 500: exit 1, `FALHOU` na linha do `verificarLogin`.
- `GET / com 200 passa` — `GET /` → 200: exit 0.
- `smoke sai 2 quando o ambiente não sobe` — `SMOKE_URL` numa porta sem servidor (abrir e fechar um servidor para obter uma porta livre), `SMOKE_ESPERA_SEGUNDOS=2`: exit 2, saída contém `não respondeu`, nenhuma linha `OK`.

**Controle negativo (red brownfield):** com o script pronto, troque temporariamente o esperado do primeiro teste para `11` linhas `OK` → tem que ficar **red**; volte para `12`. Faça o mesmo com o código de saída do teste de falha (`0` → red → `1`).

**Refatoração:** Nenhuma no código de produção.

## O que Implementar

### Arquivos a Criar ou Modificar

| Arquivo | Ação | Descrição |
| --- | --- | --- |
| `docker/etc/nginx/default.template.conf` | Modificar | Fonte única: `map` do `$loggable`, `access_log … if=$loggable` e os bloqueios que hoje só estão no `default.conf` |
| `docker/etc/nginx/default.conf` | Modificar | Igual ao template renderizado com `localhost`/`8000` (inclui o `map` e o `access_log`) |
| `docker/etc/php/cron-jobs` | Modificar | Mantém as duas linhas; acrescenta `2>&1` |
| `scripts/smoke-seguranca.sh` | Criar | Smoke de segurança (executável, `#!/usr/bin/env bash`) |
| `tests/js/smoke-seguranca.test.mjs` | Criar | Testes do veredito e dos códigos de saída do smoke |

### Detalhamento Técnico

1. **Template como fonte única.** Reescreva `docker/etc/nginx/default.template.conf` partindo do conteúdo atual do `default.conf` (que é o mais completo), trocando só `listen 8000` → `listen ${NGINX_PORT}`, `listen [::]:8000` → `listen [::]:${NGINX_PORT}` e `server_name localhost` → `server_name ${NGINX_HOST}`. Nenhuma outra variável no formato `${...}` ou `$NOME` que o `envsubst` conheça pode aparecer — o compose passa a lista explícita `'$$NGINX_HOST $$NGINX_PORT'`, então `$loggable`, `$request_uri`, `$uri`, `$document_root` etc. passam intactos.
2. **`map` e `access_log`.** No topo do arquivo, **fora** do bloco `server` (os arquivos de `conf.d/` são incluídos dentro de `http {}`, onde `map` é permitido), e dentro do `server`, logo após `client_max_body_size 64M;`:

   ```nginx
   # O webhook da Efí leva o segredo no caminho (POST /webhooks/efi/<segredo>):
   # essas requisições ficam fora do access log (docs/webhooks-de-pagamento.md).
   map $request_uri $loggable {
       ~webhooks/efi/ 0;
       default        1;
   }

   server {
       ...
       client_max_body_size 64M;
       access_log /var/log/nginx/access.log combined if=$loggable;
   ```

   Use o formato `combined` (embutido no nginx; `main` depende do `nginx.conf` da imagem). O `access_log` no nível do `server` substitui o herdado do `http`, então vale para todos os `location`. **Não** crie `location` para `/webhooks/`.
3. **`default.conf` = template renderizado.** Gere-o com `NGINX_HOST=localhost NGINX_PORT=8000 envsubst '$NGINX_HOST $NGINX_PORT' < docker/etc/nginx/default.template.conf > docker/etc/nginx/default.conf` e confira com `diff`. Valide com `docker run --rm --add-host php-fpm:127.0.0.1 -v "$(pwd)/docker/etc/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro" nginx:alpine nginx -t` (o `--add-host` existe porque `nginx -t` resolve o upstream `php-fpm`).
4. **cron.** Acrescente ` 2>&1` ao fim das duas linhas de `docker/etc/php/cron-jobs`; frequências e comandos inalterados. A última linha termina com quebra de linha (o cron ignora a última linha sem `\n`).
5. **Smoke.** `scripts/smoke-seguranca.sh`, bash, com bit de execução (`chmod +x` e `git add --chmod=+x`). Comentário de cabeçalho em pt-BR com: pré-requisito (Map-OS instalado no Docker, `application/.env` com `API_ENABLED=true`), uso e códigos de saída (0 tudo certo, 1 algum veredito falhou, 2 ambiente não subiu/sem compose). Estrutura:
   - `set -u` (não `set -e`: o `curl` devolve ≠0 em situações esperadas). `RAIZ="$(cd "$(dirname "$0")/.." && pwd)"`.
   - Variáveis: `SMOKE_URL` (padrão `http://localhost:8000`, sem barra final), `SMOKE_ESPERA_SEGUNDOS` (padrão `90`), `SMOKE_SEM_DOCKER` (padrão `0`; `1` = não sobe nem derruba, usa o que já está no ar — é o modo dos testes).
   - Compose: função que usa `docker-compose` se existir (é o comando do RUNBOOK: `cd docker && docker-compose up -d` / `docker-compose down`), senão `docker compose` se `docker compose version` funcionar, senão imprime `docker-compose não encontrado` em stderr e sai 2. Sem `SMOKE_SEM_DOCKER=1`: `(cd "$RAIZ/docker" && <compose> up -d)` — falha → exit 2; registre `trap` em `EXIT` que roda `(cd "$RAIZ/docker" && <compose> down)`, **depois** de subir com sucesso.
   - Espera: laço até `curl -sf -o /dev/null --max-time 5 "$SMOKE_URL/"` dar certo (3xx conta como sucesso no `-f`), 1 s entre tentativas, limitado por `SMOKE_ESPERA_SEGUNDOS`; estourou → `O ambiente não respondeu em ${SMOKE_ESPERA_SEGUNDOS}s em ${SMOKE_URL}/` e exit 2.
   - `checar "<descrição>" <MÉTODO> <caminho> <aceito> [<aceito>...]`: obtém o status com `curl -s -o /dev/null -w '%{http_code}' --max-time 10 -X "$metodo" "$SMOKE_URL$caminho"`; status em algum aceito → `printf 'OK      %s  %s %s → %s\n'`; senão → `printf 'FALHOU  %s  %s %s → %s (esperado: %s)\n'` e incrementa o contador de falhas. As linhas começam exatamente com `OK` ou `FALHOU` (o teste conta por prefixo).
   - As 12 chamadas da tabela do Comportamento Esperado, na ordem. No fim: `Smoke de segurança: <n> verificação(ões) com falha` ou `Smoke de segurança: tudo certo`; `exit 1` se houve falha, senão `exit 0`.
   - Nada de segredo, token ou corpo de resposta na saída.
6. **Teste** conforme a Estratégia de Teste. Rode `node --test tests/js/smoke-seguranca.test.mjs`.

**Contrato com quem consome:** a TASK-032 documenta `scripts/smoke-seguranca.sh` (uso, pré-requisito `API_ENABLED=true`, códigos 0/1/2, `SMOKE_URL`/`SMOKE_SEM_DOCKER`) e o `map … $loggable` com `access_log … if=$loggable` como receita de nginx para esconder o caminho da Efí. Mantenha esses nomes.

## Trade-offs

| Decisão | Alternativa descartada | Motivo |
| --- | --- | --- |
| Template como fonte única e `default.conf` = template renderizado | Tirar o bind mount do `default.conf` no compose | Sem mexer no compose a sobrescrita vira no-op e o arquivo citado pela documentação continua sendo o efetivo |
| `map` casando `webhooks/efi/` em qualquer ponto do `$request_uri` | `location ^~ /webhooks/efi/ { access_log off; }` | `location` própria duplicaria o roteamento para o `index.php` e não cobriria `/index.php/webhooks/efi/…` |
| Smoke testado com servidor Node falso | Rodar o smoke real nos critérios | O ambiente real exige Docker com compose e instalação concluída com `API_ENABLED=true`, inexistentes no worktree; o veredito e os códigos de saída são o que muda de uma versão para outra |
