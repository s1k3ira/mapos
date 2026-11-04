---
created_at: "2026-11-03 15:26:15"
from: data-architect
n45_version: 1.1.0
spec_id: feat-0001-melhoria-seguranca
---
### Chaves & Identidade

| Entidade | PK | Racional |
| --- | --- | --- |
| `webhook_eventos` | `id` `bigint` unsigned auto-increment | Tabela de log de crescimento contínuo, só escrita pelo servidor, nunca exposta em rota: sem risco de enumeração, e sequencial mantém a localidade do índice. Identidade de negócio = UNIQUE (`gateway`, `evento_id`). |
| `api_refresh_tokens` (cliente) | `id` existente (`INT`) | Reutilizar a tabela; a identidade de rotação continua em `familia` + `token_hash`. |
| `email_queue` | `id` existente | Sem mudança. |

Demais tabelas seguem o padrão do projeto (surrogate sequencial).

### Correções de Schema

Decisão de modelagem do refresh do cliente (pergunta em aberto do discovery): **reutilizar `api_refresh_tokens`** com coluna discriminadora, em vez de tabela própria. O fluxo (rotação, `usado_em`, revogação de família, hash SHA-256) é idêntico; duplicar tabela duplica `Refresh_token` e o risco de divergência de segurança. Custo aceito: `usuario_id` passa a ser "dono do token" genérico, então a coluna ganha um discriminador e o índice de busca passa a incluí-lo. Sem FK (o projeto não tem FK nessa tabela e o dono é polimórfico); a integridade é por revogação explícita ao excluir/desativar a conta.

```sql
-- api_refresh_tokens: dono polimórfico
ALTER TABLE api_refresh_tokens
  ADD COLUMN tipo ENUM('usuario','cliente') NOT NULL DEFAULT 'usuario' AFTER id;
-- backfill implícito pelo DEFAULT: todas as linhas existentes são de operador
CREATE INDEX api_refresh_tokens_dono ON api_refresh_tokens (tipo, usuario_id);
-- (o índice simples antigo em usuario_id passa a ser redundante; remover na mesma migration)

-- clientes
ALTER TABLE clientes
  ADD COLUMN senha_trocar TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN sessao_versao INT UNSIGNED NOT NULL DEFAULT 0;

-- email_queue
ALTER TABLE email_queue
  ADD COLUMN tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN reservado_por CHAR(32) NULL,
  ADD COLUMN reservado_em DATETIME NULL;

-- webhook_eventos
CREATE TABLE webhook_eventos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gateway ENUM('efi','mercadopago','asaas') NOT NULL,
  evento_id VARCHAR(128) NOT NULL,
  cobranca_id INT NULL,
  payload_hash CHAR(64) NOT NULL,
  processado_em DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY webhook_eventos_gateway_evento (gateway, evento_id),
  KEY webhook_eventos_cobranca (cobranca_id)
) ENGINE=InnoDB;
```

Pontos de tipo e conformidade:
- `gateway` como ENUM de domínio fechado (valores em inglês/minúsculo, igual `cobrancas.payment_gateway`; conferir o valor real gravado lá e usar exatamente o mesmo).
- `email_queue.status` é ENUM com `pending,sending,sent,failed`; **os valores existentes bastam** (reserva = `sending` + `reservado_por`; esgotou tentativas = `failed`). Não criar status novo.
- `cobranca_id` referencia `cobrancas.idCobranca` (INT); o projeto não usa FK nesse módulo e o evento pode chegar para cobrança já removida (404 + registro mesmo assim), então **sem FK**, índice apenas.
- `cobrancas.charge_id` já é VARCHAR(255) (migration do Asaas), cobre ids grandes do Mercado Pago e `pay_*` do Asaas. Nenhuma alteração.
- `senha_trocar` é flag do estado "senha provisória"; é obrigatório inicializar os **clientes existentes com 0** (não forçar troca em massa em conta antiga) e marcar 1 apenas para contas criadas daqui em diante. Decisão de produto a confirmar na Spec: clientes legados cuja senha ainda é o CPF/CNPJ permanecem expostos; opção é um comando administrativo opcional (fora da migration) para marcar `senha_trocar=1` neles.
- `clientes.sessao_versao` segue exatamente o padrão já existente em `usuarios.sessao_versao`; `clientes.token_versao` **já existe** e é reaproveitado para derrubar access tokens na troca de senha, sem coluna nova.

