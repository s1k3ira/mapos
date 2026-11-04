# Webhooks de pagamento

Com os webhooks, a cobrança vira paga sozinha quando o gateway avisa que o pagamento caiu, sem ninguém abrir o Map-OS. Sem webhook configurado, continua valendo o botão "Atualizar" da cobrança, que consulta o gateway na hora.

## Como funciona

Há uma rota por gateway. Todas aceitam só `POST`, funcionam com `API_ENABLED` ligado ou desligado, não usam sessão e ficam fora do CSRF:

| Gateway | Rota | Autenticação |
| --- | --- | --- |
| Efí | `POST /webhooks/efi/<EFI_WEBHOOK_SECRET>` | o segredo vai no caminho; o corpo é o formulário `notification=<token>` |
| Mercado Pago | `POST /webhooks/mercadopago` | headers `x-signature` e `x-request-id`; query `data.id` e `type=payment`; assinatura com `MP_WEBHOOK_SECRET` |
| Asaas | `POST /webhooks/asaas` | header `asaas-access-token` igual a `ASAAS_WEBHOOK_TOKEN` |

O que o Map-OS faz a cada chamada:

- Confere o segredo, o token ou a assinatura (com `hash_equals`) antes de qualquer outra coisa.
- **Nunca confia no corpo.** Ele só diz qual pagamento mudou; o Map-OS reconsulta o pagamento no gateway e usa a resposta de lá.
- É idempotente: cada evento é gravado em `webhook_eventos` (`gateway`, `evento_id`), então uma reentrega não baixa nada duas vezes.
- Confere o valor: se o valor pago for diferente de `cobrancas.total`, a cobrança não é baixada (resultado `divergente`, com linha de erro no log).
- O status pago nunca regride.
- Baixa o lançamento da OS ou da venda numa transação.
- Cobrança desconhecida responde 200 `ignorado`. O Asaas avisa de todos os pagamentos da conta, inclusive os que não vieram do Map-OS.

## Cadastrar a URL em cada gateway

Gere cada segredo com `openssl rand -hex 32` e grave no `application/.env`. Segredo vazio desliga o webhook daquele gateway (a rota responde 401). Os nomes de menu mudam com o tempo: procure o "painel de webhooks" ou de "integrações" do gateway e confira a documentação dele.

### Efí

1. Preencha `EFI_WEBHOOK_SECRET` no `.env`.
2. Não há nada a cadastrar no painel: a URL `https://seu-dominio/index.php/webhooks/efi/<EFI_WEBHOOK_SECRET>` vai sozinha em cada cobrança (`notification_url`).

### Mercado Pago

1. No painel de webhooks da sua aplicação, gere a assinatura secreta e copie para `MP_WEBHOOK_SECRET`.
2. A URL `https://seu-dominio/index.php/webhooks/mercadopago` vai sozinha em cada cobrança, mas só com `MP_WEBHOOK_SECRET` preenchido. Sem o segredo, a cobrança é criada sem `notification_url`.

### Asaas

1. Preencha `ASAAS_WEBHOOK_TOKEN` no `.env`.
2. No painel de webhooks do Asaas, cadastre a URL `https://seu-dominio/index.php/webhooks/asaas` e use o mesmo valor de `ASAAS_WEBHOOK_TOKEN` como token de autenticação.

Para limitar chamadas por IP, use `WEBHOOK_RATE_LIMIT` (padrão 120 por minuto; acima disso a resposta é 429).

## HTTPS obrigatório

A URL sai de `APP_BASEURL` (`site_url()`). Ela precisa ser HTTPS e pública, acessível pelo gateway. Atrás de proxy ou balanceador, configure `APP_PROXY_IPS` para o limite por IP ver o IP real de quem chama.

## Respostas

Sempre JSON, com `Cache-Control: no-store`.

