<?php
// ─── Config ───────────────────────────────────────────────────────────────────
session_start();
$CS_HOST        = getenv('CS_HOST')        ?: 'cs16';
$CS_PORT        = (int)(getenv('CS_PORT')  ?: 27015);
$RCON_PASSWORD  = getenv('RCON_PASSWORD')  ?: '';
$PANEL_PASSWORD = getenv('PANEL_PASSWORD') ?: '';

// ─── Auth ─────────────────────────────────────────────────────────────────────
$auth = !$PANEL_PASSWORD || ($_SESSION['auth'] ?? false);

if (!$auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals($PANEL_PASSWORD, $_POST['password'])) {
        $_SESSION['auth'] = true;
        $auth = true;
    } else {
        $loginError = 'Wrong password';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// ─── UDP base class ───────────────────────────────────────────────────────────
class UdpClient {
    protected $socket;

    public function __construct(string $host, int $port, int $timeout = 3) {
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $tv = ['sec' => $timeout, 'usec' => 0];
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $tv);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, $tv);
        socket_connect($this->socket, $host, $port);
    }

    protected function send(string $data): void {
        socket_send($this->socket, $data, strlen($data), 0);
    }

    protected function recv(int $size = 4096): string {
        $buf = '';
        socket_recv($this->socket, $buf, $size, 0);
        return $buf ?? '';
    }

    public function __destruct() {
        if ($this->socket) socket_close($this->socket);
    }
}

