---
created_at: "2026-11-03 15:25:48"
from: security
n45_version: 1.1.0
spec_id: feat-0001-melhoria-seguranca
---
### Cenários de Abuso (red team)

Grafo: rotas `/api/v1/*` e `/client/*` marcadas "autenticado"; webhooks serão a primeira superfície pública sem sessão, sem CSRF e sem JWT. Segredos hoje: `API_JWT_KEY`, `APP_ENCRYPTION_KEY`; entram `EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN`.

| Vetor | Ataque (o que o atacante faz) | Mitigação exigida | Severidade |
| --- | --- | --- | --- |
| Webhook forjado | POST anônimo com "pagamento confirmado" para baixar cobrança/financeiro sem pagar | Autenticar cada gateway (segredo/assinatura, comparação em tempo constante) e **reconsultar** o pagamento na API do gateway; ignorar valor/status do corpo; fail-closed | Crítica |
| Webhook: segredo vazando | Segredo na URL da Efí aparece em log de acesso/proxy/referer | Segredo só de `.env`, mascarado em logs; comparar com `hash_equals`; segredo ausente/vazio no `.env` = endpoint responde 503/401, nunca aceita | Alta |
| Webhook: replay e duplicata | Reenviar evento válido para baixar duas vezes ou gerar lançamento duplicado | Idempotência por UNIQUE (gateway + evento_id), insert antes de processar; baixa + evento na mesma transação; evento repetido = 200 sem efeito | Alta |
| Webhook: assinatura MP | `x-signature` sem validar timestamp permite replay antigo; `data.id` manipulado | Validar HMAC sobre o manifesto oficial (id, request-id, ts), tolerância curta de timestamp; `data.id` usado só como consulta autenticada à API | Alta |
| Webhook: mapeamento IDOR | Evento de um pagamento qualquer baixa cobrança de outro cliente | Cobrança localizada pelo id externo retornado pela consulta ao gateway; 404 se desconhecida; conferir que valor consultado bate com a cobrança | Alta |
| Webhook: DoS/abuso | Flood no endpoint público e consultas à API do gateway (custo/ban) | Rate limit por IP (reaproveitar `api_rate_limit`), limite de body pequeno, timeout curto na consulta ao gateway, rejeitar antes de consultar se autenticação falhar | Média |
| `PUT /api/v1/os/{id}` | Operador com permissão de edição altera OS faturada/cancelada e zera `faturado` (fraude contábil) | Checagem de OS editável (403) e `faturado` fora da allow-list de campos | Alta |
| Mass assignment API | Campos extras no body (`faturado`, `status`, `clientes_id`, `valorTotal`) | Bind por allow-list em todos os PUT/POST da API v1 | Alta |
| Senha inicial = CPF/CNPJ | Atacante com CPF (dado semipúblico) entra na conta e vê OS, cobranças, anexos | Senha aleatória (CSPRNG, entropia alta), `senha_trocar` obrigatório, bloqueio server-side de todas as rotas até a troca; exibição única; **senha nunca em log/audit** | Crítica |
| Senha inicial por e-mail | Senha em claro em e-mail/fila `email_queue` (corpo persistido) | Preferir fluxo de definição de senha por link de uso único expirando; se senha em e-mail, expira no primeiro uso e o corpo não fica em claro após envio nem em log | Alta |
| JWT do cliente longo | Token roubado válido 30 dias, sem revogação | Access ≤ 1 h; refresh rotativo com detecção de reuso revogando a família; logout revoga; troca de senha e exclusão do cliente revogam; refresh armazenado só como hash | Alta |
| Confusão de token | Token de cliente aceito em rota de operador (ou o inverso) / refresh de cliente em tabela de usuário | Validar `type` do token em cada base de controller; `api_refresh_tokens` com tipo e consulta sempre filtrada por tipo | Alta |
| Operador desativado | Grupo desativado continua operando via JWT/refresh | Conferir grupo ativo no `logged_user` e no refresh; 401 | Alta |
| Spam de OS pelo cliente | Cliente autenticado (ou conta comprometida) cria OS em massa e inunda e-mail da equipe | Teto 10/dia por cliente verificado no servidor, contagem em transação (evitar corrida), 429 + `Retry-After`; e-mail agregado/limitado | Média |
| Sessão web do cliente órfã | Cliente excluído/senha trocada segue logado | Revalidar existência + versão de sessão no banco por requisição; fail-closed se erro de consulta | Alta |
| Logout por GET | CSRF de logout / `<img src=/login/sair>` | POST com CSRF; GET removido (responder 405) | Baixa |
| Ativação de 2FA | Sessão roubada brute-force do código TOTP | `Limite_login::confirmarSenha` + limite de tentativas no código; exigir senha atual | Média |
| Reset de senha: corrida | Duas requisições simultâneas com o mesmo token trocam a senha duas vezes | Marcar como usado com UPDATE condicional atômico (`WHERE usado=0`, checar affected_rows) antes da troca | Média |
| Seed `admin@admin.com/123456` | Instalação em produção com credencial conhecida | Seed só em `development`/`testing`; instalador em produção exige credencial definida pelo operador | Alta |
| Fila de e-mail | `print_debugger()` grava corpo com link de reset em log; corrida de reserva duplica envio | Log só cabeçalhos; corpo nunca em log; reserva atômica; lock de execução; limite de tentativas | Alta |
| Vazamento por erro | `show_exception()` expõe arquivo/linha; mensagem crua do gateway ao usuário | `\Throwable` → 500 JSON genérico, detalhe só em `log_message`; mensagem de gateway mapeada para texto próprio | Alta |
| Logs com dado sensível | Log de gateway grava token, CPF, payload de cartão, segredo do webhook | Allow-list de campos logáveis; mascarar token/CPF/senha/segredo; nunca logar corpo bruto de webhook | Alta |
| `.htaccess` gerado | Sintaxe Apache 2.2 ignorada em 2.4 e `@` esconde falha de gravação: pasta de uploads exposta/executável | Bloco `mod_authz_core` (e compat), gravação conferida com log; falha na proteção = erro explícito, não silêncio; reforçar que o storage seja servido sem execução | Alta |
| Exclusão parcial | Delete falha no meio, deixando financeiro/estoque inconsistente e mostrando sucesso | Transação + conferência do retorno + rollback; sem sucesso falso | Média |
| `perPage` sem teto | `perPage=10000000` esgota memória/banco | Teto 100 e offset corrigido (sem multiplicação dupla); validar inteiro positivo | Média |
| Datas `from`/`to` | Entrada malformada vira query lenta/erro vazando SQL | Validar formato e janela máxima; 422 | Baixa |
| AJAX com sessão expirada | 302→HTML tratado como sucesso: usuário crê ter salvo; ou redirect aberto | 401 JSON; `redirect` sempre caminho interno fixo (login), nunca derivado de input | Média |
| Login com 200 em falha | Clientes de API e monitoria não distinguem brute-force; sem `Content-Type` JSON | 401/422/429 com JSON; mensagem genérica sem enumerar usuário | Baixa |
| `notification_url` fixa em terceiro | Notificações de pagamento vão para domínio de outro dono | URL montada de `base_url` + segredo do `.env`; remover domínios fixos | Crítica |
| Timeout ausente (MP/Asaas) | Gateway lento prende workers PHP (DoS) | Timeouts de conexão e resposta curtos configuráveis | Média |

