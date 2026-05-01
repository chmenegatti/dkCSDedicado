# Copilot Instructions

## Project Overview

Docker Compose stack for a Counter-Strike 1.6 dedicated server with:

- a self-hosted ZeroTier controller (`zerotier`)
- an auto-authorization sidecar for ZeroTier members (`zt-auth`)
- the HLDS game server (`cs16`)
- a PHP/Apache admin panel (`panel`)

The repository is mostly operational glue. The important behavior lives in `docker-compose.yml`, `start.sh`, `zt-auth/watch.sh`, and `panel/www/index.php`.

## Build, Run, and Validation Commands

```bash
# first-time setup
cp .env.example .env

# build and start the full stack
docker compose up -d --build

# stop everything
docker compose down

# inspect rendered compose config
docker compose config --quiet

# follow service logs
docker compose logs -f cs16
docker compose logs -f panel
docker compose logs -f zt-auth

# show the generated ZeroTier network ID and server IP
./scripts/zt-info.sh

# rebuild after changing container sources
docker compose build --no-cache
docker compose build --no-cache cs16 panel zt-auth

# targeted syntax checks (there is no repo-defined automated test or lint suite)
bash -n start.sh
bash -n scripts/zt-info.sh
sh -n zt-auth/watch.sh
docker compose run --rm --no-deps panel php -l /var/www/html/index.php
```

There is no single-test command because the repository does not define an automated test runner.

## High-Level Architecture

### Service topology

- `docker-compose.yml` wires four services together.
- `zerotier` runs in `network_mode: host` so it can create the virtual interface and expose the local controller on `localhost:9993`.
- `zt-auth` also runs in host network mode, reads the controller token from the shared `zerotier_data` volume, creates the private network if needed, joins the server to it, and auto-authorizes new members by polling the local controller API.
- `cs16` exposes UDP `27015`, mounts `cs16_data` at `/home/steam/hlds`, and gets its runtime settings from `.env`.
- `panel` mounts the same `cs16_data` volume at `/srv/hlds`, talks to the game server over UDP using the `cs16` service name, and is bound to `127.0.0.1:8080` by default.

### Game server lifecycle

- The root `Dockerfile` only installs Ubuntu dependencies and SteamCMD. It does **not** bake HLDS files into the image.
- `start.sh` is the real entrypoint. On every container start it:
  1. updates HLDS via SteamCMD into the mounted volume
  2. installs AMX Mod X and PODBot into the volume on first run
  3. reapplies admin/password-related config from environment variables
  4. regenerates `cstrike/server.cfg`
  5. `exec`s `./hlds_linux` so the game server becomes PID 1

### Admin panel structure

- `panel/www/index.php` is intentionally monolithic: PHP auth, JSON API endpoints, HTML, CSS, and browser-side JavaScript all live in the same file.
- The API is a `switch` on `?api=...`; the frontend calls those endpoints with `fetch`.
- The panel uses two integration paths:
  - A2S and RCON UDP packets for live server state and in-game commands
  - direct file access on the shared `cs16_data` volume for maps, `mapcycle.txt`, and PODBot names

## Key Conventions

- **ZeroTier is self-hosted in this repo.** Do not assume ZeroTier Central or external API tokens; `zt-auth/watch.sh` derives the network ID as `${NODE_ID}000001` and manages the local controller directly.
- **Persistent game state lives in `cs16_data`, not in the image.** Uploaded maps, `mapcycle.txt`, AMX Mod X files, PODBot files, and generated HLDS content are expected to survive container rebuilds.
- **`server.cfg` is generated on every start.** Change defaults in `start.sh` or `.env`; do not hand-edit `cstrike/server.cfg` inside the running volume and expect it to persist.
- **Keep the panel’s single-file structure unless there is a strong reason to split it.** New panel behavior usually means adding an `?api=` case in PHP plus a matching frontend function in the same file.
- **GoldSrc RCON must reuse the same UDP socket for the challenge request and the command.** Preserve the `Rcon` class flow when changing RCON behavior.
- **Shared-volume writes are intentionally atomic and permissive.** `mapcycle.txt` and `botnames.txt` are written through a temp file + `rename()`, then `chmod`ed so both the game container and Apache container can update them.
- **Map and upload validation is conservative.** Map names are restricted to `[a-z0-9_-]`, uploads are `.bsp` only, and the panel prefers existing server files as the whitelist source.
- **Kick players by `#userid`, not by display name.** The panel nudges admins to run `status` first for that reason.
- **User-facing copy is in Brazilian Portuguese.** Keep new UI strings, API error messages, and shell output aligned with that language unless the file already uses English for protocol-level terms.
