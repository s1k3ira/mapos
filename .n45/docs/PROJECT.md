---
created_at: "2026-11-03 14:50:45"
description: Sistema open source de ordens de serviço e gestão para assistências técnicas e prestadores de serviço, em PHP e CodeIgniter 3
lang: pt-BR
n45_version: 1.1.0
type: Aplicação web self-hosted (ERP leve de ordens de serviço)
updated_at: "2026-11-03 14:50:54"
---
## Visão Geral

O Map-OS é um sistema open source de ordens de serviço e gestão de pequenos negócios de assistência técnica e serviços: cadastra clientes, produtos e serviços, abre e acompanha ordens de serviço (OS), vende, cobra por boleto, Pix ou cartão, controla o financeiro e emite relatórios, termos de garantia e impressões. É mantido pela MountBit e instalado pelo próprio cliente em servidor próprio ou hospedagem; a interface e os documentos são em português do Brasil.

**Tipo:** aplicação web self-hosted (ERP leve de ordens de serviço), com painel administrativo, área do cliente e API REST
**Modelo de acesso:** privado por conta; usuários criados pelo administrador, cliente acessa a área do cliente com e-mail e senha
**Multi-tenant:** não, uma instalação atende uma empresa (single-tenant)

## Personas e Perfis

**Personas — quem usa o produto e qual o JTBD:**

| Persona | Quem é | JTBD |
| ------- | ------ | ---- |
| Dono da assistência | Administrador do negócio | Ter OS, vendas e caixa sob controle e saber quanto entrou e quanto falta receber |
| Atendente | Recebe o cliente e abre a OS | Registrar o equipamento e o defeito rápido e imprimir o comprovante |
| Técnico | Executa o serviço | Ver as OS atribuídas, lançar produtos e serviços usados e mudar o status |
| Cliente final | Quem leva o equipamento | Acompanhar a OS, ver anexos e cobranças e abrir uma OS sozinho, sem ligar |
| Integrador | Dev do app móvel ou de outro sistema | Consumir os dados do Map-OS pela API com autenticação segura |

**Perfis — permissões no sistema:**

| Perfil | Descrição | Permissões principais |
| ------ | --------- | --------------------- |
| Administrador | Grupo de permissões com todas as ações | Todas as 53 permissões (`aCliente`, `vOs`, `cSistema` ...) |
| Grupo personalizado | Grupo criado em Permissões (`permissoes`) | Subconjunto de ações: adicionar (`a`), editar (`e`), excluir (`d`), visualizar (`v`), relatório (`r`) e configurar (`c`) por área |
| Cliente | Registro em `clientes` com senha própria | Só os próprios dados: OS, compras, cobranças e anexos |
| Token de API | Usuário ou cliente autenticado por JWT | As mesmas permissões do grupo do usuário, consultadas a cada requisição |

## Módulos / Domínios

| Módulo | Responsabilidade |
| ------ | ---------------- |
| Ordens de serviço | Abertura, itens (produtos e serviços), anexos, anotações, desconto, histórico de status, impressão e faturamento da OS |
| Clientes e fornecedores | Cadastro com CPF ou CNPJ, contato, endereço e acesso à área do cliente |
| Produtos e serviços | Catálogo com preço, estoque e baixa de estoque |
| Vendas | Venda de produtos, desconto, faturamento e impressão (cupom e A4) |
| Financeiro | Lançamentos de receita e despesa, baixa e listagem |
| Cobranças | Boletos, Pix e cobranças pelos gateways Efí, Mercado Pago e Asaas |
| Garantias | Termos de garantia reutilizáveis e impressão |
| Relatórios | Relatórios rápidos e personalizados de clientes, produtos, serviços, OS, vendas e financeiro, em HTML e PDF |
| Arquivos | Arquivos enviados, com download protegido por permissão |
| Usuários e permissões | Usuários, grupos de permissões, 2FA, tema e sessão |
| Painel e configurações | Painel com indicadores e agenda, busca global, emitente, e-mails, backup e atualização do sistema |
| Área do cliente (Conecte) | Login, cadastro, OS, compras e cobranças do cliente |
| API v1 | Acesso programático a clientes, produtos, serviços, usuários e OS, e área do cliente |
| Segurança | Limite de login, cabeçalhos e CSP, auditoria, sessão e cookies |
| Instalador e atualizador | Instalação web, migrations e atualização por releases do GitHub com conferência de SHA256 |

> O modelo de dados, os endpoints e as páginas deste produto são renderizados do grafo do projeto.

## Ciclo de Vida de Entidades

### Ordem de serviço

Status guardado em `os.status`; a lista exibida é configurável (`os_status_list`), e os valores de origem são:

`Orçamento → Negociação → Aberto → Aprovado → Em Andamento ⇄ Aguardando Peças → Finalizado → Faturado`, com `Cancelado` a partir de qualquer estado aberto.

**Transições válidas:**

- `Aberto` ou `Orçamento` entra pela tela, pela API ou pela área do cliente (o cliente abre como `Aberto`)
- `Em Andamento ⇄ Aguardando Peças` conforme falta de peça
- `Finalizado → Faturado` quando se fatura (gera lançamento financeiro)
- qualquer estado em aberto `→ Cancelado`

**Transições proibidas:** editar OS `Faturado` ou `Cancelado` sem a configuração que permite editar (`control_editos`); toda mudança de status grava uma linha em `os_historico`.

### Venda

`Em aberto → Faturada` (campo `faturado`); venda faturada gera lançamento e não volta a aberta pela tela.

### Cobrança