// ─── Server Query (A2S) ───────────────────────────────────────────────────────
class ServerQuery extends UdpClient {
    public function getInfo(): ?array {
        try {
            $this->send("\xFF\xFF\xFF\xFF\x54Source Engine Query\x00");
            $resp = $this->recv();
            if (strlen($resp) < 6 || $resp[4] !== "\x49") return null;
            return $this->parseInfo($resp);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseInfo(string $d): array {
        $p = 5;
        $p++; // protocol byte
        $name = $this->readStr($d, $p);
        $map  = $this->readStr($d, $p);
        $this->readStr($d, $p); // folder
        $this->readStr($d, $p); // game name
        $p += 2;                // app id
        $players    = ord($d[$p++]);
        $maxplayers = ord($d[$p++]);
        return ['online' => true, 'name' => $name, 'map' => $map,
                'players' => $players, 'maxplayers' => $maxplayers];
    }

    public function getPlayers(): array {
        try {
            // Challenge handshake
            $this->send("\xFF\xFF\xFF\xFF\x55\xFF\xFF\xFF\xFF");
            $resp = $this->recv();
            if (strlen($resp) < 9) return [];
            if ($resp[4] === "\x41") {
                $this->send("\xFF\xFF\xFF\xFF\x55" . substr($resp, 5, 4));
                $resp = $this->recv();
            }
            if (strlen($resp) < 6 || $resp[4] !== "\x44") return [];
            return $this->parsePlayers($resp);
        } catch (Throwable) {
            return [];
        }
    }

    private function parsePlayers(string $d): array {
        $p = 5;
        $count = ord($d[$p++]);
        $list  = [];
        for ($i = 0; $i < $count && $p < strlen($d); $i++) {
            $p++; // index
            $name  = $this->readStr($d, $p);
            if ($p + 8 > strlen($d)) break;
            $score = unpack('l', substr($d, $p, 4))[1]; $p += 4;
            $secs  = (int) unpack('f', substr($d, $p, 4))[1]; $p += 4;
            $list[] = [
                'name'  => $name ?: '(empty)',
                'score' => $score,
                'time'  => sprintf('%02d:%02d:%02d', $secs / 3600, ($secs % 3600) / 60, $secs % 60),
            ];
        }
        return $list;
    }

    private function readStr(string $d, int &$p): string {
        $s = '';
        while ($p < strlen($d) && $d[$p] !== "\x00") $s .= $d[$p++];
        $p++;
        return $s;
    }
}

// ─── GoldSrc RCON (UDP challenge/response) ────────────────────────────────────
class Rcon extends UdpClient {
    private string $password;

    public function __construct(string $host, int $port, string $password) {
        parent::__construct($host, $port);
        $this->password = $password;
    }

    public function execute(string $cmd): array {
        try {
            // Challenge and command sent on the SAME socket (required by GoldSrc)
            $this->send("\xFF\xFF\xFF\xFF" . "challenge rcon\n");
            $resp = $this->recv(1024);
            if (!preg_match('/challenge rcon (\d+)/', $resp, $m)) {
                return ['success' => false, 'output' => 'No challenge response from server.'];
            }

            $pkt = "\xFF\xFF\xFF\xFF" . "rcon {$m[1]} \"{$this->password}\" {$cmd}\n";
            $this->send($pkt);

            // Collect all response packets (multi-packet support)
            $output = '';
            for ($i = 0; $i < 16; $i++) {
                $buf = $this->recv(4096);
                if (strlen($buf) <= 4) break;
                $output .= substr($buf, 4);
            }
            $output = trim($output);

            if (str_contains($output, 'Bad rcon_password')) {
                return ['success' => false, 'output' => 'Wrong RCON password.'];
            }
            return ['success' => true, 'output' => $output ?: '(no output)'];
        } catch (Throwable $e) {
            return ['success' => false, 'output' => 'Error: ' . $e->getMessage()];
        }
    }
}

// ─── JSON API endpoints ───────────────────────────────────────────────────────
if ($auth && isset($_GET['api'])) {
    header('Content-Type: application/json');
    switch ($_GET['api']) {
        case 'status':
            $info = (new ServerQuery($CS_HOST, $CS_PORT))->getInfo();
            echo json_encode($info ?: ['online' => false]);
            break;
        case 'players':
            echo json_encode((new ServerQuery($CS_HOST, $CS_PORT))->getPlayers());
            break;
        case 'rcon':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
            $cmd = trim($_POST['cmd'] ?? '');
            if ($cmd === '') { echo json_encode(['success' => false, 'output' => 'Empty command.']); break; }
            echo json_encode((new Rcon($CS_HOST, $CS_PORT, $RCON_PASSWORD))->execute($cmd));
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CS 1.6 Admin Panel</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d0d0d;color:#c8c8c8;font-family:'Courier New',monospace;font-size:14px}
a{color:#5fa2dd;text-decoration:none}
header{background:#111;border-bottom:1px solid #2a2a2a;padding:12px 24px;display:flex;align-items:center;gap:16px}
header h1{color:#e8a000;font-size:17px;letter-spacing:1px;flex:1}
header .srv{color:#888;font-size:12px}
.layout{display:grid;grid-template-columns:300px 1fr;gap:16px;padding:16px}
.card{background:#111;border:1px solid #222;border-radius:4px;padding:16px;margin-bottom:16px}
.card h2{color:#888;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;border-bottom:1px solid #1e1e1e;padding-bottom:8px}
.row{display:flex;justify-content:space-between;margin-bottom:6px}
.lbl{color:#555}.val{color:#ddd}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
.on{background:#4caf50}.off{background:#f44336}
table{width:100%;border-collapse:collapse}
th{color:#555;font-size:11px;text-transform:uppercase;text-align:left;padding:5px 8px;border-bottom:1px solid #1e1e1e}
td{padding:6px 8px;border-bottom:1px solid #181818;vertical-align:middle}
tr:hover td{background:#161616}
.hint-btn{background:transparent;color:#5fa2dd;border:1px solid #1a3a5c;border-radius:3px;cursor:pointer;padding:2px 7px;font-size:11px;font-family:inherit}
.hint-btn:hover{background:#1a3a5c}
.console{background:#080808;border:1px solid #1e1e1e;border-radius:3px;height:340px;overflow-y:auto;padding:10px;font-size:13px;margin-bottom:10px}
.ln{margin-bottom:2px;line-height:1.5}
.cmd{color:#5fa2dd}.out{color:#c8c8c8;white-space:pre-wrap;word-break:break-all}.err{color:#e87070}.info{color:#555;font-style:italic}
.cmd-row{display:flex;gap:8px}
.cmd-row input{flex:1;background:#080808;border:1px solid #2a2a2a;color:#eee;padding:8px 10px;border-radius:3px;font-family:inherit;font-size:13px}
.cmd-row input:focus{outline:none;border-color:#5fa2dd}
.btn{background:#1a3a5c;color:#7ab8e8;border:1px solid #2a5a8c;border-radius:3px;cursor:pointer;padding:8px 14px;font-family:inherit;font-size:13px}
.btn:hover{background:#2a5a8c}
.btn.sm{padding:5px 10px;font-size:12px;width:100%;margin-bottom:8px}
.qgrid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px}
.qgrid button{background:#161616;color:#999;border:1px solid #252525;border-radius:3px;cursor:pointer;padding:7px 4px;font-family:inherit;font-size:11px}
.qgrid button:hover{background:#222;color:#eee}
.map-inp{background:#080808;border:1px solid #2a2a2a;color:#eee;padding:6px 8px;border-radius:3px;font-family:inherit;font-size:12px;width:100%;margin-bottom:6px}
.map-inp:focus{outline:none;border-color:#5fa2dd}
/* Login */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-box{background:#111;border:1px solid #222;border-radius:4px;padding:36px;width:320px}
.login-box h2{color:#e8a000;margin-bottom:24px;font-size:16px}
.login-box input{width:100%;background:#080808;border:1px solid #2a2a2a;color:#eee;padding:10px;border-radius:3px;font-family:inherit;font-size:13px;margin-bottom:12px}
.login-box input:focus{outline:none;border-color:#5fa2dd}
.login-box .btn{width:100%;text-align:center}
.errmsg{color:#e87070;font-size:12px;margin-bottom:10px}
@media(max-width:900px){.layout{grid-template-columns:1fr}}
</style>
</head>
<body>

<?php if (!$auth): ?>
<div class="login-wrap">
  <div class="login-box">
    <h2>🎮 CS 1.6 Admin Panel</h2>
    <?php if (isset($loginError)): ?>
      <div class="errmsg"><?= htmlspecialchars($loginError) ?></div>
    <?php endif ?>
    <form method="POST">
      <input type="password" name="password" placeholder="Panel password" autofocus>
      <button type="submit" class="btn">Login</button>
    </form>
  </div>
</div>

<?php else: ?>
<header>
  <h1>🎮 CS 1.6 Admin</h1>
  <span class="srv" id="srvName">connecting...</span>
  <a href="?logout" style="font-size:12px;color:#555">logout</a>
</header>

<div class="layout">

  <!-- LEFT COLUMN -->
  <div>
    <div class="card">
      <h2>Server Status</h2>
      <div class="row"><span class="lbl">Status</span><span class="val"><span class="dot off" id="sDot"></span><span id="sStat">—</span></span></div>
      <div class="row"><span class="lbl">Map</span><span class="val" id="sMap">—</span></div>
      <div class="row"><span class="lbl">Players</span><span class="val" id="sPly">—</span></div>
    </div>

    <div class="card">
      <h2>Change Map</h2>
      <input class="map-inp" id="mapIn" type="text" placeholder="de_inferno, cs_assault...">
      <button class="btn sm" onclick="changeMap()">changelevel</button>
      <h2 style="margin-top:8px">Quick Actions</h2>
      <div class="qgrid">
        <button onclick="rcon('mp_restartgame 1')">Restart Round</button>
        <button onclick="rcon('status')">Status</button>
        <button onclick="rcon('sv_cheats 1')">Cheats ON</button>
        <button onclick="rcon('sv_cheats 0')">Cheats OFF</button>
        <button onclick="rcon('mp_timelimit 0')" >No Timelimit</button>
        <button onclick="rcon('mp_friendlyfire 1')">FF ON</button>
      </div>
    </div>
  </div>

  <!-- RIGHT COLUMN -->
  <div>
    <div class="card">
      <h2>Players <span id="plyCount" style="color:#444;font-weight:normal"></span></h2>
      <table>
        <thead><tr><th>#</th><th>Name</th><th>Score</th><th>Time</th><th></th></tr></thead>
        <tbody id="plyTable">
          <tr><td colspan="5" style="color:#333;text-align:center;padding:20px">Loading...</td></tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>RCON Console</h2>
      <div class="console" id="con">
        <div class="ln info">// <?= htmlspecialchars($CS_HOST) ?>:<?= $CS_PORT ?> — type a command and press Enter</div>
        <div class="ln info">// To kick a player: run "status", find their #userid, then: kick #&lt;id&gt;</div>
      </div>
      <div class="cmd-row">
        <input id="cmdIn" type="text" placeholder="rcon command..." autocomplete="off">
        <button class="btn" onclick="sendCmd()">Send</button>
      </div>
    </div>
  </div>

</div>

<script>
const con   = document.getElementById('con');
const cmdIn = document.getElementById('cmdIn');
const hist  = JSON.parse(localStorage.getItem('rcon_hist') || '[]');
let histIdx = -1;

function log(text, cls) {
  const el = document.createElement('div');
  el.className = 'ln ' + cls;
  el.textContent = text;
  con.appendChild(el);
  con.scrollTop = con.scrollHeight;
}

async function rcon(cmd) {
  log('> ' + cmd, 'cmd');
  const fd = new FormData();
  fd.append('cmd', cmd);
  try {
    const r = await fetch('?api=rcon', { method: 'POST', body: fd });
    const d = await r.json();
    log(d.output, d.success ? 'out' : 'err');
  } catch (e) {
    log('Request failed: ' + e.message, 'err');
  }
}

function sendCmd() {
  const cmd = cmdIn.value.trim();
  if (!cmd) return;
  cmdIn.value = '';
  histIdx = -1;
  if (hist[0] !== cmd) {
    hist.unshift(cmd);
    if (hist.length > 60) hist.pop();
    localStorage.setItem('rcon_hist', JSON.stringify(hist));
  }
  rcon(cmd);
}

cmdIn.addEventListener('keydown', e => {
  if (e.key === 'Enter')     { sendCmd(); return; }
  if (e.key === 'ArrowUp')   { histIdx = Math.min(histIdx + 1, hist.length - 1); cmdIn.value = hist[histIdx] || ''; e.preventDefault(); }
  if (e.key === 'ArrowDown') { histIdx = Math.max(histIdx - 1, -1); cmdIn.value = histIdx >= 0 ? hist[histIdx] : ''; e.preventDefault(); }
});

async function refreshStatus() {
  try {
    const d = await (await fetch('?api=status')).json();
    document.getElementById('sDot').className  = 'dot ' + (d.online ? 'on' : 'off');
    document.getElementById('sStat').textContent = d.online ? 'Online' : 'Offline';
    document.getElementById('sMap').textContent  = d.map || '—';
    document.getElementById('sPly').textContent  = d.online ? `${d.players}/${d.maxplayers}` : '—';
    document.getElementById('srvName').textContent = d.name || '';
  } catch {}
}

async function refreshPlayers() {
  try {
    const players = await (await fetch('?api=players')).json();
    const tbody = document.getElementById('plyTable');
    document.getElementById('plyCount').textContent = players.length ? `(${players.length})` : '';
    tbody.innerHTML = players.length
      ? players.map((p, i) => `<tr>
          <td class="lbl">${i+1}</td>
          <td>${esc(p.name)}</td>
          <td>${p.score}</td>
          <td>${p.time}</td>
          <td><button class="hint-btn" onclick="hintKick()">kick</button></td>
        </tr>`).join('')
      : '<tr><td colspan="5" style="color:#333;text-align:center;padding:20px">No players online</td></tr>';
  } catch {}
}

function hintKick() {
  log('// Run "status" to get the player #userid, then type: kick #<userid>', 'info');
  cmdIn.value = 'status';
  cmdIn.focus();
}

function changeMap() {
  const m = document.getElementById('mapIn').value.trim();
  if (!m) { log('// Enter a map name first', 'info'); return; }
  rcon('changelevel ' + m);
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

refreshStatus();
refreshPlayers();
setInterval(refreshStatus,  30000);
setInterval(refreshPlayers, 30000);
</script>
<?php endif ?>
</body>
</html>
