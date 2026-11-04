## Ciclo de Vida de Entidades

Transições válidas por entidade — base pro architect respeitar estados ao criar tasks.

---

### Ordem de serviço

```mermaid
%%{init: {'theme': 'neutral'} }%%
stateDiagram-v2
  [*] --> Orcamento: criada pela tela ou API
  [*] --> Aberto: tela, API ou área do cliente
  Orcamento --> Negociacao
  Negociacao --> Aberto
  Aberto --> Aprovado
  Aprovado --> EmAndamento
  EmAndamento --> AguardandoPecas: falta peça
  AguardandoPecas --> EmAndamento: peça chegou
  EmAndamento --> Finalizado
  Finalizado --> Faturado: fatura e gera lançamento
  Orcamento --> Cancelado
  Negociacao --> Cancelado
  Aberto --> Cancelado
  Aprovado --> Cancelado
  EmAndamento --> Cancelado
  AguardandoPecas --> Cancelado
  Faturado --> [*]
  Cancelado --> [*]
```

### Venda

```mermaid
%%{init: {'theme': 'neutral'} }%%
stateDiagram-v2
  [*] --> EmAberto
  EmAberto --> Faturada: fatura e gera lançamento
  Faturada --> [*]
```

### Cobrança

```mermaid
%%{init: {'theme': 'neutral'} }%%
stateDiagram-v2
  [*] --> Criada: criada no gateway
  Criada --> Paga: pagamento confirmado ou webhook
  Criada --> Cancelada: cancelamento
  Criada --> Excluida: exclusão
  Paga --> [*]
  Cancelada --> [*]
  Excluida --> [*]
```

---

_Criado em: 
