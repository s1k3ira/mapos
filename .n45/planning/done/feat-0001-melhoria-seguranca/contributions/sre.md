---
created_at: "2026-11-03 15:25:32"
from: sre
n45_version: 1.1.0
spec_id: feat-0001-melhoria-seguranca
---
### Resiliência

- Circuit breaker: não aplicável por padrão. App PHP síncrono sem estado em memória entre requisições; um breaker não persiste entre processos. Em vez disso, cada chamada a gateway tem timeout e falha rápida com mensagem tratada.
- Timeout (requisito do contrato): chamadas a Mercado Pago, Asaas e Efí com timeout de conexão e de resposta configuráveis por `.env` (teto de poucos segundos para o webhook, que precisa responder ao gateway rapidamente). `fetch` do frontend com timeout padrão.
- Retry:
  - Webhook: **o retry é do gateway**, não nosso. O endpoint responde 5xx apenas em falha transitória (gateway de consulta fora, banco indisponível) para forçar reentrega; responde 200 para evento repetido, evento desconhecido já tratado e payload irrelevante; 401 para autenticação inválida (não reentregar). Falha de consulta ao gateway nunca marca o evento como processado.
  - Fila de e-mail: máximo de 5 tentativas por e-mail, depois `failed` definitivo; o intervalo entre tentativas é o próprio ciclo do cron (`email/retry`, 5 min). Sem retry dentro do mesmo processo.
  - Chamadas síncronas ao gateway iniciadas pelo usuário: sem retry automático (risco de cobrança duplicada); o usuário repete.
- Falha parcial: toda escrita multi-tabela (exclusões, baixa de cobrança + financeiro, faturamento) em transação única; falha → rollback, log técnico, mensagem de erro ao usuário. Nunca sucesso falso.
- Degradação: gateway fora deixa cobrança em `pendente`; pagamento segue recuperável pela reentrega do webhook e pela consulta manual existente (`Cobrancas::atualizar`). Fila de e-mail com SMTP fora acumula e não bloqueia o fluxo do usuário.
- Cron concorrente: lock entre execuções de `email/process` e `email/retry`; se o lock está ocupado, a execução sai sem erro (código 0) e loga "ignorado". Reserva atômica de lote evita envio duplicado. Lock/reserva expirado (processo morto) volta a ser elegível após um tempo-limite definido pelo executor.
- Graceful shutdown: não aplicável ao PHP-FPM/CLI no sentido de drain; o requisito é que o cron que receba SIGTERM não deixe e-mail em estado `reservado` para sempre (a expiração da reserva cobre isso) e que a baixa de pagamento seja atômica (transação).
- Erro global: qualquer `\Throwable` na API vira 500 `{status:false, message:"Erro interno"}` com log; nunca stack trace, arquivo ou linha ao cliente em produção.

### Health Checks

O projeto hoje só tem verificação por requisição à raiz e a `/index.php/api/v1` (RUNBOOK). Esta feature **não exige** endpoints novos de liveness/readiness; recomendação mínima e opcional: manter os checks do RUNBOOK e incluir os webhooks no smoke.

| Endpoint | Tipo | Verifica |
| -------- | ---- | -------- |
| `/` | liveness | app responde (200 ou redirect para login) |
| `/index.php/api/v1` | liveness da API | 401/403 sem token; 404 se `API_ENABLED=false` |
| readiness | não aplicável nesta feature | banco é verificado pelo `mysqladmin ping` do RUNBOOK |

### Verificação de Subida (smoke)

Sobe pelo MESMO comando do RUNBOOK (`cd docker && docker-compose up -d`), espera o `curl -sf http://localhost:8000/`, bate nas rotas abaixo sem auth e sem corpo, derruba (`docker-compose down`) e sai com código diferente de zero se qualquer veredito falhar.

| O quê | Alvo | Veredito |
| ----- | ---- | -------- |
| processo de pé | `/` | 200 ou 302 para login |
| API | `/index.php/api/v1` | 401/403 |
| webhook Mercado Pago | `POST /webhooks/mercadopago` | 401 (sem assinatura); nunca 404 nem 5xx |
| webhook Asaas | `POST /webhooks/asaas` | 401 (sem token); nunca 404 nem 5xx |
| webhook Efí | `POST /webhooks/efi/{segredo}` com segredo inválido | 401; nunca 404 nem 5xx |
| login painel | `POST /login/verificarLogin` sem corpo | 422 (ou 429); nunca 404 nem 5xx |
| logout | `POST /login/sair` e `POST /mine/sair` sem CSRF | recusa (403/419/redirect); GET deixa de funcionar; nunca 5xx |
| API cliente | `POST /api/v1/client/auth`, `/client/refresh`, `/client/logout` sem corpo/token | 401/422; nunca 404 nem 5xx |
| API cliente | `PUT /api/v1/client/password`, `POST /api/v1/client/os` sem token | 401; nunca 404 nem 5xx |
| API operador | `PUT /api/v1/os/{id}` sem token | 401 (rota existe); só não-5xx |