**Colunas novas — justificar cada uma:**

| Coluna | Consumidor |
| --- | --- |
| `api_refresh_tokens.tipo` | `Refresh_token` (emitir/girar/revogar) distingue dono operador vs cliente; sem ela um id de cliente colide com id de usuário |
| `clientes.senha_trocar` | Middleware da API do cliente (403 `troca_de_senha_obrigatoria`) e tela "Defina sua nova senha" |
| `clientes.sessao_versao` | Revalidação da sessão web do cliente a cada requisição (`Mine`): troca de senha/exclusão encerra a sessão |
| `email_queue.tentativas` | Regra de máximo 5 tentativas, depois `failed` definitivo |
| `email_queue.reservado_por` | Reserva atômica por lote: o `UPDATE ... WHERE reservado_por IS NULL` marca, o `SELECT` lê só os próprios |
| `email_queue.reservado_em` | Recuperar reservas órfãs (processo morto no meio) após janela de expiração; sem isso um crash trava o e-mail para sempre |
| `webhook_eventos.*` | `gateway`+`evento_id`: idempotência; `cobranca_id`: rastreio/diagnóstico; `payload_hash`: detectar mesmo id com corpo diferente (auditoria); `processado_em`: distingue recebido de efetivamente aplicado (permite reprocessar o que falhou); `created_at`: base do TTL |

Sem `updated_at`, `deleted_at` ou `*_at` de transição além dos listados.

### Relacionamentos

| Relação | Cardinalidade | FK · ON DELETE |
| --- | --- | --- |
| `webhook_eventos` → `cobrancas` | N:1 | Sem FK. Evento tem de ser guardado mesmo se a cobrança sumiu (auditoria/idempotência); a ausência de FK é deliberada. |
| `api_refresh_tokens` → `clientes`/`usuarios` | N:1 polimórfico | Sem FK (dono polimórfico). Exclusão de cliente passa a chamar revogação de todos os refresh do cliente (`tipo='cliente'`), dentro da transação da exclusão. |
| `cobrancas` → `lancamentos` (baixa pelo webhook) | N:1 existente | Sem mudança. |

### Índices

| Tabela | Colunas | Tipo | Query que atende |
| --- | --- | --- | --- |
| `webhook_eventos` | `gateway, evento_id` | único | Idempotência: `INSERT` com violação = evento repetido |
| `webhook_eventos` | `cobranca_id` | simples | Diagnóstico por cobrança |
| `api_refresh_tokens` | `tipo, usuario_id` | composto | `revogarDoUsuario`/logout/exclusão por dono (substitui o simples em `usuario_id`) |
| `email_queue` | `status, reservado_por` | composto | `UPDATE` de reserva por lote e `SELECT` dos reservados; tabela é varrida por `status` a cada cron |
| `cobrancas` | `charge_id, payment_gateway` | composto | Webhook localiza a cobrança pelo id do gateway. **Verificar** se já existe índice em `charge_id`; se não, criar (o webhook faz essa busca em toda chamada). Se `charge_id` não for único entre gateways, a busca precisa do par. |

Para a contagem diária de OS por cliente (limite de 10): confirmar índice em `os(clientes_id, dataInicial)` ou equivalente na coluna de criação; **a OS não tem coluna de data de criação própria garantida**, então a Spec deve fixar qual coluna define "abertura no dia". Se a coluna não for indexável de forma útil, a contagem (bounded por cliente) ainda é barata com o índice da FK `clientes_id`. Sem tabela nova para o limite (decisão do discovery mantida): a janela é por dia-calendário contado na própria `os`. Limitação aceita: OS excluída pelo cliente/operador sai da contagem; documentar, pois o limite é de abuso e não de cobrança.

