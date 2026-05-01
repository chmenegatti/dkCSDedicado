# Copilot Instructions

## Project Overview

Dockerized Counter-Strike 1.6 dedicated server (HLDS) with a PHP-based web admin panel, managed via Docker Compose.

## Key Commands

```bash
# First-time setup
cp .env.example .env      # then edit .env with your passwords and settings

# Build and start everything
docker compose up -d --build

# Logs
docker compose logs -f cs16    # game server logs
docker compose logs -f panel   # web panel logs

# Stop
docker compose down

# Force rebuild (e.g. after changing start.sh or panel/)
docker compose build --no-cache
```

Web panel is available at **http://localhost:8080** after startup.

## Architecture

```
cs/
├── Dockerfile           # Ubuntu 22.04 + 32-bit libs + SteamCMD; no game files baked in
├── start.sh             # Entrypoint: runs steamcmd update → generates server.cfg → exec hlds_linux
├── docker-compose.yml   # Defines cs16 + panel services; shares .env between both
├── .env                 # Runtime config (gitignored); copy from .env.example
├── panel/
│   ├── Dockerfile       # php:8.2-apache with sockets extension
│   └── www/index.php    # Self-contained admin panel (auth + A2S query + RCON console)
└── .github/
    └── copilot-instructions.md
```

## Key Conventions

- **Game files live in the `cs16_data` volume, not the image.** On first start, `start.sh` downloads HLDS (~1 GB via SteamCMD) into the volume. Deleting the volume forces a full re-download.
- **SteamCMD runs on every container start** to keep HLDS updated automatically.
- **`hlds_linux` is used directly** (not `hlds_run`) so Docker owns the process lifecycle and `restart: unless-stopped` works correctly. `exec` in `start.sh` makes the server PID 1 for clean signal handling.
- **`server.cfg` is regenerated on every start** from `.env` variables — do not hand-edit it inside the container.
- **GoldSrc RCON uses UDP challenge/response:** the challenge request and the command must be sent on the same UDP socket. This is implemented in `panel/www/index.php` (`Rcon::execute`).
- **Kick players by `#userid`, not by name.** Run `status` in the RCON console to get user IDs, then `kick #<id>`. The panel's "kick" button prefills `status` for this reason.
- **Panel is bound to `127.0.0.1:8080`** by default — only accessible from the host machine. To expose it publicly, remove `127.0.0.1:` from the port mapping in `docker-compose.yml` and add proper auth/reverse proxy.

## Configuration (`.env`)

| Variable | Default | Used by |
|---|---|---|
| `SERVER_NAME` | `My CS 1.6 Server` | cs16 — hostname in server browser |
| `MAP` | `de_dust2` | cs16 — starting map |
| `MAXPLAYERS` | `16` | cs16 — player slots |
| `RCON_PASSWORD` | `changeme` | cs16 + panel |
| `SV_PASSWORD` | *(empty)* | cs16 — join password (leave empty for public) |
| `PANEL_PASSWORD` | `admin` | panel — web UI login |
| `ADMIN_PASSWORD` | *(empty)* | cs16 — AMX Mod X in-game admin password |
| `SERVER_REGION` | `2` | cs16 — 2=South America, 3=Europe, 255=World |

## Internet Play Checklist

For the server to be reachable from the internet:

1. **Port forward UDP 27015** on your router to the machine running Docker
2. **Firewall**: ensure UDP 27015 is open (`ufw allow 27015/udp` on Ubuntu)
3. `sv_lan 0` is already set in `server.cfg` and passed as a launch flag
4. Share your **public IP** with friends — they connect via `connect <your-ip>:27015`
5. If your ISP gives a dynamic IP, use a DDNS service (DuckDNS, No-IP, etc.)

> Running on a VPS? No router port forwarding needed — just open UDP 27015 in the cloud firewall/security group.

## Persistent Data (inside `cs16_data` volume)

- `cstrike/mapcycle.txt` — map rotation
- `cstrike/banned.cfg`, `cstrike/listip.cfg` — ban lists
- `cstrike/addons/` — AMX Mod X or other plugins installed manually

## Protocol Details (for debugging)

- Game server port: **UDP 27015**
- SteamCMD app ID for HLDS/CS 1.6: **90** with `+app_set_config 90 mod cstrike`
- A2S_INFO query header: `\xFF\xFF\xFF\xFF\x54Source Engine Query\0` — response type byte `\x49`
- A2S_PLAYER: challenge with `\xFF\xFF\xFF\xFF\x55\xFF\xFF\xFF\xFF`, challenge response type `\x41`, player data type `\x44`
