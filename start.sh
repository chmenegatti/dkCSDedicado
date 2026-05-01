#!/bin/bash
set -e

# ─── Config ───────────────────────────────────────────────────────────────────
SERVER_NAME="${SERVER_NAME:-Counter-Strike 1.6 Server}"
MAP="${MAP:-de_dust2}"
MAXPLAYERS="${MAXPLAYERS:-16}"
SV_PASSWORD="${SV_PASSWORD:-}"
RCON_PASSWORD="${RCON_PASSWORD:-changeme}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
SERVER_REGION="${SERVER_REGION:-2}"   # 2 = South America

HLDS_DIR="/home/steam/hlds"
CSTRIKE="$HLDS_DIR/cstrike"

# ─── SteamCMD update ──────────────────────────────────────────────────────────
echo ">>> Updating HLDS via SteamCMD..."
/home/steam/steamcmd/steamcmd.sh \
    +force_install_dir "$HLDS_DIR" \
    +login anonymous \
    +app_set_config 90 mod cstrike \
    +app_update 90 validate \
    +quit

mkdir -p ~/.steam/sdk32
ln -sf "$HLDS_DIR/steamclient.so" ~/.steam/sdk32/steamclient.so 2>/dev/null || true

# Allow panel container (www-data) to write maps uploads
mkdir -p "$CSTRIKE/maps"
chmod 777 "$CSTRIKE/maps"
[ -d "$CSTRIKE/addons/podbot" ] && chmod 777 "$CSTRIKE/addons/podbot" || true
[ -f "$CSTRIKE/addons/podbot/botnames.txt" ] && chmod 666 "$CSTRIKE/addons/podbot/botnames.txt" || true

# ─── AMX Mod X + Metamod (install once into the volume) ───────────────────────
if [ ! -d "$CSTRIKE/addons/amxmodx" ]; then
    echo ">>> Installing Metamod + AMX Mod X (latest from GitHub)..."
    mkdir -p "$CSTRIKE"

    # Fetch latest release tag from GitHub (e.g. "1.10.0.5476")
    AMXX_TAG=$(curl -sf https://api.github.com/repos/alliedmodders/amxmodx/releases/latest \
        | grep '"tag_name"' | head -1 \
        | sed 's/.*"tag_name": *"\([^"]*\)".*/\1/')
    if [ -z "$AMXX_TAG" ]; then
        AMXX_TAG="1.10.0.5476"   # fallback
        echo ">>> GitHub API unavailable, using fallback: $AMXX_TAG"
    fi
    AMXX_VER=$(echo "$AMXX_TAG" | cut -d. -f1-3)     # 1.10.0
    AMXX_BUILD=$(echo "$AMXX_TAG" | cut -d. -f4)     # 5476
    AMXX_URL="https://github.com/alliedmodders/amxmodx/releases/download/${AMXX_TAG}"
    echo ">>> AMX Mod X ${AMXX_TAG}"

    curl -fsSL "${AMXX_URL}/amxmodx-${AMXX_VER}-git${AMXX_BUILD}-base-linux.tar.gz" \
        | tar -xz -C "$CSTRIKE"
    curl -fsSL "${AMXX_URL}/amxmodx-${AMXX_VER}-git${AMXX_BUILD}-cstrike-linux.tar.gz" \
        | tar -xz -C "$CSTRIKE"

    # Hook Metamod into HLDS (replaces the game DLL entry in liblist.gam)
    if [ -f "$CSTRIKE/liblist.gam" ]; then
        sed -i 's|gamedll_linux "[^"]*"|gamedll_linux "addons/metamod/dlls/metamod.so"|' \
            "$CSTRIKE/liblist.gam"
    fi

    # Register AMX Mod X as a Metamod plugin
    mkdir -p "$CSTRIKE/addons/metamod"
    echo "linux addons/amxmodx/dlls/amxmodx_mm_i386.so" \
        > "$CSTRIKE/addons/metamod/plugins.ini"

    echo ">>> AMX Mod X installed."
fi

# ─── Re-apply Metamod hook (SteamCMD validate resets liblist.gam every start) ─
if [ -f "$CSTRIKE/liblist.gam" ]; then
    sed -i 's|gamedll_linux "[^"]*"|gamedll_linux "addons/metamod/dlls/metamod.so"|' \
        "$CSTRIKE/liblist.gam"
fi

# ─── Ensure both AMX and PODBot (if present) are always in plugins.ini ────────
{
    echo "linux addons/amxmodx/dlls/amxmodx_mm_i386.so"
    [ -f "$CSTRIKE/addons/podbot/podbot_mm.so" ] && echo "linux addons/podbot/podbot_mm.so"
} > "$CSTRIKE/addons/metamod/plugins.ini"

