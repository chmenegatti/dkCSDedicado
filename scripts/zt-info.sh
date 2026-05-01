#!/bin/bash
# Mostra o status do ZeroTier e o IP para compartilhar com os amigos
set -e

CONTAINER="zerotier"

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
    echo "❌ Container ZeroTier não está rodando."
    echo "   Execute: docker compose up -d zerotier"
    exit 1
fi

echo "=== Status do ZeroTier ==="
docker exec "$CONTAINER" zerotier-cli status

echo ""
echo "=== Redes conectadas ==="
docker exec "$CONTAINER" zerotier-cli listnetworks

echo ""
echo "=== IP ZeroTier (compartilhe este IP com os amigos) ==="
ZT_IP=$(docker exec "$CONTAINER" zerotier-cli listnetworks 2>/dev/null \
    | awk 'NR>1 {print $NF}' \
    | grep -oP '(\d+\.){3}\d+' \
    | head -1)

if [ -n "$ZT_IP" ]; then
    echo ""
    echo "  ✅  $ZT_IP"
    echo ""
    echo "  Os amigos conectam com:  connect $ZT_IP:27015"
else
    echo ""
    echo "  ⚠️  IP ainda não atribuído. Verifique se o device foi autorizado em:"
    echo "     https://my.zerotier.com → Networks → Members"
    echo ""
    echo "  Node ID deste servidor:"
    docker exec "$CONTAINER" zerotier-cli status | awk '{print $3}'
fi
