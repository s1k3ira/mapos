## Fluxos de Usuário

Sequência de interações para os principais fluxos da aplicação.

---

### Abertura e entrega de uma OS

```mermaid
%%{init: {'theme': 'neutral'} }%%
sequenceDiagram
  actor A as Atendente
  actor T as Técnico
  actor D as Dono
  participant P as Painel
  participant DB as Banco
  A->>P: Nova OS com cliente, descrição e status
  alt sem permissão eOs
    P-->>A: 403
  else com permissão
    P->>DB: grava OS e os_historico
    P-->>A: redireciona com toast de sucesso
  end
  T->>P: adiciona produtos, serviços e anexos
  P->>DB: recalcula totais e estoque no servidor
  T->>P: muda status até Finalizado
  D->>P: fatura a OS
  P->>DB: transação cria lançamento e status Faturado
  P-->>D: OS faturada
```

### Login no painel

```mermaid
%%{init: {'theme': 'neutral'} }%%
sequenceDiagram
  actor U as Usuário
  participant L as Login
  participant LL as Limite_login
  participant DB as Banco
  U->>L: e-mail e senha
  L->>LL: consulta bloqueio
  alt bloqueado
    L-->>U: mensagem de espera
  else liberado
    L->>DB: confere senha
    alt senha incorreta ou e-mail desconhecido
      L->>LL: registra falha
      L-->>U: mensagem genérica
    else senha correta
      opt grupo ou conta exige 2FA
        L-->>U: pede código TOTP
        U->>L: código
      end
      L-->>U: sessão criada
    end
  end
```

### Cobrança por gateway

```mermaid
%%{init: {'theme': 'neutral'} }%%
sequenceDiagram
  actor U as Usuário
  actor C as Cliente
  participant P as Painel
  participant G as Gateway
  participant W as Webhooks
  participant DB as Banco
  U->>P: gera cobrança de OS ou venda
  P->>G: cria cobrança
  P->>DB: grava cobrancas
  P-->>C: link ou Pix por e-mail
  C->>G: paga
  G->>W: webhook de pagamento
  W->>G: reconsulta o pagamento
  W->>DB: confere valor e baixa uma única vez
```

### Primeiro acesso do cliente

```mermaid
%%{init: {'theme': 'neutral'} }%%
sequenceDiagram
  actor O as Operador
  actor C as Cliente
  participant S as Map-OS
  O->>S: cadastra cliente sem senha
  S-->>O: senha aleatória mostrada uma única vez
  S-->>C: senha inicial por e-mail
  C->>S: entra na área do cliente
  S-->>C: obriga mine/definirSenha (API 403 troca_de_senha_obrigatoria)
  C->>S: nova senha
  S-->>C: outras sessões e tokens deixam de valer
```

---

_Criado em: 
