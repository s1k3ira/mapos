# Autenticação da API (v5)

Quem integra com a API (`/api/v1`), como o app móvel, precisa saber o que mudou na v5. O login e o uso do access token seguem iguais; o que mudou é a renovação (veja "Renovar" abaixo) e a validade do token do usuário.

Rode as migrations (Configurações → Sistema → Atualizar banco de dados, ou `php index.php tools migrate`) para criar `api_refresh_tokens` e `api_rate_limit` e as colunas `token_versao` de `usuarios` e `clientes`. Sem elas o login funciona, só não emite refresh token, o limite de requisições fica desligado e trocar a senha não derruba os access tokens já emitidos.

## Login

`POST /api/v1/login` com `{"email": "...", "password": "..."}`.

Com a API desligada (`API_ENABLED=false`) todas as rotas `api/v1`, inclusive por URL direta de controller, respondem `404`. O login tem limite de tentativas (`429` com `Retry-After`), como o do painel. Quem ativou o 2FA precisa enviar também o campo `totp` ([dois-fatores.md](dois-fatores.md)).

```json
{
  "status": true,
  "message": "Login realizado com sucesso!",
  "result": {
    "access_token": "eyJ...",
    "refresh_token": "Zk3...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "permissions": [ { "...": "..." } ]
  }
}
```

`refresh_token`, `token_type` e `expires_in` são **campos novos**. `access_token` e `permissions` são os de sempre. O access token vai no header `Authorization: Bearer <access_token>`.

## Access token

- JWT HS256 (biblioteca `firebase/php-jwt`, instalada pelo Composer; a cópia em `application/third_party/php-jwt` foi removida).
- Tem a claim padrão `exp` (`API_TOKEN_EXPIRE_TIME`, em segundos) e `iat`. O token do usuário que vem com refresh token dura no máximo 1 hora, mesmo que `API_TOKEN_EXPIRE_TIME` seja maior; o token da área do cliente também dura no máximo 1 hora e vem com refresh token (veja "API do cliente"). A claim `API_TIME` continua por compatibilidade e será removida; não use. Tokens emitidos antes da v5 (sem `exp`) seguem valendo até vencer pelo `API_TIME`.
- A chave `API_JWT_KEY` precisa ter pelo menos 32 caracteres (256 bits), exigência da biblioteca. As instalações novas já geram uma assim. Em produção, com a chave vazia, mais curta que isso ou igual ao `enter_jwt_key` do `.env.example`, a API responde 503 até a troca (Configurações > Sistema, "Gerar uma chave nova para os tokens", ou edite o `.env`). Do mesmo modo, o 2FA não ativa com a `APP_ENCRYPTION_KEY` do `.env.example`.
- Leva `tv` (versão dos tokens da conta) e, no usuário, `sid` (a sessão do refresh token). Trocar a senha ou desativar a conta soma 1 à versão e o logout revoga a sessão: em ambos os casos o access token já emitido passa a dar `401` na hora, sem esperar o `exp`. Tokens emitidos antes da migration não têm `tv` e valem como versão 0, até a primeira troca de senha.
- **O token não carrega permissões.** O usuário e a permissão são consultados no banco a cada requisição: token de usuário ou grupo desativado, ou de usuário removido, dá `401` na hora (`403` é só falta de permissão), e uma permissão alterada vale na próxima chamada. O app não deve decidir permissões só pelo que veio no login.

## Renovar: refresh token (novo)

`POST /api/v1/refresh` com `{"refresh_token": "..."}`. Responde como o login (novo `access_token`, **novo** `refresh_token`, `expires_in`, `permissions`) ou `401` com `"Faça o login novamente!"`.

- O refresh token é de **uso único**: guarde o novo e descarte o anterior. Dura `API_REFRESH_TOKEN_EXPIRE_TIME` (padrão 30 dias).
- Se a resposta se perder na rede, repetir o mesmo token em até 10 segundos só devolve `401`, sem derrubar a sessão. Reutilizar um token já usado depois disso revoga a sessão inteira (cópia vazada).
- Trocar a senha ou desativar o usuário revoga todos os refresh tokens dele e invalida os access tokens já emitidos.

## Logout (novo)

`POST /api/v1/logout` com `{"refresh_token": "..."}` revoga a sessão, inclusive o access token dela. Sempre responde `200`.

## `GET /api/v1/reGenToken` (removido)