### Concorrência & Consistência

- **Webhook idempotente:** a unicidade (`gateway`,`evento_id`) é o árbitro; o fluxo é `INSERT` do evento -> se violou, 200 sem reprocessar -> reconsulta no gateway -> **uma transação** que marca `cobrancas.status`, baixa o `lancamentos` vinculado e seta `processado_em`. Falha na transação = rollback inclusive do registro do evento (ou `processado_em` nulo e reprocessável no reenvio do gateway); nunca "evento visto, pagamento não aplicado".
- **Baixa dupla:** a baixa do lançamento deve ser condicional (`UPDATE ... WHERE baixado = 0`) e conferir `affected_rows`; dois eventos distintos para a mesma cobrança (ex.: duas notificações Efí de status) não podem baixar duas vezes nem sobrescrever `data_pagamento`. Evento tardio/fora de ordem (ex.: `pending` depois de `paid`) não pode regredir status: regra de transição monotônica `pendente -> pago`.
- **Fila de e-mail:** reserva atômica (`UPDATE` condicionado a `status='pending'` e reserva vazia ou expirada) + lock entre execuções do cron; sem reserva expirada o e-mail trava. O contador `tentativas` é incrementado no mesmo `UPDATE` da reserva (conta tentativa iniciada, não concluída) para que crash também consuma tentativa e o retry não seja infinito.
- **Refresh do cliente:** rotação já protegida pela unicidade de `token_hash` e pelo marcador `usado_em`; o `UPDATE ... SET usado_em ... WHERE usado_em IS NULL` com checagem de `affected_rows` é o que evita a corrida de dois refresh simultâneos (herdado de `Refresh_token`, validar que vale para o tipo cliente).
- **Reset de senha:** token marcado como usado por `UPDATE ... WHERE usado = 0` com `affected_rows = 1` **antes** da troca; zero linhas = recusa. Mesma disciplina de compare-and-set.
- **Exclusões com várias escritas:** invariante cruzando tabelas (OS/venda/cliente/produto/serviço e dependentes) em uma única transação com conferência de `trans_status`; rollback + log + erro ao usuário. Exclusão de cliente inclui revogação dos refresh tokens e das cobranças/lançamentos dependentes conforme a estratégia de exclusão abaixo.
- `version`/`updated_at` **não** entram: o compare-and-set acima resolve cada caso sem coluna de versão.

### Estratégia de Exclusão

| Entidade | Estratégia | Critério |
| --- | --- | --- |
| OS, vendas, clientes, produtos, serviços, cobranças | Hard delete (mantido) | Sem mudança de semântica; o ganho é atomicidade: dependentes e a entidade saem na mesma transação, e a UI só mostra sucesso com `trans_status` verdadeiro. Cobrança com lançamento baixado/valor pago: a Spec deve manter a regra atual de bloqueio ou decidir explicitamente (não apagar silenciosamente histórico financeiro). |
| `webhook_eventos` | Hard delete por TTL | Log técnico; retenção definida abaixo |
| `api_refresh_tokens` | Hard delete por TTL | Linhas expiradas/revogadas há mais de N dias |
| `email_queue` | Hard delete por TTL | Ver Escala |

Sem soft delete novo: nenhum consumidor de recuperação foi nomeado.

### Escala & Ciclo de Vida

