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
*Rede privada via ZeroTier · Painel web de administração · AMX Mod X incluso*

![Docker](https://img.shields.io/badge/Docker-2496ED?style=flat-square&logo=docker&logoColor=white)
![PHP](https://img.shields.io/badge/PHP_8.2-777BB4?style=flat-square&logo=php&logoColor=white)
![ZeroTier](https://img.shields.io/badge/ZeroTier-FFB71B?style=flat-square&logo=zerotier&logoColor=black)
![CS 1.6](https://img.shields.io/badge/Counter--Strike_1.6-F4A900?style=flat-square&logo=steam&logoColor=white)

</div>

---

## ✨ Funcionalidades

| | |
|---|---|
| 🎮 **Servidor HLDS completo** | Counter-Strike 1.6 via SteamCMD, atualização automática |
| 🌐 **Rede privada ZeroTier** | Jogue com amigos pela internet sem abrir portas no roteador |
| 🤖 **Autorização automática** | Novos jogadores são autorizados no ZeroTier sem intervenção |
| 🖥️ **Painel web** | Console RCON, lista de jogadores, status em tempo real |
| 🗺️ **Gestão de mapas** | Upload de mapas, edição da rotação, troca imediata |
| 🛡️ **AMX Mod X** | Instalação automática na primeira execução |
| 🔄 **Auto-restart** | Servidor reinicia automaticamente em caso de crash |

---

## 🏗️ Arquitetura

```
┌─────────────────────────────────────────────────────────┐
│                    Docker Compose                        │
│                                                          │
│  ┌────────────┐   ┌────────────┐   ┌─────────────────┐  │
│  │  zerotier  │   │    cs16    │   │      panel      │  │
│  │            │   │            │   │                  │  │
│  │ ZeroTier   │   │ HLDS +     │   │ PHP 8.2 Apache  │  │
│  │ network    │   │ AMX Mod X  │   │ :8080           │  │
│  │ mode: host │   │ UDP :27015 │   │                  │  │
│  └─────┬──────┘   └─────┬──────┘   └────────┬────────┘  │
│        │                │                    │           │
│        └────────────────┴──────────┐         │           │
│                                    ▼         ▼           │
│                            ┌──────────────────────────┐  │
│  ┌────────────┐            │      cs16_data (volume)  │  │
│  │  zt-auth   │            │   /home/steam/hlds/       │  │
│  │            │            │   cstrike/maps/*.bsp      │  │
│  │ Alpine     │            │   cstrike/mapcycle.txt    │  │
│  │ curl + jq  │            │   cstrike/server.cfg      │  │
│  │ API poll   │            └──────────────────────────┘  │
│  └────────────┘                                          │
└─────────────────────────────────────────────────────────┘
         │
         ▼ ZeroTier Central API
    auto-autoriza membros
```

---

## 📋 Pré-requisitos

- [Docker](https://docs.docker.com/get-docker/) **≥ 24**
- [Docker Compose](https://docs.docker.com/compose/install/) **v2** (`docker compose`)
- Conta gratuita no [ZeroTier](https://my.zerotier.com) *(para rede privada)*
- ~**2 GB** de espaço em disco *(HLDS + mapas)*

> **Sistema operacional:** Linux recomendado. No Windows, use WSL2.

---

## 🚀 Instalação — Passo a Passo

### 1️⃣ Clone o repositório

```bash
git clone <url-do-repo> dkCSDedicado
cd dkCSDedicado
```

---

### 2️⃣ Configure o ambiente

Copie o arquivo de exemplo e edite com suas configurações:

```bash
cp .env.example .env
nano .env   # ou seu editor favorito
```

```env
# ── Servidor CS 1.6 ──────────────────────────────
SERVER_NAME=Meu Servidor CS 1.6   # nome exibido no browser
MAP=de_dust2                       # mapa inicial
MAXPLAYERS=16                      # máximo de jogadores
RCON_PASSWORD=senha_forte_aqui     # admin remoto
SV_PASSWORD=                       # senha de entrada (vazio = público)

# ── Painel Web ───────────────────────────────────
PANEL_PASSWORD=outra_senha_forte   # login do painel
ADMIN_PASSWORD=senha_amx           # admin in-game (AMX Mod X)

# ── ZeroTier ─────────────────────────────────────
ZEROTIER_NETWORK_ID=               # ID da sua rede ZeroTier
ZEROTIER_API_TOKEN=                # token da API ZeroTier
POLL_INTERVAL=15                   # segundos entre verificações
```

> ⚠️ **Nunca commite o arquivo `.env`** — ele já está no `.gitignore`.

---

### 3️⃣ Configure o ZeroTier (Self-Hosted)

O servidor cria e gerencia sua **própria rede ZeroTier** — sem conta, sem token de API.

> 🎉 **Não é necessária conta no ZeroTier Central.** O controller roda localmente no próprio container.

#### 3a. Suba os containers
```bash
docker compose up -d --build
```

#### 3b. Descubra o Network ID gerado automaticamente
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
```

> O Network ID é gerado **uma única vez** e persiste no volume `zerotier_data`.  
> Se você apagar o volume, um novo ID será gerado.

---

### 4️⃣ Suba os containers

```bash
docker compose up -d --build
```

Na **primeira execução**, o processo leva alguns minutos:

```
zerotier  ✅  inicia o daemon ZeroTier
zt-auth   ✅  cria a rede privada e começa a autorizar novos membros
cs16      ⏳  baixa HLDS via SteamCMD (~1 GB)
cs16      ⏳  instala AMX Mod X + Metamod
cs16      ✅  inicia o servidor
panel     ✅  painel disponível em http://localhost:8080
```

Acompanhe o progresso:

```bash
docker compose logs -f cs16
```

---

### 5️⃣ Descubra o IP ZeroTier do servidor

Após o `zt-auth` criar a rede (alguns segundos), rode:

```bash
./scripts/zt-info.sh
```

Saída esperada:

```
=== ZeroTier Status ===
200 info a1b2c3d4e5 1.14.0 ONLINE

=== Redes conectadas ===
200 listnetworks ab1234567890abcd ... OK

=== IP ZeroTier (compartilhe este IP com os amigos) ===

  ✅  192.168.195.1

  Os amigos conectam com:  connect 192.168.195.1:27015
```

---

### 6️⃣ Seus amigos entram na rede

Cada amigo precisa:

1. Baixar e instalar o [ZeroTier](https://www.zerotier.com/download/)
2. Entrar na rede com o **Network ID**:
   ```
   # Windows/Mac: ZeroTier System Tray → Join Network
   # Linux:
   sudo zerotier-cli join <NETWORK_ID>
   ```
3. Em até **15 segundos**, o `zt-auth` autoriza automaticamente ✅
4. Abrir o CS 1.6 e conectar:
   ```
   console: connect 192.168.195.1:27015
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
| `ADMIN_PASSWORD` | *(vazio)* | Senha para virar admin via AMX Mod X |
| `SERVER_REGION` | `2` | Região: `2`=América do Sul, `3`=Europa, `255`=Mundo |
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
| **Status** | Mostra se o servidor está online, mapa atual e jogadores |
| **Jogadores** | Lista em tempo real com score e tempo de jogo |
| **Trocar Mapa** | Campo de texto + botão para `changelevel` imediato |
| **Ações Rápidas** | Restart de round, cheats, frendly fire, timelimit |
| **Console RCON** | Terminal completo com histórico (↑↓) de comandos |

### Aba Mapas

| Seção | O que faz |
|---|---|
| **Mapas Instalados** | Lista todos os `.bsp` com busca em tempo real |
| **▶ Jogar agora** | Troca para o mapa imediatamente via RCON |
| **➕ Rotação** | Adiciona o mapa à `mapcycle.txt` |
| **Editor de Rotação** | Edita e salva a `mapcycle.txt` diretamente |
| **Upload de Mapa** | Drag & drop de arquivos `.bsp` (até 64 MB) |

---

## 🛡️ AMX Mod X — Admin In-Game

O AMX Mod X é instalado automaticamente na primeira execução.

### Virar Admin

No chat do jogo (tecla `y`):

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
# Iniciar tudo
docker compose up -d --build

# Ver logs do servidor em tempo real
docker compose logs -f cs16

# Ver autorizações ZeroTier
docker compose logs -f zt-auth

# Reiniciar apenas o servidor CS
docker compose restart cs16

# Parar tudo
docker compose down

# Parar e remover volumes (⚠️ apaga mapas e configs!)
docker compose down -v

# Rebuildar sem cache (após mudanças no start.sh ou Dockerfile)
docker compose build --no-cache

# Executar comando RCON diretamente
docker exec cs16-server /home/steam/hlds/hlds_linux \
  +rcon_password SUA_SENHA +rcon status
```

---

## 📁 Estrutura do Projeto

```
dkCSDedicado/
├── 📄 Dockerfile              # Imagem do servidor CS 1.6 (Ubuntu 22.04 + SteamCMD)
├── 📄 docker-compose.yml      # Orquestração dos 4 serviços
├── 📄 start.sh                # Entrypoint: SteamCMD → AMX Mod X → server.cfg → HLDS
├── 📄 .env                    # ⚠️ Suas configurações (não commitar!)
├── 📄 .env.example            # Template de configuração
│
├── 📁 panel/                  # Painel web de administração
│   ├── 📄 Dockerfile          # php:8.2-apache com extensão sockets
│   └── 📁 www/
│       └── 📄 index.php       # Painel completo (auth, RCON, mapas, upload)
│
├── 📁 zt-auth/                # Serviço de autorização automática ZeroTier
│   ├── 📄 Dockerfile          # Alpine + curl + jq
│   └── 📄 watch.sh            # Loop de polling da API ZeroTier
│
└── 📁 scripts/
    └── 📄 zt-info.sh          # Utilitário: mostra IP ZeroTier do servidor
```

---

## 🔧 Troubleshooting

<details>
<summary><strong>❌ Servidor não aparece para os amigos</strong></summary>

```bash
# Verifique se o ZeroTier está conectado
./scripts/zt-info.sh

# Verifique se o servidor está rodando
docker compose logs cs16 | tail -20

# Confirme que sv_lan 0 está ativo
docker compose logs cs16 | grep "sv_lan"
```
</details>

<details>
<summary><strong>❌ Amigo não consegue entrar na rede ZeroTier</strong></summary>

```bash
# Veja se o zt-auth está autorizando automaticamente
docker compose logs zt-auth

# Descubra o Network ID correto
./scripts/zt-info.sh

# O amigo precisa rodar (substitua pelo Network ID real):
# zerotier-cli join a1b2c3d4e5000001

# Para autorizar manualmente via API local:
TOKEN=$(docker exec zerotier cat /var/lib/zerotier-one/authtoken.secret)
NODE_ID=$(docker exec zerotier cat /var/lib/zerotier-one/identity.public | cut -d: -f1)
curl -X POST -H "X-ZT1-AUTH: $TOKEN" \
  -d '{"authorized":true}' \
  http://localhost:9993/controller/network/${NODE_ID}000001/member/<NODE_DO_AMIGO>
```
</details>

<details>
<summary><strong>❌ Painel não abre ou dá erro de conexão</strong></summary>

```bash
# Verifique se o container está rodando
docker compose ps

# Veja os logs do painel
docker compose logs panel

# Teste localmente
curl -I http://localhost:8080
```
</details>

<details>
<summary><strong>❌ Upload de mapa falha no painel</strong></summary>

```bash
# Verifique permissões do diretório de mapas
docker exec cs16-server ls -la /home/steam/hlds/cstrike/maps | head -5

# Corrija manualmente se necessário
docker exec cs16-server chmod 777 /home/steam/hlds/cstrike/maps
```
</details>

<details>
<summary><strong>❌ AMX Mod X não foi instalado</strong></summary>

```bash
# Verifique os logs da primeira execução
docker compose logs cs16 | grep -i "amx\|metamod"

# Forçar reinstalação: remova a pasta addons do volume e reinicie
docker exec cs16-server rm -rf /home/steam/hlds/cstrike/addons
docker compose restart cs16
```
</details>

<details>
<summary><strong>⚠️ Primeira execução demora muito</strong></summary>

Normal! O SteamCMD baixa ~1 GB de arquivos do HLDS na primeira vez.  
Nas próximas inicializações é muito mais rápido (só verifica atualizações).

```bash
# Acompanhe o progresso
docker compose logs -f cs16
```
</details>

---

## 🌐 Regiões ZeroTier (`SERVER_REGION`)

| Código | Região |
|---|---|
| `0` | Leste dos EUA |
| `1` | Oeste dos EUA |
| `2` | América do Sul *(padrão)* |
| `3` | Europa |
| `4` | Ásia |
| `5` | Austrália |
| `255` | Mundial |

---

<div align="center">

Feito com ☕ e muita nostalgia  
**Bom jogo! 🎮**

</div>