A rota que renovava um access token vencido, sem senha e sem refresh token, foi removida: dava para renovar um token vazado indefinidamente. Quem ainda a chama recebe `404`. Use o refresh token (acima) ou faça o login de novo.

## 401 ou 403

- **`403`** é só falta de permissão: `{"status": false, "message": "..."}`. Refrescar o token não resolve.
- **`401`** é todo problema de token ou de conta: token ausente (`Faça login para acessar a API.`), inválido, expirado, de outro tipo (token de cliente numa rota de operador, e o inverso), ou de usuário ou grupo desativado ou expirado (`Faça o login novamente!`). Nesses casos o app deve renovar o token ou voltar para o login.
- `POST /api/v1/refresh` recusa grupo desativado e revoga os refresh tokens do usuário.
- Exceção não tratada responde `500` em JSON: `{"status": false, "message": "Erro interno"}`.

## API do cliente

Rotas da área do cliente, sob `/api/v1/client`.

| Rota | Corpo | Resposta |
| --- | --- | --- |
| `POST /api/v1/client/auth` | e-mail e senha do cliente | `result.{access_token, refresh_token, token_type: "Bearer", expires_in: 3600, troca_de_senha_obrigatoria}` |
| `POST /api/v1/client/refresh` | `{"refresh_token": "..."}` | `200` com par novo, ou `401` `Faça o login novamente!` |
| `POST /api/v1/client/logout` | `{"refresh_token": "..."}` | `204`, sempre |
| `PUT /api/v1/client/password` (Bearer) | `{"senha_atual": "...", "senha_nova": "..."}` | `200` com par novo; `422` com `erros.senha_atual` ou `erros.senha_nova`; `429` com `Retry-After` |

- O access token do cliente dura no máximo 1 hora (`expires_in: 3600`), mesmo que `API_TOKEN_EXPIRE_TIME` seja maior, e vem com refresh token. Tokens antigos, sem `sid`, são recusados (`401`).
- O refresh é de uso único: guarde o novo. Um refresh de operador nesta rota também dá `401`, e reutilizar um refresh já usado revoga a família inteira.
- A senha nova tem de 8 a 72 caracteres e precisa ser diferente da atual. Trocar a senha derruba os tokens anteriores.
- **Troca de senha obrigatória:** com `senha_trocar = 1` (cliente criado pelo operador, ou marcado por `tools marcarSenhasLegadas`), toda rota autenticada responde `403` com o código `troca_de_senha_obrigatoria`:

```json
{ "status": false, "code": "troca_de_senha_obrigatoria", "message": "Defina uma nova senha antes de continuar." }
```

  Ficam liberadas só `client/password`, `client/refresh` e `client/logout`. O app deve mandar o cliente para a tela de nova senha.

## Paginação e filtros de data

- Listagens `os`, `clientes`, `produtos`, `servicos`, `usuarios`, `audit` e, na área do cliente, `client/os`, `client/compras` e `client/cobrancas`: `perPage` é um inteiro de 1 a 100 (padrão 20; acima de 100 vira 100) e `page` é um inteiro a partir de 0. O deslocamento é `page * perPage`.
- `perPage` ou `page` que não sejam inteiros, ou `perPage` igual ou menor que 0, respondem `422` com `Parâmetros de paginação inválidos.` e o campo `erros`.
- `GET /api/v1/os`: `from` e `to` em `dd/mm/aaaa`. `GET /api/v1/calendario`: `start` e `end` em `aaaa-mm-dd`. Formato inválido, ou janela maior que 366 dias, responde `422`. O mesmo vale para `dataInicial` e `dataFinal` de `POST` e `PUT /api/v1/os`.

## Cliente criado pela API

`POST /api/v1/clientes` sem `senha` responde `201` com `result.senha_inicial` (16 caracteres) e `result.email_enviado` (`true` ou `false`, conforme o e-mail com o acesso foi enfileirado), com `Cache-Control: no-store`. A senha inicial aparece **só nesta resposta**; o `GET` nunca a devolve. Com `senha` informada, a resposta não traz nenhum dos dois campos. Em todo caso o cliente nasce com troca de senha obrigatória.

## Limite de abertura de OS pelo cliente