- `webhook_eventos`: **cresce sem limite** (um ou mais eventos por cobrança, com reenvios dos gateways). Estimativa: baixa a média, mesma ordem de grandeza de `cobrancas` multiplicada por 2-5 eventos. Particionamento **não** se justifica nesse volume (instalação single-tenant, MySQL único); a política é **TTL por rotina de limpeza** (sugestão: 90 dias sobre `created_at`, valor operacional a fixar na Spec/RUNBOOK), apagando em lotes limitados para não segurar lock. Atenção: apagar um evento reabre a idempotência para aquele id; a janela de retenção deve ser **maior que a janela de reenvio do gateway** (gateways reenviam por dias; 90 dias é folgado).
- `api_refresh_tokens`: já cresce a cada login; com o cliente sobe. Mesma rotina de TTL (expirados e revogados) para não degradar o índice de dono; confirmar se `Refresh_token` já tem limpeza e estendê-la ao tipo cliente.
- `email_queue`: linhas `sent` e `failed` acumulam e guardam **corpo da mensagem** (ver LGPD). Retenção de `sent` curta (dias) e `failed` mais longa só para diagnóstico; sem particionamento.
- `api_rate_limit`: sem mudança.

### Requisitos LGPD/Compliance

Classificação dos dados tocados:

| Dado | Classe | Observação |
| --- | --- | --- |
| `clientes` (nome, e-mail, CPF/CNPJ, telefone, endereço) | PII | Já existente; a feature só muda a senha e adiciona flags técnicas |
| `clientes.senha` | Credencial | Hash (bcrypt/`password_hash`), nunca reversível; **a senha inicial em claro nunca é persistida**: gerada, exibida uma vez ao operador, enviada por e-mail e descartada |
| `api_refresh_tokens.token_hash` | Credencial | Só SHA-256 (já é assim); token em claro nunca no banco nem em log |
| `email_queue.message` / `headers` | PII + credencial (corpo carrega link de redefinição e agora a senha inicial do cliente) | Corpo de e-mail é dado sensível; **o e-mail de senha inicial é o ponto mais crítico**: a linha da fila contém senha em claro até ser enviada |
| `webhook_eventos` | Financeiro (referência) | `payload_hash` guarda só o hash; **não persistir o payload bruto** (pode conter dado do pagador); persistir apenas o necessário |
| `cobrancas`/`lancamentos` | Financeiro + PII indireta | Sem mudança de classe |

Requisitos:
1. **E-mail da senha inicial na fila:** como o corpo fica em `email_queue.message` até o envio, a Spec deve exigir (a) apagar/anonimizar o corpo (`message`) assim que `sent`, ou (b) preferir enviar link de definição de senha em vez de senha em claro, ou (c) enviar esse e-mail fora da fila persistente. Recomendação: **(b)/(a)** no mínimo; manter senha em claro numa tabela com retenção é contrário ao "nunca sensível em claro porque é interno". Decisão de produto/segurança a registrar na Spec; o requisito de dados é: **senha em claro não pode sobreviver ao envio**.
2. Corpo de e-mail e link de reset não podem ir ao log (`print_debugger` só com cabeçalhos): já no escopo; vale também para `webhook` (não logar corpo/assinatura/segredo, só id do evento e resultado).
3. Segredos de webhook (`EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN`) só no `.env`, nunca no banco nem em log; comparação em tempo constante. **Atenção:** o segredo da Efí vai no **path da URL**, e URL costuma ir para access log do servidor e de proxy; tratar como credencial exposta em log de acesso, documentar rotação e considerar não registrar essa rota no access log.
4. Minimização: `webhook_eventos` não guarda dado do pagador; `senha_trocar`/`sessao_versao` são flags técnicas sem PII.
5. Retenção/TTL das tabelas técnicas acima documentada (princípio de limitação de armazenamento da LGPD); exclusão de cliente revoga tokens e apaga o que for dele (direito de eliminação), preservando o que a lei fiscal/contábil exigir nos lançamentos (decisão já existente do projeto, não alterar nesta feature).
6. Notificação por e-mail de abertura de OS para toda a equipe contém dado do cliente: manter, sem ampliar o conteúdo.

### Histórico/Auditoria

