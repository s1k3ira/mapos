---
branch: feat/0001-melhoria-seguranca
created_at: "2026-11-03 15:50:28"
id: feat-0001-melhoria-seguranca
n45_version: 1.1.0
phases:
    - completed_at: "2026-11-03 18:21:48"
      name: 'Phase 1 — Base de segurança: schema, API, gateways e avisos de sessão'
      started_at: "2026-11-03 16:28:49"
      status: done
      tasks:
        - complexity: P
          deps: []
          id: TASK-001
          persona: backend
          status: done
          title: 'Suporte de teste: captura de log e falha em Deprecated'
        - complexity: M
          deps: []
          id: TASK-002
          persona: dba
          status: done
          title: Migrations de webhooks, refresh por tipo, troca de senha do cliente e reserva da fila
        - complexity: G
          deps:
            - TASK-001
          id: TASK-003
          persona: backend
          status: done
          title: 'Base da API: 500 em JSON, 401 x 403, grupo ativo, paginação e limite por IP'
        - complexity: G
          deps:
            - TASK-001
          id: TASK-004
          persona: backend
          status: done
          title: 'Mercado Pago: exceções tipadas, log, timeout e descrição da cobrança'
        - complexity: G
          deps:
            - TASK-004
          id: TASK-005
          persona: backend
          status: done
          title: 'Asaas e Efí: exceções tipadas, log, timeout e notification_url'
        - complexity: M
          deps:
            - TASK-001
          id: TASK-006
          persona: backend
          status: done
          title: Proteção de pastas sem @, save_queries e seed só em development
        - complexity: M
          deps: []
          id: TASK-007
          persona: frontend
          status: done
          title: 'Cliente HTTP: sessão expirada, timeout, JSON inválido e erros visíveis'
        - complexity: M
          deps: []
          id: TASK-008
          persona: frontend
          status: done
          title: Tela da senha inicial do cliente exibida uma única vez
    - completed_at: "2026-11-03 20:38:21"
      name: Phase 2 — Pagamento por webhook, fila de e-mail confiável e conta do cliente
      started_at: "2026-11-03 18:31:22"
      status: done
      tasks:
        - complexity: M
          deps:
            - TASK-001
            - TASK-002
          id: TASK-009
          persona: backend
          status: done
          title: 'Senha do cliente: gerador, troca transacional e refresh por tipo'
        - complexity: M
          deps:
            - TASK-005
          id: TASK-010
          persona: backend
          status: done
          title: Consulta de pagamento nos gateways para o webhook
        - complexity: G
          deps:
            - TASK-002
            - TASK-003
            - TASK-010
          id: TASK-011
          persona: backend
          status: done
          title: Webhooks de pagamento com autenticação, idempotência e baixa transacional
        - complexity: G
          deps:
            - TASK-001
            - TASK-002
          id: TASK-012
          persona: backend
          status: done
          title: Fila de e-mail com lock, reserva atômica e tentativas
        - complexity: M
          deps:
            - TASK-012
          id: TASK-013
          persona: backend
          status: done
          title: Retenção de eventos, refresh tokens e fila no email/retry
        - complexity: M
          deps:
            - TASK-005
          id: TASK-014
          persona: backend
          status: done
          title: 'Cobranças: respostas 422/502, mensagens amigáveis e exclusão em transação'
        - complexity: G
          deps:
            - TASK-001
          id: TASK-015
          persona: backend
          status: done
          title: Exclusão e faturamento de OS e vendas em transação
        - complexity: M
          deps: []
          id: TASK-016
          persona: frontend
          status: done
          title: Tela Defina sua nova senha da área do cliente
    - completed_at: "2026-11-04 01:03:50"
      name: Phase 3 — Primeiro acesso do cliente e APIs do operador e do cliente
      started_at: "2026-11-03 22:40:39"
      status: done
      tasks:
        - complexity: M
          deps:
            - TASK-001
          id: TASK-017
          persona: backend
          status: done
          title: Exclusão de produtos e serviços em transação
        - complexity: M
          deps:
            - TASK-001
          id: TASK-018
          persona: backend
          status: done
          title: 2FA com confirmação de senha limitada e retornos do Mapos_model
        - complexity: G
          deps:
            - TASK-009
            - TASK-008
          id: TASK-019
          persona: backend
          status: done
          title: Cadastro de cliente pelo operador com senha inicial e exclusão em transação
        - complexity: G
          deps:
            - TASK-003
            - TASK-015
          id: TASK-020
          persona: backend
          status: done
          title: 'API de OS: OS fechada, allow-list, datas, paginação e exclusão'
        - complexity: G
          deps:
            - TASK-003
            - TASK-017
          id: TASK-021
          persona: backend
          status: done
          title: 'API de produtos, serviços e usuários: 403, allow-list, paginação e exclusão'
        - complexity: M
          deps:
            - TASK-003
            - TASK-009
            - TASK-019
          id: TASK-022
          persona: backend
          status: done
          title: 'API de clientes: senha inicial, allow-list, paginação e exclusão'
        - complexity: G
          deps:
            - TASK-003
            - TASK-009
          id: TASK-023
          persona: backend
          status: done
          title: 'Tokens da API do cliente: 1 h, refresh, logout e troca de senha'
        - complexity: G
          deps:
            - TASK-009
            - TASK-016
          id: TASK-024
          persona: backend
          status: done
          title: 'Área do cliente: sessão revalidada, troca obrigatória, reset atômico e 401 em AJAX'
    - name: Phase 4 — Login por status, logout seguro e limite de OS do cliente
      status: pending
      tasks:
        - complexity: G
          deps:
            - TASK-024
          id: TASK-025
          persona: backend
          status: pending
          title: Login por status HTTP e logout só por POST no painel e na área do cliente
        - complexity: P
          deps:
            - TASK-001
            - TASK-002
          id: TASK-026
          persona: backend
          status: pending
          title: Comando marcarSenhasLegadas para clientes com senha igual ao documento
        - complexity: G
          deps:
            - TASK-023
            - TASK-025
          id: TASK-027
          persona: backend
          status: pending
          title: Limite diário de abertura de OS pelo cliente na tela e na API
        - complexity: M
          deps:
            - TASK-003
            - TASK-027
          id: TASK-028
          persona: backend
          status: pending
          title: Paginação e QR de venda da API do cliente
        - complexity: M
          deps:
            - TASK-025
          id: TASK-029
          persona: frontend
          status: pending
          title: Sair por formulário POST em todas as telas
        - complexity: M
          deps:
            - TASK-025
            - TASK-029
          id: TASK-030
          persona: frontend
          status: pending
          title: Login do painel e da área do cliente por status HTTP no módulo de login
    - name: 'Phase 5 — Entrega: smoke de segurança, servidor e documentação'
      status: pending
      tasks:
        - complexity: M
          deps:
            - TASK-006
            - TASK-011
            - TASK-020
            - TASK-023
            - TASK-025
            - TASK-027
            - TASK-028
          id: TASK-031
          persona: devops
          status: pending
          title: Nginx sem segredo no log, cron da fila e smoke de segurança
        - complexity: M
          deps:
            - TASK-006
            - TASK-011
            - TASK-012
            - TASK-019
            - TASK-022
            - TASK-023
            - TASK-025
            - TASK-026
            - TASK-027
            - TASK-028
          id: TASK-032
          persona: backend
          status: pending
          title: Documentação de webhooks, API, servidor web, migração e CHANGELOG
status: in-progress
updated_at: "2026-11-04 01:03:50"
---
