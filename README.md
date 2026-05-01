<div align="center">

```
██████╗ ██╗  ██╗ ██████╗███████╗    ██████╗ ███████╗██████╗
██╔══██╗██║ ██╔╝██╔════╝██╔════╝    ██╔══██╗██╔════╝██╔══██╗
██║  ██║█████╔╝ ██║     ███████╗    ██║  ██║█████╗  ██║  ██║
██║  ██║██╔═██╗ ██║     ╚════██║    ██║  ██║██╔══╝  ██║  ██║
██████╔╝██║  ██╗╚██████╗███████║    ██████╔╝███████╗██████╔╝
╚═════╝ ╚═╝  ╚═╝ ╚═════╝╚══════╝    ╚═════╝ ╚══════╝╚═════╝
```

**Servidor dedicado de Counter-Strike 1.6 containerizado com Docker**  
*Rede privada via ZeroTier · Painel web de administração · AMX Mod X + PODBot inclusos*

![Docker](https://img.shields.io/badge/Docker-2496ED?style=flat-square&logo=docker&logoColor=white)
![PHP](https://img.shields.io/badge/PHP_8.2-777BB4?style=flat-square&logo=php&logoColor=white)
![ZeroTier](https://img.shields.io/badge/ZeroTier-FFB71B?style=flat-square&logo=zerotier&logoColor=black)
![CS 1.6](https://img.shields.io/badge/Counter--Strike_1.6-F4A900?style=flat-square&logo=steam&logoColor=white)

</div>

---

## ✨ Funcionalidades

| | |
|---|---|
| 🎮 **Servidor HLDS completo** | Counter-Strike 1.6 via SteamCMD, atualização automática a cada início |
| 🌐 **Rede privada ZeroTier** | Jogue com amigos pela internet sem abrir portas no roteador |
| 🤖 **Autorização automática** | Novos membros ZeroTier são aprovados automaticamente sem conta externa |
| 🖥️ **Painel web** | Console RCON, jogadores, mapas, bots e configurações em tempo real |
| 🗺️ **Gestão de mapas** | Upload de `.bsp`, edição da rotação e troca imediata de mapa |
| 🛡️ **AMX Mod X** | Plugin framework instalado automaticamente, admin in-game via chat |
| 🤖 **PODBot mm** | Bots de IA configuráveis pelo painel (quota, skill, nomes) |
| ⚙️ **Configurações ao vivo** | Ajuste tempo de round, dinheiro, C4 timer e muito mais sem reiniciar |
| 🔄 **Auto-restart** | Servidor reinicia automaticamente em caso de crash |

---

## 🏗️ Arquitetura

```
┌─────────────────────────────────────────────────────────────┐
│                       Docker Compose                         │
│                                                              │
│  ┌────────────┐   ┌──────────────────┐   ┌───────────────┐  │
│  │  zerotier  │   │      cs16        │   │     panel     │  │
│  │            │   │                  │   │               │  │
│  │ ZeroTier   │   │ HLDS (build 2024)│   │ PHP 8.2 Apache│  │
│  │ daemon +   │   │ Metamod-P 1.21   │   │ :8080         │  │
│  │ controller │   │ AMX Mod X 1.10   │   │               │  │
│  │ mode: host │   │ PODBot mm V3B24  │   │               │  │
│  │            │   │ UDP :27015       │   │               │  │
│  └─────┬──────┘   └────────┬─────────┘   └───────┬───────┘  │
│        │  zerotier_data    │                      │          │
│  ┌─────┴──────┐            └──────────────────────┘          │
│  │  zt-auth   │                     │ cs16_data (volume)     │
│  │            │            ┌────────┴──────────────────────┐ │
│  │ Alpine     │            │  /home/steam/hlds/cstrike/    │ │
│  │ curl + jq  │            │   dlls/cs.so  ← Metamod-P     │ │
│  │ auto-auth  │            │   dlls/cs_real.so ← CS DLL    │ │
│  │ loop 15s   │            │   addons/amxmodx/             │ │
│  └────────────┘            │   addons/podbot/              │ │
│                            │   maps/*.bsp                  │ │
│                            │   mapcycle.txt / server.cfg   │ │
│                            └───────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

> **Como o Metamod-P é carregado:** o HLDS 2024 só resolve game DLLs pelo
> caminho `dlls/cs.so`. O `start.sh` instala o Metamod-P nesse caminho e
> preserva o CS DLL original em `dlls/cs_real.so`.

---

## 📋 Pré-requisitos

- [Docker](https://docs.docker.com/get-docker/) **≥ 24**
- [Docker Compose](https://docs.docker.com/compose/install/) **v2** (`docker compose`)
- **~2 GB** de espaço em disco *(HLDS ~1 GB + mapas)*

> **Sistema operacional:** Linux recomendado. No Windows, use WSL2.  
> **Conta ZeroTier:** não é necessária — o controller roda localmente no próprio container.

---

## 🚀 Instalação — Passo a Passo

### 1️⃣ Clone o repositório

```bash
git clone https://github.com/chmenegatti/dkCSDedicado.git
cd dkCSDedicado
```

### 2️⃣ Configure o ambiente

```bash
cp .env.example .env
nano .env   # ou seu editor favorito
```

```env
# ── Servidor CS 1.6 ──────────────────────────────
SERVER_NAME=Meu Servidor CS 1.6   # nome no browser de servidores
MAP=de_dust2                       # mapa inicial
MAXPLAYERS=16                      # máximo de jogadores
RCON_PASSWORD=senha_forte_aqui     # ⚠️  troque isso!
SV_PASSWORD=                       # senha de entrada (vazio = público)

# ── Painel Web ───────────────────────────────────
PANEL_PASSWORD=outra_senha_forte   # ⚠️  troque isso!
ADMIN_PASSWORD=senha_amx           # admin in-game via AMX Mod X

# ── ZeroTier ─────────────────────────────────────
POLL_INTERVAL=15                   # segundos entre verificações de novos membros

# ── Região do servidor ───────────────────────────
SERVER_REGION=2                    # 2=América do Sul (ver tabela abaixo)
```

> ⚠️ **Nunca commite o `.env`** — ele já está no `.gitignore`.

### 3️⃣ Suba os containers

```bash
docker compose up -d --build
```

Na **primeira execução**, aguarde alguns minutos:

```
zerotier  ✅  inicia o daemon + controller ZeroTier local
zt-auth   ✅  cria a rede privada e começa a autorizar novos membros
cs16      ⏳  baixa HLDS via SteamCMD (~1 GB) ...
cs16      ⏳  instala Metamod-P + AMX Mod X + PODBot ...
cs16      ✅  inicia o servidor CS 1.6
panel     ✅  painel disponível em http://localhost:8080
```

Acompanhe o progresso:

```bash
docker compose logs -f cs16
```

### 4️⃣ Descubra o Network ID e o IP do servidor

```bash
./scripts/zt-info.sh
```

Saída esperada:

```
=== ZeroTier Self-Hosted Controller ===

  Node ID    : a1b2c3d4e5
  Network ID : a1b2c3d4e5000001

=== IP ZeroTier do Servidor ===

  ✅  10.144.0.1

  Compartilhe com seus amigos:
    1) Entrar na rede : zerotier-cli join a1b2c3d4e5000001
    2) Conectar no CS : connect 10.144.0.1:27015

=== Membros na Rede ===
  a1b2c3d4e5
```

> O Network ID é gerado **uma única vez** e persiste no volume `zerotier_data`.  
> Se você apagar o volume, um novo ID será gerado.

### 5️⃣ Seus amigos entram na rede

Cada amigo precisa:

1. Baixar e instalar o [ZeroTier](https://www.zerotier.com/download/)
2. Entrar na rede com o **Network ID**:
   ```
   # Windows / Mac: ZeroTier System Tray → Join Network → cole o Network ID
   # Linux:
   sudo zerotier-cli join a1b2c3d4e5000001
   ```
3. Em até **15 segundos**, o `zt-auth` autoriza automaticamente ✅
4. Abrir o CS 1.6 e conectar:
   ```
   connect 10.144.0.1:27015
   ```

---

## ⚙️ Referência — Variáveis de Ambiente

| Variável | Padrão | Descrição |
|---|---|---|
| `SERVER_NAME` | `My CS 1.6 Server` | Nome exibido no browser de servidores |
| `MAP` | `de_dust2` | Mapa ao iniciar |
| `MAXPLAYERS` | `16` | Máximo de jogadores simultâneos |
| `RCON_PASSWORD` | `changeme` | Senha para comandos RCON remotos |
| `SV_PASSWORD` | *(vazio)* | Senha para entrar no servidor (vazio = público) |
| `PANEL_PASSWORD` | `admin` | Senha de login do painel web |
| `ADMIN_PASSWORD` | *(vazio)* | Senha para virar admin via AMX Mod X in-game |
| `SERVER_REGION` | `2` | Região Steam: `0`=EUA Leste · `1`=EUA Oeste · `2`=América do Sul · `3`=Europa · `4`=Ásia · `5`=Austrália · `255`=Mundial |
| `POLL_INTERVAL` | `15` | Segundos entre verificações de novos membros ZeroTier |

---

## 🖥️ Painel de Administração

Acesse em: **[http://localhost:8080](http://localhost:8080)**

> Por padrão, o painel só é acessível da própria máquina (`127.0.0.1`).  
> Para expor na rede local, edite `docker-compose.yml`:  
> `"127.0.0.1:8080:80"` → `"8080:80"`

### Aba Servidor

| Seção | O que faz |
|---|---|
| **Status** | Mapa atual, jogadores conectados e estado do servidor |
| **Jogadores** | Lista em tempo real com score, ping e tempo de jogo |
| **Trocar Mapa** | Campo de texto com autocomplete + botão `changelevel` imediato |
| **Ações Rápidas** | Restart de round, cheats, friendly fire, timelimit |
| **Console RCON** | Terminal completo com histórico (↑↓) de comandos |

### Aba Mapas

| Seção | O que faz |
|---|---|
| **Mapas Instalados** | Lista todos os `.bsp` com busca em tempo real |
| **▶ Jogar agora** | Troca para o mapa imediatamente via RCON |
| **➕ Rotação** | Adiciona o mapa à `mapcycle.txt` |
| **Editor de Rotação** | Edita e salva a `mapcycle.txt` diretamente no volume |
| **Upload de Mapa** | Drag & drop de arquivos `.bsp` (até 64 MB) |

### Aba 🤖 Bots

| Seção | O que faz |
|---|---|
| **Quota de Bots** | Define quantos bots o PODBot mantém na partida (0–10) |
| **Nível de Habilidade** | Slider de skill mínimo e máximo (0–100) |
| **▶ Aplicar** | Envia os comandos PODBot via RCON e reinicia os bots |
| **⏹ Desativar Bots** | Remove todos os bots imediatamente |
| **Nomes dos Bots** | Editor da lista de nomes (`botnames.txt`) — mínimo 9 nomes |

### Aba ⚙️ Configurações

Ajuste as regras da partida em tempo real sem reiniciar o servidor.  
Clique em **↺ Carregar do Servidor** para ver os valores atuais, ajuste e clique em **✔ Aplicar**.

| Seção | Configurações disponíveis |
|---|---|
| ⏱ **Partida** | `mp_timelimit`, `mp_maxrounds`, `mp_winlimit` |
| 🔄 **Rounds** | `mp_roundtime`, `mp_freezetime`, `mp_buytime`, `mp_c4timer` |
| 👥 **Jogadores** | `mp_startmoney`, `mp_limitteams`, fogo amigo, balance automático, auto kick |

---

## 🛡️ AMX Mod X — Admin In-Game

O AMX Mod X é instalado automaticamente na primeira execução.

### Virar Admin

No chat do jogo (tecla `Y`):

```
/pass <ADMIN_PASSWORD>
```

### Comandos Principais

| Comando | Descrição |
|---|---|
| `amx_kick <nome>` | Kickar jogador |
| `amx_ban <nome> <minutos>` | Banir temporariamente |
| `amx_map <mapa>` | Trocar mapa |
| `amx_slay <nome>` | Matar jogador |
| `amx_slap <nome>` | Dar slap no jogador |
| `amx_rcon <comando>` | Executar comando RCON |
| `amx_who` | Listar admins conectados |
| `say /vote` | Iniciar votação de mapa |

> 💡 Todos esses comandos também podem ser executados pelo **Console RCON** no painel web.

---

## 🐳 Comandos Úteis

```bash
# Iniciar tudo (com rebuild)
docker compose up -d --build

# Iniciar sem rebuild (mais rápido)
docker compose up -d

# Ver logs em tempo real
docker compose logs -f cs16      # servidor CS
docker compose logs -f zt-auth   # autorizações ZeroTier
docker compose logs -f panel     # painel web

# Reiniciar apenas o servidor CS
docker compose restart cs16

# Parar tudo (mantém volumes)
docker compose down

# Parar e remover volumes ⚠️ apaga mapas, bans e configs do volume
docker compose down -v

# Rebuildar sem cache (após mudar start.sh ou Dockerfile)
docker compose build --no-cache

# Ver status de todos os containers
docker compose ps

# Verificar IP ZeroTier e Network ID
./scripts/zt-info.sh

# Forçar reinstalação do AMX Mod X + PODBot
docker exec cs16-server rm -rf /home/steam/hlds/cstrike/addons
docker compose restart cs16
```

---

## 📁 Estrutura do Projeto

```
dkCSDedicado/
├── 📄 Dockerfile              # Imagem do servidor (Ubuntu 22.04 + 32-bit libs + SteamCMD)
├── 📄 docker-compose.yml      # Orquestração dos 4 serviços
├── 📄 start.sh                # Entrypoint: SteamCMD → Metamod-P → AMX → PODBot → server.cfg → HLDS
├── 📄 .env                    # ⚠️ Suas configurações (não commitar!)
├── 📄 .env.example            # Template de configuração
│
├── 📁 panel/                  # Painel web de administração
│   ├── 📄 Dockerfile          # php:8.2-apache
│   └── 📁 www/
│       └── 📄 index.php       # Painel completo (auth, RCON, mapas, bots, upload)
│
├── 📁 zt-auth/                # Serviço de autorização automática ZeroTier
│   ├── 📄 Dockerfile          # Alpine + curl + jq
│   └── 📄 watch.sh            # Cria rede self-hosted + loop de auto-autorização
│
└── 📁 scripts/
    └── 📄 zt-info.sh          # Mostra Network ID, IP ZeroTier e membros conectados
```

### Volumes Docker

| Volume | Montagem | Conteúdo |
|---|---|---|
| `cs16_data` | `/home/steam/hlds` (cs16) · `/srv/hlds` (panel) | Todos os arquivos do HLDS, mapas, configs, addons |
| `zerotier_data` | `/var/lib/zerotier-one` (zerotier + zt-auth) | Identidade e chaves ZeroTier (persistem o Network ID) |

---

## 🔧 Troubleshooting

<details>
<summary><strong>❌ Servidor não aparece para os amigos</strong></summary>

```bash
# Verifique se o ZeroTier está conectado e o IP do servidor
./scripts/zt-info.sh

# Verifique se o servidor está rodando
docker compose ps
docker compose logs cs16 | tail -30

# Confirme sv_lan 0
docker compose logs cs16 | grep "sv_lan"
```

Os amigos devem conectar pelo **IP ZeroTier** (ex: `10.144.0.x`), não pelo IP local.
</details>

<details>
<summary><strong>❌ Amigo não consegue entrar na rede ZeroTier</strong></summary>

```bash
# Veja se o zt-auth está rodando e autorizando
docker compose logs zt-auth | tail -20

# Descubra o Network ID correto
./scripts/zt-info.sh

# Autorizar manualmente via API local:
TOKEN=$(docker exec zerotier cat /var/lib/zerotier-one/authtoken.secret)
NODE_ID=$(docker exec zerotier cat /var/lib/zerotier-one/identity.public | cut -d: -f1)
curl -X POST -H "X-ZT1-AUTH: $TOKEN" \
  -d '{"authorized":true}' \
  http://localhost:9993/controller/network/${NODE_ID}000001/member/<NODE_DO_AMIGO>
```

O amigo deve usar exatamente o Network ID mostrado por `./scripts/zt-info.sh`.
</details>

<details>
<summary><strong>❌ Bots não aparecem no jogo</strong></summary>

```bash
# Verifique se o PODBot foi instalado
docker exec cs16-server ls /home/steam/hlds/cstrike/addons/podbot/podbot_mm.so

# Verifique se o Metamod-P carregou os plugins
docker compose logs cs16 | grep -i "metamod\|amx\|podbot"
```

Se o PODBot não estiver instalado, force reinstalação:
```bash
docker exec cs16-server rm -rf /home/steam/hlds/cstrike/addons/podbot
docker compose restart cs16
```

No painel, aba **🤖 Bots** → defina uma quota > 0 → clique **▶ Aplicar**.
</details>

<details>
<summary><strong>❌ Painel mostra "Falha ao salvar — verifique permissões do volume"</strong></summary>

```bash
# Corrija as permissões do diretório cstrike
docker exec cs16-server chmod 777 /home/steam/hlds/cstrike
docker exec cs16-server chmod 777 /home/steam/hlds/cstrike/maps
```

Isso já é feito automaticamente pelo `start.sh` a cada reinício.
</details>

<details>
<summary><strong>❌ Painel não abre ou dá erro de conexão</strong></summary>

```bash
docker compose ps          # todos os containers devem estar "Up"
docker compose logs panel  # veja erros do Apache/PHP
curl -I http://localhost:8080
```
</details>

<details>
<summary><strong>❌ AMX Mod X ou Metamod não carrega</strong></summary>

```bash
# Verifique os logs de inicialização
docker compose logs cs16 | grep -i "amx\|metamod\|error"

# Forçar reinstalação completa dos addons
docker exec cs16-server rm -rf /home/steam/hlds/cstrike/addons
docker compose restart cs16
```
</details>

<details>
<summary><strong>⚠️ Primeira execução demora muito</strong></summary>

Normal! O SteamCMD baixa ~1 GB de arquivos do HLDS. Nas próximas inicializações é muito mais rápido (só verifica atualizações).

```bash
docker compose logs -f cs16   # acompanhe o progresso
```
</details>

---

## 📡 Jogo pela Internet (sem ZeroTier)

Para o servidor ser acessível diretamente pela internet:

1. **Port forward UDP 27015** no seu roteador para a máquina com Docker
2. **Firewall:** `sudo ufw allow 27015/udp`
3. `sv_lan 0` já está ativo no `server.cfg`
4. Compartilhe seu **IP público** com os amigos: `connect <seu-ip>:27015`

> **VPS?** Não precisa de port forwarding — basta abrir UDP 27015 no firewall/security group da nuvem.

---

## 🔬 Detalhes Técnicos

| Item | Detalhe |
|---|---|
| **HLDS** | Build `10211` (Oct 8 2024) via SteamCMD app `90` |
| **Metamod** | [metamod-p 1.21p38](https://github.com/Bots-United/metamod-p) instalado como `dlls/cs.so` |
| **AMX Mod X** | Última versão estável do [GitHub AlliedModders](https://github.com/alliedmodders/amxmodx) |
| **PODBot** | [V3B24-APG](https://github.com/APGRoboCop/podbot_mm) — bot de IA clássico do CS 1.6 |
| **ZeroTier** | Controller self-hosted — rede `10.144.0.0/24`, sem conta externa necessária |
| **Porta do jogo** | UDP `27015` |
| **Painel** | `127.0.0.1:8080` (apenas localhost por padrão) |

---

<div align="center">

Feito com ☕ e muita nostalgia  
**Bom jogo! 🎮**

</div>