Não é exigida trilha completa de entidade. `webhook_eventos` já funciona como trilha append-only dos pagamentos recebidos (por isso o hash do payload e `processado_em`). Os eventos de segurança (reuso de refresh, assinatura inválida, 429) vão para **log técnico** (`log_message`), sem tabela de auditoria nova nesta feature.

### Multi-tenancy

Não se aplica (instalação single-tenant).

### Migração (se schema existente)

- Estratégia: **Expand-Contract em migrations de timestamp reversíveis, só aditivas** (colunas com DEFAULT e tabela nova); nenhum `DROP`/mudança destrutiva. O único "contract" é o índice simples antigo de `api_refresh_tokens`, removido após criar o composto, e não afeta consultas.
- Segurança de lock: todas as `ALTER ... ADD COLUMN` com DEFAULT constante são online no MySQL 8.4 (`INSTANT`/`INPLACE`); no MariaDB também é online nas versões atuais. Criar os índices com `ALGORITHM=INPLACE, LOCK=NONE` quando suportado. `clientes` e `email_queue` são tabelas pequenas/médias; não é necessário backfill em lotes porque o DEFAULT cobre as linhas existentes. `api_refresh_tokens.tipo` com DEFAULT `'usuario'` já classifica corretamente todo o legado.
- Comportamento com `db_debug` desligado (como já fazem as migrations de `api_tokens` e `sessao_versao`): **cada migration confere por `field_exists`/`table_exists` e lança exceção se não criou**, para a versão não avançar com DDL falho. Obrigatório nesta feature (o mesmo problema de erro engolido é objeto da feature).
- Compatibilidade de deploy (código novo x schema antigo e vice-versa): aplicar migration antes do código novo; o código antigo ignora as colunas novas (todas com DEFAULT), então o rollback de código sem rollback de schema é seguro. `email_queue`: e-mails `pending`/`failed` antigos entram com `tentativas = 0`; os `failed` antigos **não** são reenfileirados automaticamente (o retry manual respeita o máximo a partir de 0).
- Rollback: `down()` remove as colunas/tabela novas; o `down()` de `api_refresh_tokens` deve **apagar antes as linhas `tipo='cliente'`** (senão cairiam na mão de operadores com id colidindo) e só então dropar a coluna. Reverter derruba as sessões de cliente; aceitável.
- Seed do admin padrão apenas em `development`: é mudança de **dado inicial**, não de schema; o seed de instalação (`install`/`banco.sql`) deve ser ajustado para não criar `admin@admin.com/123456` fora de dev, e instalações existentes não são afetadas (nada é apagado).

### Para tasks

- `29a9b94336`: migrations aditivas e reversíveis com verificação pós-DDL: `webhook_eventos` (+ índices), `api_refresh_tokens.tipo` + índice composto (e remoção do índice simples), `clientes.senha_trocar` e `clientes.sessao_versao`, `email_queue.tentativas`/`reservado_por`/`reservado_em` + índice `status, reservado_por`; confirmar/criar índice em `cobrancas.charge_id`; confirmar índice de apoio à contagem diária de OS por cliente; `down()` com limpeza prévia de `tipo='cliente'`; atualizar o schema de instalação (`banco.sql`) de acordo.
- `50a8844c7d`: idempotência do webhook por `INSERT` com violação do único + transação única de baixa (cobrança + lançamento, `UPDATE` condicional com `affected_rows`, transição monotônica, sem payload bruto); reserva atômica da fila com `reservado_por`/`reservado_em`/`tentativas` e expiração de reserva; compare-and-set no token de reset e na rotação do refresh; `Refresh_token` com `tipo` (inclusive revogação ao excluir cliente); exclusões em transação com `trans_status`; rotinas de TTL (`webhook_eventos`, refresh expirado, `email_queue` enviado) com remoção em lotes; garantir que a senha inicial em claro não sobrevive ao envio (apagar/anonimizar `email_queue.message` após `sent` ou usar link); não logar corpo/segredo/assinatura.