Atenção: a rota de webhook fora do CSRF/sessão não pode cair em 404 por prefixo duplicado de roteamento; o smoke existe para pegar isso. Com `API_ENABLED=false` as rotas de API respondem 404 legítimo: o smoke roda com a API ligada.

### Log Estruturado

Campos obrigatórios por evento/requisição relevante: nível, timestamp, origem (gateway, controller ou job), id do evento/cobrança/e-mail/OS quando houver, resultado (ok, ignorado, falha), código HTTP devolvido, duração da chamada externa. Tudo via `log_message` (CI3 não tem log estruturado nativo; padrão de linha única com pares chave=valor).

Eventos críticos a logar:
- Webhook: recebido, autenticação recusada (sem o valor do segredo), evento repetido ignorado, pagamento confirmado e baixado (cobrança, lançamento), falha de consulta ao gateway.
- Falha de gateway (todas), com timeout e código de status.
- Rollback de transação (qual operação e id).
- Exceção não tratada na API (classe e mensagem; stack só no log do servidor).
- Cron de e-mail: início, fim, quantidade processada/enviada/falha/descartada, lock ocupado, e-mail que chegou ao máximo de tentativas.
- Login bloqueado (429), reuso de refresh token do cliente (revogação de família, nível de alerta alto: indício de roubo de token), troca de senha obrigatória concluída.

Nunca logar: corpo de e-mail e links de redefinição, senha (inclusive a inicial gerada), tokens JWT/refresh, segredos de webhook (`EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN`) e a URL do Efí com o segredo no caminho (mascarar), payload bruto do gateway com dados de pagador, CPF/CNPJ completo.

### Métricas e Alertas

Sem stack de métricas no projeto (STACK não prevê Prometheus). Requisito: a feature precisa ser observável por log; os sinais abaixo devem ser extraíveis por `grep`/contagem de linhas de log. Implantar coletor está fora do escopo.

RED (endpoints críticos): webhooks, `/api/v1/client/auth`, `/api/v1/client/refresh`, `/login/verificarLogin` — taxa, % de erro (4xx de autenticação separado de 5xx), duração p95. Não aplicável aos demais.
USE: fila `email_queue` — utilização (pendentes), saturação (idade do mais antigo pendente), erros (`failed`). Banco e disco: não aplicável a esta feature.

| Sev. | Sinal | Limiar sugerido | Resposta (RUNBOOK) |
| ---- | ----- | --------------- | ------------------ |
| P1 | Webhook respondendo 5xx de forma contínua | mais de 5 min sem 200 com pagamentos pendentes | verificar banco e acesso à API do gateway; gateway reentrega |
| P2 | Rajada de 401 em webhook | acima de linha de base | segredo rotacionado ou tentativa de forja; conferir `.env` e cadastro no gateway |
| P2 | Cron de e-mail sem executar | nenhuma linha de fim de ciclo em 15 min | conferir cron, lock preso |
| P3 | E-mails `failed` definitivos | qualquer aumento | revisar SMTP; reenfileirar manualmente |
| P2 | Reuso de refresh token do cliente | qualquer ocorrência | investigar conta; família já revogada |
| P3 | 500 "Erro interno" na API | acima de linha de base | ler log pela exceção |
| P4 | Pendências de cobrança sem webhook há mais de X horas | revisão diária | conferir cadastro da URL no gateway |

O RUNBOOK deve ganhar: como cadastrar a URL de webhook em cada gateway, rotacionar segredos, destravar lock do cron e reprocessar evento.

### Para tasks

- `50a8844c7d`: timeouts dos gateways via `.env`; webhook respondendo 200/401/5xx conforme a regra de reentrega (5xx só em falha transitória, nunca marca processado); `log_message` com os campos e a lista "nunca logar" acima; `\Throwable` global na API; rollback com log; lock e reserva atômica no cron com saída 0 quando lock ocupado e código diferente de zero em falha; log de reuso de refresh em nível alto.
- `82654b3833`: script de smoke (sobe pelo comando do RUNBOOK, bate nas rotas da tabela, derruba, sai com código); atualizar RUNBOOK com cadastro de webhook, segredos novos no `.env.example` e health checks; garantir que o cron do Docker (`docker/etc/php/cron-jobs`) continue chamando `email/process` e `email/retry` com a nova semântica de saída.
