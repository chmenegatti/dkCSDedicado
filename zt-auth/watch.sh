#!/bin/sh
set -e

API="https://api.zerotier.com/api/v1"
POLL_INTERVAL="${POLL_INTERVAL:-15}"

# ─── Validate config ──────────────────────────────────────────────────────────
if [ -z "$ZEROTIER_API_TOKEN" ] || [ -z "$ZEROTIER_NETWORK_ID" ]; then
    echo "❌  ZEROTIER_API_TOKEN e ZEROTIER_NETWORK_ID são obrigatórios."
    echo "    Gere um token em: https://my.zerotier.com/account"
    exit 1
fi

echo "┌────────────────────────────────────────────┐"
echo "│   ZeroTier Auto-Auth                       │"
echo "└────────────────────────────────────────────┘"
echo "  Network : $ZEROTIER_NETWORK_ID"
echo "  Intervalo: ${POLL_INTERVAL}s"
echo ""

# ─── Poll loop ────────────────────────────────────────────────────────────────
while true; do

    # Fetch all members from ZeroTier Central API
    MEMBERS=$(curl -sf \
        -H "Authorization: token $ZEROTIER_API_TOKEN" \
        "${API}/network/${ZEROTIER_NETWORK_ID}/member") || {
        echo "[$(date '+%H:%M:%S')] ⚠️  API inacessível — tentando em ${POLL_INTERVAL}s"
        sleep "$POLL_INTERVAL"
        continue
    }

    # Collect node IDs that are not yet authorized
    PENDING=$(echo "$MEMBERS" | jq -r '.[] | select(.config.authorized == false) | .nodeId')

    for NODE_ID in $PENDING; do
        NAME=$(echo "$MEMBERS" | jq -r \
            ".[] | select(.nodeId == \"$NODE_ID\") | .name // \"(sem nome)\"")

        # Authorize the member
        RESULT=$(curl -sf -X POST \
            -H "Authorization: token $ZEROTIER_API_TOKEN" \
            -H "Content-Type: application/json" \
            -d '{"config":{"authorized":true}}' \
            "${API}/network/${ZEROTIER_NETWORK_ID}/member/${NODE_ID}") && {

            IP=$(echo "$RESULT" | jq -r '.config.ipAssignments[0] // "IP pendente"')
            echo "[$(date '+%H:%M:%S')] ✅  Autorizado: $NODE_ID  nome=$NAME  ip=$IP"
        } || {
            echo "[$(date '+%H:%M:%S')] ❌  Falha ao autorizar: $NODE_ID"
        }
    done

    sleep "$POLL_INTERVAL"
done