`POST /api/v1/client/os` responde `201` com `{"status": true, "message": "...", "os_id": 123}`. Acima de `CLIENTE_OS_LIMITE_DIARIO` aberturas no dia (padrão `10`; `0` desliga; o dia segue `APP_TIMEZONE`) responde `429` com `Retry-After` igual aos segundos até 00:00 e a mensagem `Limite diário de aberturas atingido. Tente novamente amanhã ou fale com a assistência.` A tela `mine/adicionarOs` mostra a mesma mensagem.

## Outras mudanças de contrato

- **`PUT /api/v1/usuarios/{id}` no próprio usuário** grava só os campos de perfil (`nome`, `rg`, `cpf`, `cep`, `rua`, `numero`, `bairro`, `cidade`, `estado`, `email`, `telefone`, `celular`) e **ignora** `permissoes_id`, `situacao` e `dataExpiracao`. Trocar a própria senha exige `senha_atual` (sem ela ou errada, `403`); a nova senha tem no mínimo 8 caracteres e é gravada com hash. O e-mail não pode repetir (`400`). Como trocar a senha invalida os access tokens, o app precisa entrar de novo depois. `situacao` e `permissoes_id` só são exigidos ao editar **outro** usuário, o que pede a permissão `cUsuario`; o super admin não pode ser desativado nem sair do grupo Administrador. Em `POST`, `PUT` e `DELETE` de outro usuário vale a regra de delegação do painel: quem não é administrador só mexe em usuários e grupos com permissões que o próprio grupo dele tem, e só o usuário principal altera o usuário principal (`403`).
- **Respostas sem senha:** o hash da senha não aparece mais nas respostas de clientes, usuários e OS.
- **Anexos de OS:** a resposta traz `url` e `thumb_url` (autenticadas, `Authorization: Bearer`) e não traz mais o caminho do arquivo. O arquivo vem de `GET /api/v1/os/<os>/anexos/<id>` (`?miniatura=1` para a miniatura) e, na API do cliente, de `GET /api/v1/client/os/<os>/anexos/<id>`. Veja [armazenamento-de-arquivos.md](armazenamento-de-arquivos.md).
- **OS:** o item (produto ou serviço) e a anotação precisam pertencer à OS da URL (`404` se não); OS faturada ou cancelada só é alterada com `control_editos` ligado (`403`); o desconto é calculado no servidor e preço e quantidade são validados (`400`).
- **OS faturada ou cancelada:** `PUT /api/v1/os/{id}` em OS `Faturado`, `Cancelado` ou com `faturado = 1` responde `403` (`OS Faturada/Cancelada não pode ser alterada.`), e `status: "Faturado"` responde `422`. `faturado`, `valorTotal` e `lancamento` nunca vêm do corpo.

## CORS e limite de requisições

Configurados no `.env` (lidos por `application/config/rest.php`):

| Variável | Padrão | O que faz |
|---|---|---|
| `API_CORS_ORIGINS` | vazio | Origens que podem chamar a API de um navegador, separadas por vírgula, ou `*`. Vazio: sem CORS, como na v4. App Android/iOS não precisa. Os headers liberados incluem `Authorization`, `Content-Type` e `App-Version`. |
| `API_RATE_LIMIT` | `300` | Requisições por minuto por IP. `0` desliga. Acima disso a API responde `429` em JSON (`{"status": false, "message": "..."}`) com `Retry-After`. |
| `API_REFRESH_TOKEN_EXPIRE_TIME` | `2592000` | Duração do refresh token, em segundos. |
| `API_TOKEN_EXPIRE_TIME` | `3600` (gravado pelo instalador) | Duração do access token, em segundos; do usuário e do cliente, no máximo 1 hora. |
| `API_ENABLED` | `false` | `false` desliga a API (`404`). |
| `CLIENTE_OS_LIMITE_DIARIO` | `10` | Aberturas de OS por cliente por dia na API do cliente. `0` desliga. Acima disso, `429` com `Retry-After`. |
| `WEBHOOK_RATE_LIMIT` | `120` | Chamadas por minuto por IP nos webhooks de pagamento (`429` acima disso). Veja [webhooks-de-pagamento.md](webhooks-de-pagamento.md). |

## O que um app novo deve fazer

1. Guardar `access_token` e `refresh_token` (de preferência em armazenamento seguro).
2. Ao receber `401` com o access token, chamar `POST /refresh` uma vez e repetir a requisição.
3. Se o refresh der `401`, voltar para a tela de login.
4. No logout, chamar `POST /logout` antes de apagar os tokens.
5. Tratar `429` esperando o `Retry-After`.