### Requisitos de Implementação — Crítico / Alto

- Endpoints de webhook: fora do CSRF e da sessão por exclusão explícita e mínima (somente essas rotas); autenticação do gateway obrigatória e verificada **antes** de qualquer consulta externa ou escrita; falha de autenticação = 401 sem detalhe; comparação de segredos em tempo constante.
- Segredo do webhook ausente no `.env` desabilita o endpoint daquele gateway (fail-closed) e não gera `notification_url`.
- Nunca confiar no corpo: status, valor e identificação da cobrança vêm da consulta autenticada ao gateway; valor consultado deve conferir com o da cobrança antes de baixar.
- Idempotência por chave única; registro do evento e baixa (cobrança + lançamento) na mesma transação; falha técnica = rollback + 5xx para o gateway retentar; evento duplicado = 200.
- Segredo do webhook da Efí na URL: nunca em log de aplicação; recomendar no RUNBOOK mascarar o path no log do servidor web; preferir mecanismo adicional (mTLS/IP do gateway) quando disponível, como defesa em profundidade.
- `PUT /api/v1/os/{id}`: bloqueio de OS fechada (403) e `faturado` fora do bind; mesma verificação nos sub-recursos (produtos, serviços, anexos, anotações) e nos `DELETE` da OS — auditar todas as rotas filhas, não só o PUT.
- Bind por allow-list de campos em toda escrita da API v1 (OS, clientes, produtos, serviços, usuários); `usuarios` jamais aceita `permissoes_id`/grupo sem permissão administrativa explícita (escalonamento de privilégio).
- Senha inicial do cliente: CSPRNG, comprimento e entropia fortes, hash `password_hash`; troca obrigatória aplicada **no servidor** em toda rota da área do cliente e da API (não só na UI); a senha em claro nunca vai para log, audit, flashdata persistido ou corpo de e-mail retido.
- Resposta da API de criação de cliente expõe `senha_inicial` uma única vez; não reexibir em consultas posteriores; resposta com `Cache-Control: no-store`.
- JWT do cliente: expiração máxima 1 h imposta em código (teto, ignorando `.env` maior); refresh opaco aleatório, armazenado como hash, rotativo, com detecção de reuso → revoga toda a família; revogação também ao trocar senha, excluir cliente e logout; refresh de cliente separado por tipo e nunca aceito em endpoint de operador.
- Validação do `type` do token em cada controller base; rota sem verificação de tipo é defeito.
- `logged_user` e refresh do operador: grupo/usuário desativado = 401; checagem a cada requisição, não só no login.
- Sessão web do cliente revalidada no banco por requisição (existência, ativo, versão de sessão); fail-closed em erro de banco.
- Limite de OS/dia por cliente aplicado no servidor com contagem atômica; resposta 429 com `Retry-After`.
- Seed padrão `admin@admin.com/123456` condicionado a `development`; instalação de produção sem credencial conhecida.
- `REST_Controller`: `\Throwable` capturado, log com contexto, resposta genérica; nada de arquivo/linha/SQL/stack ao cliente em qualquer ambiente exceto `development`; `display_errors` desligado em produção.
- Mensagens de gateway nunca repassadas cruas; log sem token/CPF/segredo/corpo bruto.
- Fila de e-mail: reserva atômica por lote com marcador por execução, lock entre execuções do cron, tentativas máximas (5), corpo/links de reset nunca em log.
- Reset de senha: consumo atômico do token (UPDATE condicional + affected_rows) antes da troca; token com expiração curta e armazenado como hash (confirmar).
- `.htaccess`/`index.html` de proteção: falha de gravação é erro registrado e visível ao administrador; sintaxe compatível com Apache 2.4; confirmar que nginx do `docker/` nega execução em `uploads/` e negue acesso direto aos anexos privados (download só por controller com checagem de dono).
- Cada fluxo de segurança com teste do caso negado: assinatura inválida, segredo ausente, evento repetido, valor divergente, OS fechada, rota filha de OS fechada, grupo desativado, refresh reutilizado, tipo de token trocado, troca de senha pendente, limite de OS, 401 em AJAX.