| Código | Corpo | Quando | O que o gateway faz |
| --- | --- | --- | --- |
| 200 | `{"status":"aplicado"}` | pagamento conferido e baixado | considera entregue |
| 200 | `{"status":"repetido"}` | evento já processado | considera entregue |
| 200 | `{"status":"ignorado"}` | cobrança desconhecida, ou evento sem pagamento | considera entregue |
| 200 | `{"status":"divergente"}` | valor diferente do da cobrança; nada é baixado | considera entregue |
| 200 | `{"status":"sem_efeito"}` | status que não muda a cobrança | considera entregue |
| 401 | `{"status":"recusado"}` | segredo, token ou assinatura errados, ou segredo vazio no `.env` | |
| 405 | | método diferente de `POST` (header `Allow: POST`) | |
| 413 | | corpo maior que 64 KB | |
| 429 | | acima de `WEBHOOK_RATE_LIMIT` (header `Retry-After`) | |
| 503 | `{"status":"tente_novamente"}` | a consulta ao gateway, o banco ou o rollback falhou | reentrega (5xx) |

## Trocar um segredo

- **Efí:** o segredo está na URL gravada em cada cobrança já criada. Depois da troca, as cobranças antigas passam a receber 401. Para as pendentes, use "Atualizar" na cobrança.
- **Mercado Pago e Asaas:** troque no painel e no `.env` ao mesmo tempo. O Asaas pausa a fila de webhooks depois de falhas seguidas: reative no painel.

## Esconder o caminho da Efí nos logs

O segredo da Efí vai no caminho da URL, então o access log do servidor não deve registrá-lo. O log do Map-OS (`application/logs`) já não registra o caminho: só `webhook gateway=… evento=… cobranca=… resultado=… http=… duracao_ms=…`, nunca segredo, payload ou dado do pagador.

nginx (já aplicado no Docker, em `docker/etc/nginx/default.template.conf`):

```nginx
map $request_uri $loggable {
    ~webhooks/efi/ 0;
    default        1;
}
# dentro do server:
access_log /var/log/nginx/access.log combined if=$loggable;
```

Apache:

```apache
SetEnvIf Request_URI "webhooks/efi/" mapos_sem_log
CustomLog ${APACHE_LOG_DIR}/access.log combined env=!mapos_sem_log
```

O `error_log` do servidor ainda pode registrar a linha da requisição quando o próprio servidor falha.

## Reprocessar um evento

Evento que falhou (503) fica sem `processado_em` e é reprocessado sozinho na reentrega. Para reprocessar um já gravado (`ignorado` porque a cobrança ainda não existia, `divergente` depois de corrigir o valor), apague a linha e peça a reentrega no painel do gateway, ou use "Atualizar" na cobrança:

```sql
DELETE FROM webhook_eventos WHERE gateway = 'asaas' AND evento_id = 'evt_123';
```

Os valores de `gateway` são `efi`, `mercadopago` e `asaas`. A retenção apaga eventos com mais de 90 dias.

## Destravar a fila de e-mail

- O lock da fila (`GET_LOCK`) solta quando o processo morre; não precisa de ação.
- E-mail em `sending` preso volta sozinho em 15 minutos pelo `email/retry`.
- E-mail `failed` com 5 tentativas é definitivo. Para reenviar:

```sql
UPDATE email_queue SET status = 'pending', tentativas = 0, reservado_por = NULL, reservado_em = NULL WHERE id = 42;
```

Os dois crons são `email/process` (a cada 2 minutos) e `email/retry` (a cada 5 minutos). Ambos saem com 0 em caso de sucesso e 1 em caso de falha.

## Conferir

O smoke de segurança sobe o ambiente Docker, confere as rotas e derruba tudo:

```
scripts/smoke-seguranca.sh
```

Pré-requisito: instalação concluída com `API_ENABLED=true`. Saída 0 = ok, 1 = alguma conferência falhou, 2 = o ambiente não subiu ou falta o compose. Variáveis: `SMOKE_URL` (padrão `http://localhost:8000/`), `SMOKE_ESPERA_SEGUNDOS` e `SMOKE_SEM_DOCKER=1` para usar um ambiente que já está no ar.

Em produção, uma chamada sem credencial tem que responder `401`:

```
curl -i -X POST https://seu-dominio/index.php/webhooks/asaas
```
