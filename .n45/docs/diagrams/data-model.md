## Modelo de Dados

Entidades, campos-chave e relações.

---

```mermaid
%%{init: {'theme': 'neutral'} }%%
erDiagram
  PERMISSAO ||--o{ USUARIO : agrupa
  CLIENTE ||--o{ OS : abre
  USUARIO ||--o{ OS : atende
  OS ||--o{ PRODUTO_OS : contem
  PRODUTO ||--o{ PRODUTO_OS : usado
  OS ||--o{ SERVICO_OS : contem
  SERVICO ||--o{ SERVICO_OS : usado
  OS ||--o{ ANEXO_OS : tem
  OS ||--o{ OS_HISTORICO : registra
  OS ||--o{ EQUIPAMENTO_OS : liga
  EQUIPAMENTO ||--o{ EQUIPAMENTO_OS : usado
  CLIENTE ||--o{ EQUIPAMENTO : possui
  MARCA ||--o{ EQUIPAMENTO : classifica
  CLIENTE ||--o{ VENDA : compra
  VENDA ||--o{ ITEM_VENDA : contem
  PRODUTO ||--o{ ITEM_VENDA : vendido
  CLIENTE ||--o{ COBRANCA : recebe
  OS ||--o{ COBRANCA : gera
  VENDA ||--o{ COBRANCA : gera
  COBRANCA ||--o{ WEBHOOK_EVENTO : confirma
  CATEGORIA ||--o{ LANCAMENTO : classifica
  CLIENTE ||--o{ LANCAMENTO : origina
  USUARIO ||--o{ GARANTIA : cria
  USUARIO ||--o{ CODIGO_RECUPERACAO_2FA : possui
  USUARIO ||--o{ API_REFRESH_TOKEN : emite
  CLIENTE ||--o{ RESET_SENHA : solicita
  PERMISSAO {
    int id
    string permissoes
  }
  USUARIO {
    int id
    string email
    int permissoes_id
  }
  CLIENTE {
    int id
    string documento
    string email
  }
  OS {
    int id
    int clientes_id
    int usuarios_id
    string status
    int lancamento
  }
  OS_HISTORICO {
    int id
    int os_id
    string status
  }
  PRODUTO {
    int id
    decimal preco
    int estoque
  }
  SERVICO {
    int id
    decimal preco
  }
  VENDA {
    int id
    int clientes_id
    int lancamentos_id
    bool faturado
  }
  COBRANCA {
    int id
    int clientes_id
    int os_id
    int vendas_id
    string status
  }
  WEBHOOK_EVENTO {
    int id
    int cobranca_id
  }
  LANCAMENTO {
    int id
    int categorias_id
    int clientes_id
    decimal valor
  }
```

---

_Criado em: 