### Requisitos de Implementação — Médio / Baixo

- Logout por POST com CSRF (GET responde 405); ativação de 2FA com `confirmarSenha` e limite de tentativas do código.
- Rate limit dos webhooks e do `client/auth`/`refresh` reaproveitando o existente; body máximo pequeno nos webhooks.
- Timeouts curtos de conexão/resposta nos SDKs do Mercado Pago e Asaas (e conferir Efí).
- `perPage` teto 100 e offset corrigido; `from`/`to` validados com janela máxima; 422.
- Login com JSON e status (401/422/429) mantendo mensagem genérica que não enumera usuários; nome do token CSRF vindo da config.
- `redirect` do 401 AJAX sempre fixo e interno.
- Exclusões em transação com conferência de retorno; `save_queries` desligado em produção; `@` substituídos por checagem + log.
- Eventos de segurança logados (webhook rejeitado, reuso de refresh, limite excedido, 2FA falho) sem dado sensível, para detecção.
- Itens do checklist não aplicáveis: upload e SSRF novos não existem nesta feature (consulta ao gateway usa SDK com host fixo do provedor, nunca URL vinda do payload do webhook — requisito: proibido usar URL/host do corpo); CORS e security headers/CSP/HSTS já cobertos hoje, sem alteração, exceto garantir que as novas rotas de webhook respondam com os mesmos headers e `no-store`; XSS: os avisos de senha gerada e mensagens de erro devem ir pelo encode de saída existente (`check-escape` não pode subir).

### Para tasks

- `50a8844c7d`: webhooks (autenticação por gateway, reconsulta, idempotência transacional, fail-closed sem segredo, sem usar host do payload); `PUT/DELETE` de OS e sub-rotas bloqueando OS fechada; allow-list de campos em toda escrita da API v1; senha inicial CSPRNG + troca obrigatória no servidor; refresh rotativo com reuso/revogação, validação de `type` do token; grupo desativado = 401; revalidação da sessão web do cliente; limite de OS/dia atômico; reset de token atômico; seed só em development; `\Throwable` genérico; transações nas exclusões; fila de e-mail atômica e sem corpo em log; log mascarado; timeouts; 403 vs 401; teto de `perPage`; testes do caso negado.
- `844dd534f4`: 401 JSON de AJAX tratado com redirect fixo e aviso; senha gerada exibida com encode de saída e sem persistir em storage do navegador; formulário de troca de senha sem autofill sensível e sem log no console; logout como form POST com CSRF; timeout/erros do `http.js` sem expor detalhe interno; `check-escape` e `check-inline-script` sem regressão.
- `82654b3833`: variáveis de segredo dos webhooks no `.env.example` (sem valor real) e validação de presença; rotas de webhook excluídas só do CSRF e mantendo headers de segurança, rate limit e limite de body no nginx/Apache; mascarar o path do webhook Efí no access log; `.htaccess` Apache 2.4 e equivalente nginx negando execução/acesso direto a uploads; cron de e-mail com lock e código de saída; RUNBOOK com cadastro das URLs, rotação de segredos e HTTPS obrigatório.
