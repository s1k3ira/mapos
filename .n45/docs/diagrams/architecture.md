## Arquitetura Interna

Organização em módulos e camadas do código-fonte.

---

```mermaid
%%{init: {'theme': 'neutral'} }%%
flowchart TD
  subgraph Entrada[Entrada]
    Hooks["Hooks\nsecurity_headers e erros"]
    MC["MY_Controller\nsessão, 2FA e permissions_map"]
    RC["REST_Controller\nJWT, limite e API_ENABLED"]
    WH["Webhooks\npúblico, sem sessão e sem CSRF"]
  end
  subgraph Painel[Painel e área do cliente]
    Ctl["Controllers\nOs, Clientes, Vendas, Financeiro, Mine"]
    Views["Views PHP\ncomponent() e e()"]
  end
  subgraph Regras[Regras reutilizáveis]
    Help["Helpers\nos_helper, financeiro_helper"]
    Lib["Libraries\nLimite_login, Totp, Permission, Retencao"]
    Gw["Gateways\nEfí, Mercado Pago, Asaas"]
  end
  subgraph Dados[Dados]
    Mod["Models\nQuery Builder e transações"]
    Mig["Migrations"]
  end
  Hooks --> MC
  MC --> Ctl
  RC --> Ctl
  WH --> Gw
  Ctl --> Views
  Ctl --> Help
  Ctl --> Lib
  Ctl --> Mod
  Ctl --> Gw
  Gw --> Mod
  Lib --> Mod
  Mod --> DB[(MySQL 8.4)]
  Mig --> DB
  Gw --> Ext["Efí, Mercado Pago e Asaas"]
  Cron["Cron email/process e email/retry"] --> Mod
  Cron --> SMTP["SMTP"]
```

---

_Criado em: 