`Cobrança criada no gateway → paga | cancelada | excluída`; a confirmação de pagamento e o cancelamento exigem `eCobranca`.

## Casos de Uso Principais

| Persona | Caso de uso | Resultado esperado |
| ------- | ----------- | ------------------ |
| Atendente | Abrir OS para um cliente | OS com número, cliente, equipamento e defeito, pronta para imprimir |
| Técnico | Lançar produtos e serviços na OS e mudar o status | Total da OS correto, estoque baixado e histórico registrado |
| Dono | Faturar OS ou venda | Lançamento financeiro criado e documento impresso |
| Dono | Cobrar o cliente | Boleto ou Pix gerado pelo gateway e enviado por e-mail |
| Cliente final | Acompanhar a OS na área do cliente | Vê status, anexos e cobranças sem ligar para a loja |
| Administrador | Controlar quem faz o quê | Grupos de permissões, 2FA e auditoria das ações |
| Integrador | Consumir a API | Dados de OS e cadastros por JWT, com limite de requisições |

## Fluxos Principais

### Abertura e entrega de uma OS

1. Atendente acessa Ordens de serviço e escolhe Nova OS
2. Informa cliente (busca por autocompletar), descrição e status inicial e salva
3. Sistema grava a OS, registra o histórico e redireciona com toast de sucesso
4. Técnico abre a OS, adiciona produtos e serviços (estoque e totais calculados no servidor) e anexa fotos
5. Muda o status até `Finalizado`; a OS pode ser impressa ou enviada por e-mail ao cliente
6. Dono fatura a OS; o sistema cria o lançamento e a OS passa a `Faturado`
7. Desvio: usuário sem `eOs` recebe 403, e OS fechada não aceita novos itens

### Login no painel

1. Usuário informa e-mail e senha em `login`
2. Sistema consulta `Limite_login`; bloqueado devolve mensagem e espera
3. Senha confere → sessão criada; se o grupo ou a conta exige 2FA, pede o código TOTP
4. Desvio: e-mail desconhecido e senha errada recebem a mesma mensagem genérica e contam como falha

### Cobrança por gateway

1. Usuário com `aCobranca` gera cobrança de uma OS ou venda
2. Sistema chama o gateway configurado (Efí, Mercado Pago ou Asaas) e grava `cobrancas`
3. Cliente recebe o link ou o Pix; o usuário confirma o pagamento ou cancela

## Regras de Negócio

- Totais, descontos e baixa de estoque são sempre calculados no servidor; item enviado pelo cliente precisa pertencer ao registro e o id do registro é conferido no banco
- CPF ou CNPJ e e-mail de cliente são únicos
- Cliente novo sem senha informada ganha o CPF ou CNPJ sem pontuação como senha inicial (guardada com hash)
- Toda rota do painel exige permissão declarada em `permissions_map.php`; método sem entrada é negado
- Senha trocada, conta desativada ou expirada e grupo desativado encerram as sessões na requisição seguinte
- Exceder as tentativas de login bloqueia o IP e o e-mail por um período e responde 429 na API
- A API só existe com `API_ENABLED=true`; token de API não carrega permissões, elas vêm do banco a cada chamada
- Anexos e arquivos nunca têm URL pública: saem por controller que checa permissão (ou o dono, na área do cliente)
- Schema só muda por migration; o painel bloqueia (503) até as migrations pendentes serem aplicadas
- Atualização do sistema só baixa de `github.com/<usuário>/<repo>/releases/download/<tag>/` e exige SHA256 igual ao do `.sha256`

## Limites Técnicos

- **Upload:** 10 MB por arquivo (`UPLOAD_MAX_KB`), 40 megapixels por imagem (`UPLOAD_MAX_PIXELS`), além de `upload_max_filesize` e `post_max_size` do PHP
- **Listagens do painel:** 10 itens por página
- **API:** 300 requisições por minuto por IP (`API_RATE_LIMIT`); access token do usuário dura no máximo 1 hora; refresh token 30 dias (`API_REFRESH_TOKEN_EXPIRE_TIME`)
- **Sessão:** 7200 segundos (`APP_SESS_EXPIRATION`)
- **Chave JWT:** mínimo de 32 caracteres

## Integrações Externas

| Serviço | Finalidade | Protocolo |
| ------- | ---------- | --------- |
| Efí (Gerencianet) | Cobranças, boleto e Pix | REST pelo SDK `efipay/sdk-php-apis-efi` |
| Mercado Pago | Cobranças | REST pelo SDK `mercadopago/dx-php` |
| Asaas | Cobranças | REST pelo SDK `codephix/asaas-sdk` |
| Servidor SMTP | E-mails de OS, cobrança e recuperação de senha | SMTP |
| GitHub Releases | Atualização do sistema | HTTPS |
| WhatsApp | Link de mensagem pronta ao cliente (`notifica_whats`) | URL de compartilhamento |

## Glossário

| Termo | Definição |
| ----- | --------- |
| OS | Ordem de serviço, o registro central do negócio |
| Emitente | Dados da empresa que aparecem nos documentos impressos |
| Garantia | Termo de garantia aplicado a uma OS ou venda |
| Conecte | Nome interno da área do cliente (controller `Mine`) |
| Faturar | Fechar a OS ou venda gerando o lançamento financeiro |
| Lançamento | Receita ou despesa do módulo financeiro |
| Permissão `a/e/d/v/r/c` | Adicionar, editar, excluir, visualizar, relatório e configurar uma área |
| Gateway | Provedor de pagamento (Efí, Mercado Pago ou Asaas) |
