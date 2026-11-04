---
created_at: "2026-11-04 07:25:17"
id: feat-0001-melhoria-seguranca
metrics:
    added_tasks: 0
    blocks: 2
    planned_tasks: 32
    re_reviews: 0
    scope_changes: 0
    validation_changes: 0
    validation_fixes: 0
n45_version: 1.1.0
recommendations:
    - Validar cada comando de critério com grep ou awk num exemplo positivo e num negativo antes de fechar a task; âncoras como $ e -E mudam o resultado (TASK-003)
    - Critério que roda npm ou tailwind deve declarar a preparação do worktree (npm ci) ou trazer o resultado já commitado, para não bloquear a task (TASK-029)
    - Rodar composer test completo no merge de cada task que mexe em classe compartilhada (REST_Controller, Format, .env.example), pois três fricções de integração só apareceram depois do merge
    - Ao mudar o verbo de uma rota (logout GET para POST), listar na task todos os redirecionamentos e links que a chamam, inclusive em controllers como Mapos.php
---
## Fricções

- TASK-003 — bloqueada: o comando do critério 15 usava `grep -nE` com `$`, que vira âncora de fim de linha; o código atendia e o critério foi corrigido por decisão
- TASK-029 — bloqueada: critério 10 não verificável porque o worktree não tinha `node_modules` (tailwindcss ausente)
- Integração da Phase 1 — a revisão aprovou, mas `composer test` falhou com 3 erros em `CookieSeguroTest` (`parse_ini_file` do `.env.example`)
- Integração da Phase 3 — `composer test` com erro fatal `Cannot redeclare class Format` após o merge de TASK-020 e TASK-023
- Integração da Phase 4 — revisão rejeitada: `Mapos.php` ainda redirecionava por GET para `login/sair`, que passou a aceitar só POST (405)

## Ajustes pós-conclusão

- Nenhum commit `[hot-fix]` ou `[quick-feat]` na branch
