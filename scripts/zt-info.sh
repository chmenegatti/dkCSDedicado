#!/bin/bash
# Mostra o Network ID e o IP ZeroTier do servidor (self-hosted controller)
set -e

CONTAINER="zerotier"
ZT_DATA="/var/lib/zerotier-one"
ZT_API="http://localhost:9993"

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
    echo "❌ Container ZeroTier não está rodando."
    echo "   Execute: docker compose up -d zerotier"
    exit 1
fi

TOKEN=$(docker exec "$CONTAINER" cat "$ZT_DATA/authtoken.secret" 2>/dev/null)
NODE_ID=$(docker exec "$CONTAINER" cat "$ZT_DATA/identity.public" 2>/dev/null | cut -d: -f1)

if [ -z "$TOKEN" ] || [ -z "$NODE_ID" ]; then
    echo "⚠️  ZeroTier ainda inicializando, aguarde alguns segundos..."
    exit 1
fi

NETWORK_ID="${NODE_ID}000001"

echo "=== ZeroTier Self-Hosted Controller ==="
echo ""
printf "  Node ID    : %s\n" "$NODE_ID"
printf "  Network ID : %s\n" "$NETWORK_ID"

# IP do servidor na rede ZeroTier (lê do controller local via API do host)
ZT_IP=$(curl -sf -H "X-ZT1-AUTH: $TOKEN" \
    "$ZT_API/controller/network/$NETWORK_ID/member/$NODE_ID" 2>/dev/null | \
    python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('ipAssignments',[''])[0])" 2>/dev/null)

echo ""
echo "=== IP ZeroTier do Servidor ==="
if [ -n "$ZT_IP" ]; then
    echo ""
    echo "  ✅  $ZT_IP"
    echo ""
    echo "  Compartilhe com seus amigos:"
    echo "    1) Entrar na rede : zerotier-cli join $NETWORK_ID"
    echo "    2) Conectar no CS : connect $ZT_IP:27015"
else
    echo ""
    echo "  ⚠️  IP ainda não atribuído. O zt-auth pode ainda estar inicializando."
fi

echo ""
echo "=== Membros na Rede ==="
curl -sf -H "X-ZT1-AUTH: $TOKEN" \
    "$ZT_API/controller/network/$NETWORK_ID/member" 2>/dev/null | \
    python3 -c "
import sys, json, urllib.request, urllib.error
members = json.load(sys.stdin)
if not members:
    print('  Nenhum membro ainda.')
else:
    for mid in members:
        print(f'  {mid}')
" 2>/dev/null || echo "  Não foi possível listar membros."
