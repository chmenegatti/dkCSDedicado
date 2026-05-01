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
METAMOD_DIR="$CSTRIKE/addons/metamod"
METAMOD_SO="$METAMOD_DIR/dlls/metamod_i386.so"

# ─── SteamCMD update ──────────────────────────────────────────────────────────
echo ">>> Updating HLDS via SteamCMD..."
if [ -f "$HLDS_DIR/hlds_linux" ]; then
    # HLDS already installed — try to update but don't abort if Steam is unreachable
    /home/steam/steamcmd/steamcmd.sh -tcp \
        +force_install_dir "$HLDS_DIR" \
        +login anonymous \
        +app_set_config 90 mod cstrike \
        +app_update 90 \
        +quit || echo ">>> AVISO: SteamCMD não conseguiu conectar, usando instalação existente."
else
    # First run — must succeed; validate ensures a complete install
    /home/steam/steamcmd/steamcmd.sh -tcp \
        +force_install_dir "$HLDS_DIR" \
        +login anonymous \
        +app_set_config 90 mod cstrike \
        +app_update 90 validate \
        +quit
fi

mkdir -p ~/.steam/sdk32
ln -sf "$HLDS_DIR/steamclient.so" ~/.steam/sdk32/steamclient.so 2>/dev/null || true

# Allow panel container (www-data) to write into volume directories
chmod 777 "$CSTRIKE"                                                            # mapcycle.txt, tmp files
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

    echo ">>> AMX Mod X installed."
fi

# ─── Install Metamod-P binary if missing ──────────────────────────────────────
# metamod-p 1.21p38: maintained fork with better compatibility for modern HLDS
if [ ! -f "$METAMOD_SO" ]; then
    echo ">>> Installing Metamod-P 1.21p38..."
    METAMOD_TMP="/tmp/metamod_install"
    mkdir -p "$METAMOD_TMP" "$METAMOD_DIR/dlls"

    METAMOD_P_URL="https://github.com/Bots-United/metamod-p/releases/download/v1.21p38/metamod_i686_linux_win32-1.21p38.tar.xz"
    if curl -fsSL --max-time 120 "$METAMOD_P_URL" \
        | tar -xJ -C "$METAMOD_TMP" "metamod.so"; then
        cp "$METAMOD_TMP/metamod.so" "$METAMOD_SO"
        chmod 755 "$METAMOD_SO"
        echo ">>> Metamod-P installed."
    else
        echo ">>> ERROR: failed to download Metamod-P binary."
        exit 1
    fi

    rm -rf "$METAMOD_TMP"
fi

# ─── Re-apply Metamod hook ─────────────────────────────────────────────────────
# HLDS 10211 only successfully loads from dlls/cs.so (the vanilla path).
# Strategy: install Metamod AS dlls/cs.so; preserve real CS DLL as dlls/cs_real.so.
# liblist.gam stays with its original "dlls/cs.so" — no sed needed.
#
# Guard: use file size to detect if cs_real.so is the real CS DLL or accidentally
# got overwritten with Metamod. The real CS DLL is ~2-4 MB; Metamod is ~200 KB.
_METAMOD_SIZE=$(stat -c%s "$METAMOD_SO")
_CS_REAL="$CSTRIKE/dlls/cs_real.so"
_CS_SO="$CSTRIKE/dlls/cs.so"
_cs_real_size=$(stat -c%s "$_CS_REAL" 2>/dev/null || echo 0)

if [ "$_cs_real_size" -le "$_METAMOD_SIZE" ]; then
    # cs_real.so is missing or is metamod — need the real CS DLL
    _cs_so_size=$(stat -c%s "$_CS_SO" 2>/dev/null || echo 0)
    if [ "$_cs_so_size" -gt "$_METAMOD_SIZE" ]; then
        # cs.so is still the original CS DLL (fresh SteamCMD install)
        echo ">>> Backing up real CS DLL as cs_real.so..."
        cp -f "$_CS_SO" "$_CS_REAL"
    else
        # Both files are metamod — run SteamCMD validate to restore the real DLL
        echo ">>> cs_real.so lost — forcing SteamCMD validate to restore real CS DLL..."
        /home/steam/steamcmd/steamcmd.sh -tcp \
            +force_install_dir "$HLDS_DIR" \
            +login anonymous \
            +app_set_config 90 mod cstrike \
            +app_update 90 validate \
            +quit
        cp -f "$_CS_SO" "$_CS_REAL"
    fi
fi
# Always install Metamod-P as cs.so (HLDS 10211 only loads the game DLL via this path)
cp -f "$METAMOD_SO" "$_CS_SO"

# ─── Ensure both AMX and PODBot (if present) are always in plugins.ini ────────
{
    echo "linux addons/amxmodx/dlls/amxmodx_mm_i386.so"
    [ -f "$CSTRIKE/addons/podbot/podbot_mm.so" ] && echo "linux addons/podbot/podbot_mm.so"
} > "$METAMOD_DIR/plugins.ini"

# ─── Tell Metamod-P where the real game DLL is ────────────────────────────────
# Use absolute path so Metamod-P never mis-resolves it
echo "$CSTRIKE/dlls/cs_real.so" > "$METAMOD_DIR/metamod.ini"
# mp.so symlink covers Metamod's old "dlls/mp.so" fallback for Windows-named DLL
ln -sf cs_real.so "$CSTRIKE/dlls/mp.so" 2>/dev/null || true

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

# ─── Pre-launch diagnostics ───────────────────────────────────────────────────
echo ">>> PRE-LAUNCH CHECK:"
echo "    gamedll_linux line in liblist.gam:"
grep 'gamedll_linux' "$CSTRIKE/liblist.gam" 2>/dev/null | cat -A || echo "    MISSING"
echo "    cs.so (should be metamod): $(ls -la $CSTRIKE/dlls/cs.so 2>/dev/null || echo MISSING)"
echo "    cs_real.so (CS game DLL): $(ls -la $CSTRIKE/dlls/cs_real.so 2>/dev/null || echo MISSING)"
echo "    metamod.ini: $(cat $METAMOD_DIR/metamod.ini 2>/dev/null || echo MISSING)"
echo "    hlds_linux: $(ls -la $HLDS_DIR/hlds_linux 2>/dev/null | head -1)"

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
