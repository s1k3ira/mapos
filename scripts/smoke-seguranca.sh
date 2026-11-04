#!/usr/bin/env bash
# Smoke de segurança do Map-OS (RF-60).
#
# Pré-requisito: Map-OS instalado no Docker, com API_ENABLED=true em application/.env.
# Uso: scripts/smoke-seguranca.sh
# Variáveis:
#   SMOKE_URL              URL base (padrão http://localhost:8000, sem barra final)
#   SMOKE_ESPERA_SEGUNDOS  tempo máximo esperando o ambiente responder (padrão 90)
#   SMOKE_SEM_DOCKER       1 = não sobe nem derruba o compose, usa o que já está no ar
# Códigos de saída: 0 tudo certo, 1 algum veredito falhou, 2 ambiente não subiu/sem compose.

set -u
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SMOKE_URL="${SMOKE_URL:-http://localhost:8000}"
SMOKE_URL="${SMOKE_URL%/}"
SMOKE_ESPERA_SEGUNDOS="${SMOKE_ESPERA_SEGUNDOS:-90}"
SMOKE_SEM_DOCKER="${SMOKE_SEM_DOCKER:-0}"

COMPOSE=()
detectar_compose() {
    if command -v docker-compose >/dev/null 2>&1; then
        COMPOSE=(docker-compose)
    elif command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        COMPOSE=(docker compose)
    else
        echo "docker-compose não encontrado" >&2
        exit 2
    fi
}

derrubar() {
    (cd "$RAIZ/docker" && "${COMPOSE[@]}" down)
}

if [ "$SMOKE_SEM_DOCKER" != "1" ]; then
    detectar_compose
    if ! (cd "$RAIZ/docker" && "${COMPOSE[@]}" up -d); then
        echo "Falha ao subir o ambiente com ${COMPOSE[*]} up -d" >&2
        exit 2
    fi
    trap derrubar EXIT
fi

inicio=$SECONDS
until curl -sf -o /dev/null --max-time 5 "$SMOKE_URL/"; do
    if [ $((SECONDS - inicio)) -ge "$SMOKE_ESPERA_SEGUNDOS" ]; then
        echo "O ambiente não respondeu em ${SMOKE_ESPERA_SEGUNDOS}s em ${SMOKE_URL}/" >&2
        exit 2
    fi
    sleep 1
done

falhas=0
checar() {
    local descricao="$1" metodo="$2" caminho="$3"
    shift 3
    local status aceito
    status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -X "$metodo" "$SMOKE_URL$caminho")"
    for aceito in "$@"; do
        if [ "$status" = "$aceito" ]; then
            printf 'OK      %s  %s %s → %s\n' "$descricao" "$metodo" "$caminho" "$status"
            return
        fi
    done
    printf 'FALHOU  %s  %s %s → %s (esperado: %s)\n' "$descricao" "$metodo" "$caminho" "$status" "$*"
    falhas=$((falhas + 1))
}

checar "processo" GET / 200 302
checar "API sem token" GET /index.php/api/v1 401
checar "webhook MP sem assinatura" POST /index.php/webhooks/mercadopago 401
checar "webhook Asaas sem token" POST /index.php/webhooks/asaas 401
checar "webhook Efí com segredo inválido" POST /index.php/webhooks/efi/invalido 401
checar "login sem corpo" POST /index.php/login/verificarLogin 403 422
checar "logout do painel por GET" GET /index.php/login/sair 405
checar "logout da área do cliente por GET" GET /index.php/mine/sair 405
checar "refresh do cliente sem corpo" POST /index.php/api/v1/client/refresh 401
checar "troca de senha do cliente sem token" PUT /index.php/api/v1/client/password 401
checar "abertura de OS do cliente sem token" POST /index.php/api/v1/client/os 401
checar "edição de OS do operador sem token" PUT /index.php/api/v1/os/1 401

if [ "$falhas" -gt 0 ]; then
    echo "Smoke de segurança: $falhas verificação(ões) com falha"
    exit 1
fi
echo "Smoke de segurança: tudo certo"
exit 0
