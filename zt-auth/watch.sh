#!/bin/sh
# ZeroTier self-hosted controller — auto-auth sem dependência do ZeroTier Central

ZT_DATA="/var/lib/zerotier-one"
ZT_API="http://localhost:9993"
POLL_INTERVAL="${POLL_INTERVAL:-15}"

# ── Aguarda o daemon ZeroTier inicializar ──────────────────────────────────────
echo "⏳  Aguardando ZeroTier inicializar..."
until [ -f "$ZT_DATA/authtoken.secret" ]; do
    sleep 3
done
TOKEN=$(cat "$ZT_DATA/authtoken.secret")
until curl -sf -H "X-ZT1-AUTH: $TOKEN" "$ZT_API/status" > /dev/null 2>&1; do
    sleep 3
done

NODE_ID=$(cut -d: -f1 "$ZT_DATA/identity.public")
NETWORK_ID="${NODE_ID}000001"

# ── Cria a rede se ainda não existir ──────────────────────────────────────────
EXISTING=$(curl -sf -H "X-ZT1-AUTH: $TOKEN" \
    "$ZT_API/controller/network/$NETWORK_ID" 2>/dev/null | jq -r '.id // empty')

if [ -z "$EXISTING" ]; then
    echo "🌐  Criando rede self-hosted: $NETWORK_ID"
    curl -sf -X POST \
        -H "X-ZT1-AUTH: $TOKEN" \
        -H "Content-Type: application/json" \
        -d "{
            \"name\": \"CS16 Private Network\",
            \"private\": true,
            \"v4AssignMode\": {\"zt\": true},
            \"ipAssignmentPools\": [{
                \"ipRangeStart\": \"10.144.0.1\",
                \"ipRangeEnd\":   \"10.144.0.254\"
            }],
            \"routes\": [{\"target\": \"10.144.0.0/24\", \"via\": null}]
        }" \
        "$ZT_API/controller/network/$NETWORK_ID" > /dev/null
    echo "✅  Rede criada."
fi

# ── Entra na própria rede ──────────────────────────────────────────────────────
JOINED=$(curl -sf -H "X-ZT1-AUTH: $TOKEN" \
    "$ZT_API/network/$NETWORK_ID" 2>/dev/null | jq -r '.id // empty')
if [ -z "$JOINED" ]; then
    echo "🔗  Entrando na rede..."
    curl -sf -X POST \
        -H "X-ZT1-AUTH: $TOKEN" \
        -H "Content-Type: application/json" \
        -d '{}' \
        "$ZT_API/network/$NETWORK_ID" > /dev/null
fi

# ── Autoriza o próprio nó do servidor ─────────────────────────────────────────
SELF_AUTH=$(curl -sf -H "X-ZT1-AUTH: $TOKEN" \
    "$ZT_API/controller/network/$NETWORK_ID/member/$NODE_ID" 2>/dev/null | \
    jq -r '.authorized // false')
if [ "$SELF_AUTH" != "true" ]; then
    curl -sf -X POST \
        -H "X-ZT1-AUTH: $TOKEN" \
        -H "Content-Type: application/json" \
        -d '{"authorized": true}' \
        "$ZT_API/controller/network/$NETWORK_ID/member/$NODE_ID" > /dev/null
    echo "✅  Servidor autorizado na própria rede."
fi

# ── Banner ─────────────────────────────────────────────────────────────────────
echo ""
echo "┌────────────────────────────────────────────────────┐"
echo "│   ZeroTier Self-Hosted Controller  🌐              │"
echo "├────────────────────────────────────────────────────┤"
printf "│   Network ID : %-35s│\n" "$NETWORK_ID"
printf "│   Node ID    : %-35s│\n" "$NODE_ID"
printf "│   Intervalo  : %-35s│\n" "${POLL_INTERVAL}s"
echo "└────────────────────────────────────────────────────┘"
echo ""
echo "  Amigos entram na rede com:"
echo "    zerotier-cli join $NETWORK_ID"
echo ""

# ── Loop: autoriza novos membros automaticamente ───────────────────────────────
while true; do
    MEMBER_IDS=$(curl -sf \
        -H "X-ZT1-AUTH: $TOKEN" \
        "$ZT_API/controller/network/$NETWORK_ID/member" 2>/dev/null | \
        jq -r 'keys[]' 2>/dev/null) || {
        echo "[$(date '+%H:%M:%S')] ⚠️  API local inacessível, tentando em ${POLL_INTERVAL}s"
        sleep "$POLL_INTERVAL"
        continue
    }

    for MID in $MEMBER_IDS; do
        MEMBER=$(curl -sf \
            -H "X-ZT1-AUTH: $TOKEN" \
            "$ZT_API/controller/network/$NETWORK_ID/member/$MID" 2>/dev/null)

        AUTHORIZED=$(echo "$MEMBER" | jq -r '.authorized // false')

        if [ "$AUTHORIZED" = "false" ]; then
            RESULT=$(curl -sf -X POST \
                -H "X-ZT1-AUTH: $TOKEN" \
                -H "Content-Type: application/json" \
                -d '{"authorized": true}' \
                "$ZT_API/controller/network/$NETWORK_ID/member/$MID" 2>/dev/null)

            IP=$(echo "$RESULT" | jq -r '.ipAssignments[0] // "atribuindo..."')
            echo "[$(date '+%H:%M:%S')] ✅  Autorizado: $MID  ip=$IP"
        fi
    done

    sleep "$POLL_INTERVAL"
done