# ─── PODBot mm V3B24 (install once into the volume) ──────────────────────────
PODBOT_SO="$CSTRIKE/addons/podbot/podbot_mm.so"
if [ ! -f "$PODBOT_SO" ]; then
    echo ">>> Installing PODBot mm V3B24..."
    PODBOT_URL="https://github.com/APGRoboCop/podbot_mm/releases/download/V3B24-APG/podbot_full_V3B24.zip"
    PODBOT_TMP="/tmp/podbot_install"
    mkdir -p "$PODBOT_TMP"

    if curl -fsSL --max-time 120 -o "$PODBOT_TMP/podbot.zip" "$PODBOT_URL"; then
        # Extract the podbot/ folder, skip docs and Windows DLL
        unzip -q "$PODBOT_TMP/podbot.zip" "podbot/*" -d "$PODBOT_TMP/" -x "podbot/pod_v3 docs/*"
        mkdir -p "$CSTRIKE/addons/podbot"
        cp -r "$PODBOT_TMP/podbot/." "$CSTRIKE/addons/podbot/"
        rm -f "$CSTRIKE/addons/podbot/podbot_mm.dll"   # remove Windows DLL

        if [ -f "$PODBOT_SO" ]; then
            # botnames e permissões
            cat > "$CSTRIKE/addons/podbot/botnames.txt" <<'EOF'
Pistoleiro
Fragger
Sniper_Pro
Rush_B
Fumaceiro
Headshot_King
Bombeiro
Defusador
Clutch_Master
Wallbanger
Camper_Pro
FlashBot
AK_Master
AWP_God
Pistol_Pete
EOF
            chmod 666 "$CSTRIKE/addons/podbot/botnames.txt"
            chmod 777 "$CSTRIKE/addons/podbot"
            echo ">>> PODBot mm V3B24 installed."
        else
            echo ">>> WARNING: podbot_mm.so not found after extraction — skipping."
        fi
    else
        echo ">>> WARNING: PODBot mm download failed. Bots unavailable."
    fi
    rm -rf "$PODBOT_TMP"
fi

# ─── Admin password (re-applied on every start to pick up env changes) ─────────
if [ -n "$ADMIN_PASSWORD" ] && [ -f "$CSTRIKE/addons/amxmodx/configs/users.ini" ]; then
    USERS_INI="$CSTRIKE/addons/amxmodx/configs/users.ini"
    # Remove any previous password-auth line, then add current one
    grep -v '"ce"' "$USERS_INI" > /tmp/users_clean.ini 2>/dev/null || true
    echo "\"\" \"${ADMIN_PASSWORD}\" \"abcdefghijklmnopqrstu\" \"ce\"" >> /tmp/users_clean.ini
    mv /tmp/users_clean.ini "$USERS_INI"
fi

# ─── Mapcycle (create default only if missing — edits in the volume persist) ───
if [ ! -f "$CSTRIKE/mapcycle.txt" ]; then
    cat > "$CSTRIKE/mapcycle.txt" <<'EOF'
de_dust2
de_inferno
de_nuke
de_train
de_aztec
de_cbble
cs_assault
cs_italy
cs_office
cs_militia
EOF
fi
chmod 666 "$CSTRIKE/mapcycle.txt"

# ─── Generate server.cfg ──────────────────────────────────────────────────────
mkdir -p "$CSTRIKE"
cat > "$CSTRIKE/server.cfg" <<EOF
hostname        "${SERVER_NAME}"
rcon_password   "${RCON_PASSWORD}"
$([ -n "$SV_PASSWORD" ] && echo "sv_password     \"${SV_PASSWORD}\"")

// Internet play
sv_lan          0
sv_region       ${SERVER_REGION}

// Rates (balanced for most connections)
sv_maxrate      25000
sv_minrate      3500
sv_maxupdaterate 60
sv_minupdaterate 20

// Gameplay
mp_timelimit    30
mp_freezetime   6
mp_roundtime    5
mp_c4timer      35
mp_startmoney   1800
mp_friendlyfire 0
mp_autokick     1
mp_autoteambalance 1
mp_limitteams   2
mp_buytime      1.5

// PODBot — bots padrão (ajuste pelo painel ou edite aqui)
pb_maxbots      5
pb_minbotskill  40
pb_maxbotskill  80

// Performance
fps_max         500
sys_ticrate     1000
log             on
EOF

# ─── Launch ───────────────────────────────────────────────────────────────────
echo ">>> Starting CS 1.6 dedicated server (internet mode)..."
echo "    Server:  $SERVER_NAME"
echo "    Map:     $MAP | Slots: $MAXPLAYERS | Region: $SERVER_REGION"

cd "$HLDS_DIR"
export LD_LIBRARY_PATH=".:$HLDS_DIR:$HLDS_DIR/cstrike"
exec ./hlds_linux \
    -game cstrike \
    -console \
    -norestart \
    +ip 0.0.0.0 \
    +port 27015 \
    +map "$MAP" \
    +maxplayers "$MAXPLAYERS" \
    +hostname "$SERVER_NAME" \
    +sv_lan 0

