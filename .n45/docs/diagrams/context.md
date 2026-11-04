## Contexto

Visão externa: atores, integrações e dependências.

---

```mermaid
%%{init: {'theme': 'neutral'} }%%
flowchart TD
  A([Dono da assistência]) --> S[Map-OS]
  B([Atendente]) --> S
  C([Técnico]) --> S
  D([Cliente final]) --> S
  I([Integrador\napp móvel ou sistema]) --> S
  S --> E1["Efí\nBoleto e Pix"]
  S --> E2["Mercado Pago\nCobranças"]
  S --> E3["Asaas\nCobranças"]
  S --> E4["Servidor SMTP\nE-mails"]
  S --> E5["GitHub Releases\nAtualização do sistema"]
  S --> E6["WhatsApp\nLink de mensagem pronta"]
```

---

_Criado em: 
