---
entries:
    - date: "2026-11-04"
      feature: feat-0001-melhoria-seguranca
      summary: |
        ### Segurança
        - Webhooks de pagamento da Efí, do Mercado Pago e do Asaas autenticados, idempotentes e sem confiar no corpo: o pagamento é reconsultado no gateway e o valor é conferido antes da baixa transacional (TASK-010, TASK-011)
        - Cliente criado pelo operador recebe senha inicial aleatória, exibida uma única vez, e troca a senha no primeiro acesso; a sessão do cliente é revalidada a cada requisição (TASK-009, TASK-019, TASK-024)
        - Token da área do cliente na API dura no máximo 1 hora, com refresh token rotativo por tipo, logout e troca de senha (TASK-023)
        - 2FA exige confirmação de senha, com limite de tentativas (TASK-018)
        - Limite diário de abertura de OS pelo cliente, na tela e na API (TASK-027)
        - Fila de e-mail com lock, reserva atômica e tentativas, sem envio duplicado (TASK-012)
        - Pastas de armazenamento protegidas em Apache 2.4, `save_queries` e seed de dados só em development, e logs sem segredo, payload ou dados do pagador (TASK-006, TASK-031)

        ### Adicionado
        - Rotas `/webhooks/efi/<segredo>`, `/webhooks/mercadopago` e `/webhooks/asaas`, tabela `webhook_eventos` e variáveis `EFI_WEBHOOK_SECRET`, `MP_WEBHOOK_SECRET`, `ASAAS_WEBHOOK_TOKEN` e `WEBHOOK_RATE_LIMIT` (TASK-002, TASK-011)
        - API do cliente: `POST /api/v1/client/refresh`, `POST /api/v1/client/logout` e `PUT /api/v1/client/password` (TASK-023)
        - Tela `mine/definirSenha` para a troca obrigatória de senha do cliente e tela da senha inicial no cadastro (TASK-008, TASK-016, TASK-024)
        - Comando `php index.php tools marcarSenhasLegadas` para obrigar a troca de quem usa o documento como senha (TASK-026)
        - Retenção automática de eventos de webhook, refresh tokens e e-mails da fila no ciclo `email/retry` (TASK-013)
        - `scripts/smoke-seguranca.sh`, com 12 verificações de segurança no Docker, e documentação em `docs/webhooks-de-pagamento.md` (TASK-031, TASK-032)

        ### Alterado
        - Exceções tipadas, log sem PII e timeout configurável nos gateways Mercado Pago, Asaas e Efí; erros de cobrança respondem 422 ou 502 com mensagem amigável (TASK-004, TASK-005, TASK-014)
        - Exclusão e faturamento de OS, vendas, produtos, serviços, clientes e cobranças em transação, sem sucesso falso quando falham (TASK-014, TASK-015, TASK-017, TASK-019)
        - Cliente HTTP do front trata sessão expirada, timeout e JSON inválido com aviso visível (TASK-007)
        - Nginx do Docker sem o segredo da Efí no log e cron com saída em `/var/log/cron.log` (TASK-031)

        ### Corrigido
        - Erro não tratado na API responde 500 em JSON (TASK-003)
        - `GET /api/v1/calendario` devolve os eventos e o QR code da compra do cliente volta a funcionar (TASK-020, TASK-028)
        - Sessão expirada em requisições AJAX responde 401 em vez de HTML (TASK-024)

        ### Breaking Changes
        - API: falta de permissão passa de 401 para 403, e token ausente, inválido, expirado ou de conta ou grupo desativado passa de 403 para 401 — **migração:** tratar 401 como "refazer login" e 403 como "sem permissão" (TASK-003, TASK-021)
        - Login do painel e da área do cliente respondem por status HTTP (200, 401, 403, 422, 429) e o token CSRF novo vem no campo de nome configurado, não mais em `MAPOS_TOKEN` — **migração:** clientes do login devem ler o status e o campo do CSRF configurado (TASK-025, TASK-030)
        - Logout do painel e da área do cliente só por POST com CSRF; GET responde 405 — **migração:** trocar links de sair por formulário POST (TASK-025, TASK-029)
        - API do cliente: access token de no máximo 1 hora com refresh token; tokens antigos, sem `sid`, deixam de valer — **migração:** usar `POST /api/v1/client/refresh` (TASK-023)
        - Listagens da API: `perPage` com teto de 100 e `perPage` ou `page` inválidos respondem 422 — **migração:** limitar `perPage` a 100 (TASK-003, TASK-020, TASK-021, TASK-022, TASK-028)
        - `POST /api/v1/client/os` com limite diário de aberturas (`CLIENTE_OS_LIMITE_DIARIO`, padrão 10), com 429 e `Retry-After` (TASK-027)
        - Cinco migrations novas e variáveis novas no `.env` — **migração:** seguir `docs/migracao-4x-para-5.md`, aplicar as migrations e rodar `tools marcarSenhasLegadas` (TASK-002, TASK-026, TASK-032)
      version: 0.1.0
n45_version: 1.1.0
---
