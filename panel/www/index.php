<?php
// ─── Config ───────────────────────────────────────────────────────────────────
session_start();
$CS_HOST        = getenv('CS_HOST')        ?: 'cs16';
$CS_PORT        = (int)(getenv('CS_PORT')  ?: 27015);
$RCON_PASSWORD  = getenv('RCON_PASSWORD')  ?: '';
$PANEL_PASSWORD = getenv('PANEL_PASSWORD') ?: '';
$HLDS_DIR       = rtrim(getenv('HLDS_DIR') ?: '/srv/hlds', '/');
$CSTRIKE        = "$HLDS_DIR/cstrike";

// ─── Auth ─────────────────────────────────────────────────────────────────────
$auth = !$PANEL_PASSWORD || ($_SESSION['auth'] ?? false);
if (!$auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals(trim($PANEL_PASSWORD), trim($_POST['password']))) { $_SESSION['auth'] = true; $auth = true; }
    else $loginError = 'Senha incorreta';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: /'); exit; }

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
    protected function send(string $d): void { socket_send($this->socket, $d, strlen($d), 0); }
    protected function recv(int $s = 4096): string { $b = ''; socket_recv($this->socket, $b, $s, 0); return $b ?? ''; }
    public function __destruct() { if ($this->socket) socket_close($this->socket); }
}

// ─── Server Query ─────────────────────────────────────────────────────────────
class ServerQuery extends UdpClient {
    public function getInfo(): ?array {
        try {
            $this->send("\xFF\xFF\xFF\xFF\x54Source Engine Query\x00");
            $r = $this->recv();
            // Newer HLDS versions require a challenge (same pattern as A2S_PLAYER)
            if (strlen($r) >= 9 && $r[4] === "\x41") {
                $this->send("\xFF\xFF\xFF\xFF\x54Source Engine Query\x00" . substr($r, 5, 4));
                $r = $this->recv();
            }
            if (strlen($r) < 6 || $r[4] !== "\x49") return null;
            $p = 5; $p++;
            $name = $this->rStr($r,$p); $map = $this->rStr($r,$p);
            $this->rStr($r,$p); $this->rStr($r,$p); $p += 2;
            return ['online'=>true,'name'=>$name,'map'=>$map,'players'=>ord($r[$p++]),'maxplayers'=>ord($r[$p])];
        } catch (Throwable) { return null; }
    }
    public function getPlayers(): array {
        try {
            $this->send("\xFF\xFF\xFF\xFF\x55\xFF\xFF\xFF\xFF"); $r = $this->recv();
            if (strlen($r) < 9) return [];
            if ($r[4] === "\x41") { $this->send("\xFF\xFF\xFF\xFF\x55".substr($r,5,4)); $r = $this->recv(); }
            if (strlen($r) < 6 || $r[4] !== "\x44") return [];
            $p = 5; $cnt = ord($r[$p++]); $list = [];
            for ($i=0; $i<$cnt && $p<strlen($r); $i++) {
                $p++; $name=$this->rStr($r,$p); if ($p+8>strlen($r)) break;
                $sc=unpack('l',substr($r,$p,4))[1]; $p+=4;
                $s=(int)unpack('f',substr($r,$p,4))[1]; $p+=4;
                $list[]=['name'=>$name?:'(empty)','score'=>$sc,'time'=>sprintf('%02d:%02d:%02d',$s/3600,($s%3600)/60,$s%60)];
            }
            return $list;
        } catch (Throwable) { return []; }
    }
    private function rStr(string $d, int &$p): string {
        $s=''; while ($p<strlen($d) && $d[$p]!=="\x00") $s.=$d[$p++]; $p++; return $s;
    }
}

// ─── RCON ────────────────────────────────────────────────────────────────────
class Rcon extends UdpClient {
    private string $pw;
    public function __construct(string $h, int $p, string $pw) { parent::__construct($h,$p); $this->pw=$pw; }
    public function execute(string $cmd): array {
        try {
            $this->send("\xFF\xFF\xFF\xFF"."challenge rcon\n"); $r=$this->recv(1024);
            if (!preg_match('/challenge rcon (\d+)/',$r,$m)) return ['success'=>false,'output'=>'Sem resposta do servidor.'];
            $this->send("\xFF\xFF\xFF\xFF"."rcon {$m[1]} \"{$this->pw}\" $cmd\n");
            $out=''; for($i=0;$i<16;$i++){$b=$this->recv(4096);if(strlen($b)<=4)break;$out.=substr($b,4);}
            $out=trim($out);
            if (str_contains($out,'Bad rcon_password')) return ['success'=>false,'output'=>'RCON password incorreta.'];
            return ['success'=>true,'output'=>$out?:'(sem output)'];
        } catch (Throwable $e) { return ['success'=>false,'output'=>'Erro: '.$e->getMessage()]; }
    }
}

// ─── Map helpers ─────────────────────────────────────────────────────────────
function installedMaps(string $cstrike): array {
    $files = glob("$cstrike/maps/*.bsp") ?: [];
    $maps  = array_map(fn($f) => basename($f, '.bsp'), $files);
    sort($maps);
    return $maps;
}

function isValidMapName(string $name): bool {
    return (bool) preg_match('/^[a-z0-9_\-]+$/i', $name);
}

function mapExists(string $name, string $cstrike): bool {
    return file_exists("$cstrike/maps/$name.bsp");
}

function rconCommandError(array $result): ?string {
    if (!($result['success'] ?? false)) return $result['output'] ?? 'Falha ao executar comando.';

    $output = strtolower($result['output'] ?? '');
    foreach (['unknown command', 'is not a valid command', 'not recognized'] as $needle) {
        if (str_contains($output, $needle)) return $result['output'] ?: 'Comando não reconhecido pelo servidor.';
    }

    return null;
}

function runRconCommand(Rcon $rcon, string $command): ?string {
    return rconCommandError($rcon->execute($command));
}

function applyBotQuota(Rcon $rcon, int $quota, int $skill): array {
    $minSkill = max(0, min(100, $skill));
    $maxSkill = min(100, $minSkill + 20);

    foreach ([
        'pb_bot_quota_match 0',
        'pb_minbots 0',
        'pb_maxbots 0',
        'pb removebots',
        "pb_minbotskill $minSkill",
        "pb_maxbotskill $maxSkill",
    ] as $command) {
        $error = runRconCommand($rcon, $command);
        if ($error !== null) return ['success' => false, 'output' => $error];
    }

    $added = 0;
    for ($i = 0; $i < $quota; $i++) {
        $botSkill = $maxSkill > $minSkill ? random_int($minSkill, $maxSkill) : $minSkill;
        $error = runRconCommand($rcon, "pb add $botSkill");
        if ($error !== null) {
            return ['success' => false, 'output' => $added > 0
                ? "Falha ao adicionar bots após $added inserção(ões): $error"
                : $error];
        }
        $added++;
    }

    return ['success' => true, 'output' => "$added bot(s) adicionados | skill $minSkill–$maxSkill."];
}

// ─── API ─────────────────────────────────────────────────────────────────────
if ($auth && isset($_GET['api'])) {
    error_reporting(0);          // evita warnings de socket vazarem no JSON
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    switch ($_GET['api']) {
        case 'status':
            echo json_encode((new ServerQuery($CS_HOST,$CS_PORT))->getInfo() ?: ['online'=>false]); break;
        case 'players':
            echo json_encode((new ServerQuery($CS_HOST,$CS_PORT))->getPlayers()); break;

        case 'rcon':
            if (!$isPost) { http_response_code(405); break; }
            $cmd = trim($_POST['cmd'] ?? '');
            echo json_encode($cmd ? (new Rcon($CS_HOST,$CS_PORT,$RCON_PASSWORD))->execute($cmd)
                                  : ['success'=>false,'output'=>'Comando vazio.']); break;

        case 'changelevel':
            if (!$isPost) { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); break; }
            $map = trim($_POST['map'] ?? '');
            if (!isValidMapName($map)) { echo json_encode(['success'=>false,'output'=>'Nome de mapa inválido.']); break; }
            if (is_dir("$CSTRIKE/maps") && !mapExists($map,$CSTRIKE)) {
                echo json_encode(['success'=>false,'output'=>"Mapa '$map' não encontrado no servidor."]); break;
            }
            $res = (new Rcon($CS_HOST,$CS_PORT,$RCON_PASSWORD))->execute("changelevel $map");
            // changelevel não retorna output — qualquer resposta bem-formada é sucesso
            if ($res['success'] || $res['output'] === '(sem output)') {
                $res = ['success'=>true,'output'=>"Trocando para $map..."];
            }
            echo json_encode($res); break;

        case 'maps_list':
            echo json_encode(installedMaps($CSTRIKE)); break;

        case 'mapcycle_get':
            $file = "$CSTRIKE/mapcycle.txt";
            echo json_encode(['content' => is_file($file) ? file_get_contents($file) : '']); break;

        case 'mapcycle_save':
            if (!$isPost) { http_response_code(405); break; }
            $raw   = $_POST['content'] ?? '';
            $valid = installedMaps($CSTRIKE);  // whitelist
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            $clean = array_filter($lines, function($l) use ($valid) {
                if (!isValidMapName($l)) return false;
                return empty($valid) || in_array($l, $valid, true);
            });
            $content = implode("\n", $clean) . "\n";
            $file    = "$CSTRIKE/mapcycle.txt";
            $tmp     = "$file.tmp";
            if (file_put_contents($tmp, $content) !== false && rename($tmp, $file)) {
                chmod($file, 0666);
                echo json_encode(['success'=>true, 'saved'=>count($clean)]);
            } else {
                echo json_encode(['success'=>false,'error'=>'Falha ao salvar — verifique permissões do volume.']);
            }
            break;

        case 'maps_upload':
            if (!$isPost) { http_response_code(405); break; }
            // Detect silent failure when post body exceeded post_max_size
            if (empty($_FILES) && $_SERVER['CONTENT_LENGTH'] > 0) {
                echo json_encode(['success'=>false,'error'=>'Arquivo muito grande (máx 64 MB).']); break;
            }
            $f = $_FILES['mapfile'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $errs = [1=>'Muito grande',2=>'Muito grande',3=>'Parcial',4=>'Sem arquivo',6=>'Sem /tmp',7=>'Falha ao gravar'];
                echo json_encode(['success'=>false,'error'=>$errs[$f['error']??0]??'Erro de upload']); break;
            }
            $name = basename($f['name']);
            if (!preg_match('/^[a-z0-9_\-]+\.bsp$/i', $name)) {
                echo json_encode(['success'=>false,'error'=>'Somente arquivos .bsp com nome válido.']); break;
            }
            $mapsDir = "$CSTRIKE/maps";
            if (!is_dir($mapsDir)) { echo json_encode(['success'=>false,'error'=>'Diretório maps/ não encontrado.']); break; }
            $dest = "$mapsDir/$name";
            $part = "$dest.part";
            if (!move_uploaded_file($f['tmp_name'], $part)) {
                echo json_encode(['success'=>false,'error'=>'Falha ao mover arquivo.']); break;
            }
            rename($part, $dest);
            echo json_encode(['success'=>true,'name'=>basename($name,'.bsp')]); break;

        case 'botnames_get':
            $file = "$CSTRIKE/addons/podbot/botnames.txt";
            echo json_encode(['content' => is_file($file) ? file_get_contents($file) : '', 'exists' => is_file($file)]); break;

        case 'botnames_save':
            if (!$isPost) { http_response_code(405); break; }
            $file = "$CSTRIKE/addons/podbot/botnames.txt";
            if (!is_file($file)) { echo json_encode(['success'=>false,'error'=>'PODBot não instalado (arquivo não encontrado).']); break; }
            $raw   = $_POST['content'] ?? '';
            $names = array_filter(array_map(fn($l) => substr(trim($l), 0, 21), explode("\n", $raw)), fn($l) => strlen($l) > 0 && $l[0] !== '#');
            if (count($names) < 9) { echo json_encode(['success'=>false,'error'=>'Mínimo de 9 nomes necessários.']); break; }
            $content = implode("\n", $names) . "\n";
            $tmp = "$file.tmp";
            if (file_put_contents($tmp, $content) !== false && rename($tmp, $file)) {
                chmod($file, 0666);
                echo json_encode(['success'=>true, 'saved'=>count($names)]);
            } else {
                echo json_encode(['success'=>false,'error'=>'Falha ao salvar — verifique permissões do volume.']);
            }
            break;

        case 'bots_apply':
            if (!$isPost) { http_response_code(405); break; }
            $quota = max(0, min(10, (int)($_POST['quota'] ?? 0)));
            $skill = max(0, min(100, (int)($_POST['skill'] ?? 60)));
            $rcon  = new Rcon($CS_HOST, $CS_PORT, $RCON_PASSWORD);
            echo json_encode(applyBotQuota($rcon, $quota, $skill)); break;

        case 'bots_kickall':
            if (!$isPost) { http_response_code(405); break; }
            $rcon = new Rcon($CS_HOST, $CS_PORT, $RCON_PASSWORD);
            foreach (['pb_bot_quota_match 0', 'pb_minbots 0', 'pb_maxbots 0', 'pb removebots'] as $command) {
                $error = runRconCommand($rcon, $command);
                if ($error !== null) {
                    echo json_encode(['success'=>false,'output'=>$error]);
                    break 2;
                }
            }
            echo json_encode(['success'=>true,'output'=>'Bots removidos do servidor.']); break;

        case 'settings_get':
            $keys = ['mp_timelimit','mp_roundtime','mp_freezetime','mp_buytime',
                     'mp_startmoney','mp_c4timer','mp_friendlyfire','mp_autoteambalance',
                     'mp_limitteams','mp_autokick','mp_maxrounds','mp_winlimit'];
            $res = (new Rcon($CS_HOST,$CS_PORT,$RCON_PASSWORD))->execute(implode(';', $keys));
            $vals = [];
            if ($res['success']) {
                preg_match_all('/"([a-z_0-9]+)" is "([^"]*)"/', $res['output'], $m, PREG_SET_ORDER);
                foreach ($m as $match) $vals[$match[1]] = $match[2];
            }
            echo json_encode(['success'=>$res['success'],'values'=>$vals]); break;

        case 'settings_set':
            if (!$isPost) { http_response_code(405); break; }
            $allowed = ['mp_timelimit','mp_roundtime','mp_freezetime','mp_buytime',
                        'mp_startmoney','mp_c4timer','mp_friendlyfire','mp_autoteambalance',
                        'mp_limitteams','mp_autokick','mp_maxrounds','mp_winlimit'];
            $rcon   = new Rcon($CS_HOST, $CS_PORT, $RCON_PASSWORD);
            $failed = [];
            $count  = 0;
            foreach ($allowed as $k) {
                if (isset($_POST[$k]) && is_numeric($_POST[$k])) {
                    $res = $rcon->execute("$k " . floatval($_POST[$k]));
                    if (!$res['success']) $failed[] = $k;
                    else $count++;
                }
            }
            if ($count === 0 && empty($failed)) {
                echo json_encode(['success'=>false,'output'=>'Nenhuma configuração enviada.']); break;
            }
            if (empty($failed)) {
                echo json_encode(['success'=>true,'output'=>"$count configuração(ões) aplicada(s)."]);
            } else {
                echo json_encode(['success'=>false,'output'=>'Falha em: '.implode(', ',$failed).'. Servidor offline ou RCON incorreto.']);
            }
            break;

        default: http_response_code(404); echo json_encode(['error'=>'Not found']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>CS 1.6 Admin Panel</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d0d0d;color:#c8c8c8;font-family:'Courier New',monospace;font-size:14px}
a{color:#5fa2dd;text-decoration:none}
header{background:#111;border-bottom:1px solid #2a2a2a;padding:10px 24px;display:flex;align-items:center;gap:16px}
header h1{color:#e8a000;font-size:16px;letter-spacing:1px;flex:1}
header .srv{color:#666;font-size:12px}
/* Tabs */
.tabs{background:#111;border-bottom:1px solid #222;display:flex;padding:0 16px}
.tab{background:transparent;color:#555;border:none;border-bottom:2px solid transparent;cursor:pointer;padding:10px 18px;font-family:inherit;font-size:13px;transition:color .15s}
.tab.active{color:#e8a000;border-bottom-color:#e8a000}
.tab:hover:not(.active){color:#aaa}
.tab-content{display:none}.tab-content.active{display:block}
/* Layout */
.layout{display:grid;grid-template-columns:300px 1fr;gap:16px;padding:16px}
.card{background:#111;border:1px solid #222;border-radius:4px;padding:16px;margin-bottom:16px}
.card h2{color:#666;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;border-bottom:1px solid #1e1e1e;padding-bottom:8px}
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
/* Console */
.console{background:#080808;border:1px solid #1e1e1e;border-radius:3px;height:320px;overflow-y:auto;padding:10px;font-size:13px;margin-bottom:10px}
.ln{margin-bottom:2px;line-height:1.5}
.cmd{color:#5fa2dd}.out{color:#c8c8c8;white-space:pre-wrap;word-break:break-all}.err{color:#e87070}.info{color:#555;font-style:italic}.ok{color:#4caf50}
/* Inputs / buttons */
.cmd-row{display:flex;gap:8px}
input[type=text],input[type=password],.map-inp{background:#080808;border:1px solid #2a2a2a;color:#eee;padding:8px 10px;border-radius:3px;font-family:inherit;font-size:13px}
input[type=text]:focus,input[type=password]:focus,.map-inp:focus{outline:none;border-color:#5fa2dd}
.cmd-row input{flex:1}
.btn{background:#1a3a5c;color:#7ab8e8;border:1px solid #2a5a8c;border-radius:3px;cursor:pointer;padding:8px 14px;font-family:inherit;font-size:13px}
.btn:hover{background:#2a5a8c}
.btn.sm{padding:5px 10px;font-size:12px}
.btn.green{background:#1a3a1a;color:#7ab87a;border-color:#2a5a2a}
.btn.green:hover{background:#2a5a2a}
.btn.full{width:100%;text-align:center;margin-bottom:8px}
.qgrid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px}
.qgrid button{background:#161616;color:#999;border:1px solid #252525;border-radius:3px;cursor:pointer;padding:7px 4px;font-family:inherit;font-size:11px}
.qgrid button:hover{background:#222;color:#eee}
.map-inp{display:block;width:100%;margin-bottom:6px}
/* Maps tab */
.maps-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px}
.maps-search{width:100%;background:#080808;border:1px solid #2a2a2a;color:#eee;padding:8px 10px;border-radius:3px;font-family:inherit;font-size:13px;margin-bottom:10px}
.maps-search:focus{outline:none;border-color:#5fa2dd}
.map-list{background:#080808;border:1px solid #1e1e1e;border-radius:3px;height:380px;overflow-y:auto}
.map-item{display:flex;align-items:center;padding:6px 10px;border-bottom:1px solid #111;gap:8px}
.map-item:hover{background:#111}
.map-name{flex:1;font-size:13px;color:#ccc}
.map-name.in-cycle{color:#7ab87a}
.mibtn{background:transparent;border:1px solid #2a2a2a;border-radius:3px;cursor:pointer;padding:2px 7px;font-size:11px;font-family:inherit;color:#888}
.mibtn.play{color:#5fa2dd;border-color:#1a3a5c}
.mibtn.play:hover{background:#1a3a5c;color:#7ab8e8}
.mibtn.add{color:#7ab87a;border-color:#1a3a1a}
.mibtn.add:hover{background:#1a3a1a}
.mibtn.del{color:#e87070;border-color:#5c1a1a}
.mibtn.del:hover{background:#5c1a1a}
.cycle-area{width:100%;height:320px;background:#080808;border:1px solid #1e1e1e;border-radius:3px;color:#eee;font-family:'Courier New',monospace;font-size:13px;padding:10px;resize:none;margin-bottom:8px}
.cycle-area:focus{outline:none;border-color:#5fa2dd}
.upload-zone{border:2px dashed #2a2a2a;border-radius:4px;padding:20px;text-align:center;color:#555;cursor:pointer;transition:border-color .2s,color .2s}
.upload-zone:hover,.upload-zone.drag{border-color:#5fa2dd;color:#7ab8e8}
.upload-zone input{display:none}
.tag{display:inline-block;background:#1a3a1a;color:#7ab87a;border-radius:3px;padding:1px 6px;font-size:11px;margin-left:4px}
/* Settings tab */
.cfg-layout{padding:16px}
.cfg-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.cfg-top h2{color:#e8a000;font-size:14px;letter-spacing:.5px}
.cfg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
.cfg-sec-title{color:#666;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;border-bottom:1px solid #1e1e1e;padding-bottom:8px}
.cfg-row{margin-bottom:14px}
.cfg-row-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px}
.cfg-label{color:#aaa;font-size:13px}
.cfg-val{color:#e8a000;font-size:13px;font-weight:bold;min-width:64px;text-align:right}
input[type=range]{-webkit-appearance:none;width:100%;height:4px;background:#2a2a2a;border-radius:2px;outline:none;cursor:pointer;accent-color:#e8a000}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:14px;height:14px;border-radius:50%;background:#e8a000;cursor:pointer}
.cfg-subtext{color:#444;font-size:11px;margin-top:4px}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #141414}
.toggle-row:last-child{border-bottom:none}
.toggle-btn{background:#1a1a1a;border:1px solid #333;border-radius:12px;cursor:pointer;padding:4px 14px;font-family:inherit;font-size:12px;color:#555;transition:all .15s;min-width:52px;text-align:center}
.toggle-btn.on{background:#1a3a1a;border-color:#2a5a2a;color:#7ab87a}
.cfg-msg{font-size:12px;color:#666;min-width:200px;text-align:right}
.cfg-msg.ok{color:#7ab87a}.cfg-msg.err{color:#e87070}
/* Login */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-box{background:#111;border:1px solid #222;border-radius:4px;padding:36px;width:320px}
.login-box h2{color:#e8a000;margin-bottom:24px;font-size:16px}
.login-box .btn{width:100%;text-align:center}
.errmsg{color:#e87070;font-size:12px;margin-bottom:10px}
@media(max-width:900px){.layout,.maps-layout{grid-template-columns:1fr}}
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
      <input type="password" name="password" placeholder="Senha do painel" autofocus style="width:100%;margin-bottom:12px">
      <button type="submit" class="btn">Entrar</button>
    </form>
  </div>
</div>

<?php else: ?>
<header>
  <h1>🎮 CS 1.6 Admin</h1>
  <span class="srv" id="srvName">conectando...</span>
  <a href="?logout" style="font-size:12px;color:#444">sair</a>
</header>

<nav class="tabs">
  <button class="tab active" data-tab="server">Servidor</button>
  <button class="tab" data-tab="maps">Mapas</button>
  <button class="tab" data-tab="bots">🤖 Bots</button>
  <button class="tab" data-tab="config">⚙️ Configurações</button>
</nav>

<!-- ═══ TAB: SERVIDOR ═══════════════════════════════════════════════════════ -->
<div id="tab-server" class="tab-content active">
<div class="layout">
  <div>
    <div class="card">
      <h2>Status</h2>
      <div class="row"><span class="lbl">Status</span><span class="val"><span class="dot off" id="sDot"></span><span id="sStat">—</span></span></div>
      <div class="row"><span class="lbl">Mapa</span><span class="val" id="sMap">—</span></div>
      <div class="row"><span class="lbl">Jogadores</span><span class="val" id="sPly">—</span></div>
    </div>
    <div class="card">
      <h2>Trocar Mapa</h2>
      <input class="map-inp" id="mapIn" type="text" placeholder="de_inferno, cs_assault...">
      <button class="btn full" onclick="changeMap()">changelevel</button>
      <h2 style="margin-top:4px">Ações Rápidas</h2>
      <div class="qgrid">
        <button onclick="rcon('mp_restartgame 1')">Reiniciar Round</button>
        <button onclick="rcon('status')">Status</button>
        <button onclick="rcon('sv_cheats 1')">Cheats ON</button>
        <button onclick="rcon('sv_cheats 0')">Cheats OFF</button>
        <button onclick="rcon('mp_friendlyfire 1')">FF ON</button>
        <button onclick="rcon('mp_timelimit 0')">Sem Limite</button>
      </div>
    </div>
  </div>
  <div>
    <div class="card">
      <h2>Jogadores <span id="plyCount" style="color:#444;font-weight:normal"></span></h2>
      <table>
        <thead><tr><th>#</th><th>Nome</th><th>Score</th><th>Tempo</th><th></th></tr></thead>
        <tbody id="plyTable"><tr><td colspan="5" style="color:#333;text-align:center;padding:20px">Carregando...</td></tr></tbody>
      </table>
    </div>
    <div class="card">
      <h2>Console RCON</h2>
      <div class="console" id="con">
        <div class="ln info">// <?= htmlspecialchars($CS_HOST) ?>:<?= $CS_PORT ?> — pressione Enter para enviar</div>
        <div class="ln info">// Para kickar: rode "status", encontre o #userid, depois: kick #&lt;id&gt;</div>
      </div>
      <div class="cmd-row">
        <input type="text" id="cmdIn" placeholder="comando rcon..." autocomplete="off">
        <button class="btn" onclick="sendCmd()">Enviar</button>
      </div>
    </div>
  </div>
</div>
</div>

<!-- ═══ TAB: MAPAS ══════════════════════════════════════════════════════════ -->
<div id="tab-maps" class="tab-content">
<div class="maps-layout">

  <!-- Left: installed maps -->
  <div>
    <div class="card">
      <h2>Mapas Instalados <span id="mapCount" style="color:#444;font-weight:normal"></span></h2>
      <input class="maps-search" id="mapSearch" type="text" placeholder="🔍  Buscar mapa..." oninput="filterMaps()">
      <div class="map-list" id="mapList">
        <div style="color:#333;text-align:center;padding:24px">Carregando mapas...</div>
      </div>
    </div>

    <div class="card">
      <h2>Upload de Mapa <span style="color:#444;font-weight:normal">(.bsp, máx 64 MB)</span></h2>
      <div class="upload-zone" id="uploadZone" onclick="document.getElementById('bspFile').click()"
           ondragover="event.preventDefault();this.classList.add('drag')"
           ondragleave="this.classList.remove('drag')"
           ondrop="handleDrop(event)">
        <input type="file" id="bspFile" accept=".bsp" onchange="uploadMap(this.files[0])">
        <div id="uploadMsg">Clique ou arraste um arquivo <strong>.bsp</strong> aqui</div>
      </div>
    </div>
  </div>

  <!-- Right: mapcycle editor -->
  <div>
    <div class="card">
      <h2>Rotação de Mapas <span id="cycleInfo" style="color:#444;font-weight:normal"></span></h2>
      <textarea class="cycle-area" id="cycleArea" placeholder="de_dust2&#10;de_inferno&#10;de_nuke&#10;..."></textarea>
      <div style="display:flex;gap:8px">
        <button class="btn green" onclick="saveCycle()" style="flex:1">💾 Salvar Rotação</button>
        <button class="btn sm" onclick="loadCycle()" style="color:#888;border-color:#2a2a2a;background:#161616">↺ Recarregar</button>
      </div>
      <div id="cycleMsg" style="font-size:12px;margin-top:8px;min-height:16px"></div>
    </div>
  </div>

</div>
</div>

<!-- ═══ TAB: BOTS ════════════════════════════════════════════════════════════ -->
<div id="tab-bots" class="tab-content">
<div class="cfg-layout">
  <div class="cfg-top">
    <h2>🤖 Gerenciar Bots (PODBot mm)</h2>
    <span id="botsMsg" class="cfg-msg"></span>
  </div>
  <div class="cfg-grid">

    <div class="card">
      <h2>⚙️ Controle</h2>
      <div class="cfg-row">
        <div class="cfg-row-head">
          <span class="cfg-label">Número de Bots</span>
          <span class="cfg-val" id="val-quota">5</span>
        </div>
        <input type="range" id="rng-quota" min="0" max="10" step="1" value="5"
               oninput="document.getElementById('val-quota').textContent=this.value">
        <div class="cfg-subtext">0 = sem bots · PODBot mantém a quantidade automaticamente</div>
      </div>
      <div class="cfg-row">
        <div class="cfg-row-head">
          <span class="cfg-label">Habilidade</span>
          <span class="cfg-val" id="val-skill">60</span>
        </div>
        <input type="range" id="rng-skill" min="0" max="100" step="10" value="60"
               oninput="syncSkill(this.value)">
        <div style="display:flex;justify-content:space-between;color:#444;font-size:10px;margin-top:3px">
          <span>Fácil</span><span>Normal</span><span>Difícil</span><span>Insano</span>
        </div>
        <div class="cfg-subtext" id="skill-hint">Skill 60–80 (faixa aplicada ao servidor)</div>
      </div>
      <div style="display:flex;gap:8px;margin-top:12px">
        <button class="btn green" onclick="applyBots()" style="flex:1">✔ Aplicar</button>
        <button id="btn-disable-bots" class="btn" onclick="disableBots()" style="color:#e87070;border-color:#5c1a1a;background:#1a0808">⏹ Desativar Bots</button>
      </div>
      <div style="color:#444;font-size:11px;margin-top:10px;line-height:1.6">
        Requer <strong style="color:#666">PODBot mm</strong> instalado no servidor.<br>
        Instalado automaticamente na primeira inicialização do container.
      </div>
    </div>

    <div class="card">
      <h2>📝 Nomes dos Bots <span style="color:#444;font-weight:normal">(mín. 9 · máx. 21 chars/nome)</span></h2>
      <textarea class="cycle-area" id="botnamesArea" placeholder="Pistoleiro&#10;Fragger&#10;Sniper_Pro&#10;..."></textarea>
      <div style="display:flex;gap:8px">
        <button class="btn green" onclick="saveBotnames()" style="flex:1">💾 Salvar Nomes</button>
        <button class="btn sm" onclick="loadBotnames()" style="color:#888;border-color:#2a2a2a;background:#161616">↺ Recarregar</button>
      </div>
      <div id="botnamesMsg" style="font-size:12px;margin-top:8px;min-height:16px"></div>
      <div style="color:#444;font-size:11px;margin-top:6px">O prefixo [POD] e o nível são adicionados automaticamente pelo PODBot.</div>
    </div>

  </div>
</div>
</div>

<!-- ═══ TAB: CONFIGURAÇÕES ══════════════════════════════════════════════════ -->
<div id="tab-config" class="tab-content">
<div class="cfg-layout">
  <div class="cfg-top">
    <h2>⚙️ Configurações de Jogo</h2>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span id="cfgMsg" class="cfg-msg"></span>
      <button class="btn sm" onclick="loadSettings()" style="color:#888;border-color:#2a2a2a;background:#161616">↺ Carregar do Servidor</button>
      <button class="btn green" onclick="applySettings()">✔ Aplicar Configurações</button>
    </div>
  </div>
  <div class="cfg-grid" id="cfgGrid">
    <div style="color:#444;padding:24px;text-align:center">Carregando configurações...</div>
  </div>
</div>
</div>

<script>
// ─── Tab switching ────────────────────────────────────────────────────────────
document.querySelectorAll('.tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    if (btn.dataset.tab === 'maps')   { loadMaps(); loadCycle(); }
    if (btn.dataset.tab === 'bots')   { loadBotnames(); }
    if (btn.dataset.tab === 'config') { if (!document.getElementById('rng_mp_timelimit')) buildSettings(); loadSettings(); }
  });
});

// ─── Console ─────────────────────────────────────────────────────────────────
const con   = document.getElementById('con');
const cmdIn = document.getElementById('cmdIn');
const hist  = JSON.parse(localStorage.getItem('rcon_hist') || '[]');
let histIdx = -1;

function log(text, cls) {
  const el = document.createElement('div');
  el.className = 'ln ' + cls; el.textContent = text;
  con.appendChild(el); con.scrollTop = con.scrollHeight;
}

async function rcon(cmd, silent = false) {
  if (!silent) log('> ' + cmd, 'cmd');
  const fd = new FormData(); fd.append('cmd', cmd);
  try {
    const d = await (await fetch('?api=rcon', { method:'POST', body:fd })).json();
    if (!silent) log(d.output, d.success ? 'out' : 'err');
    return d;
  } catch(e) { if (!silent) log('Falha: ' + e.message, 'err'); return null; }
}

function sendCmd() {
  const cmd = cmdIn.value.trim(); if (!cmd) return;
  cmdIn.value = ''; histIdx = -1;
  if (hist[0] !== cmd) { hist.unshift(cmd); if (hist.length > 60) hist.pop(); localStorage.setItem('rcon_hist', JSON.stringify(hist)); }
  rcon(cmd);
}
cmdIn.addEventListener('keydown', e => {
  if (e.key === 'Enter') { sendCmd(); return; }
  if (e.key === 'ArrowUp')   { histIdx = Math.min(histIdx+1, hist.length-1); cmdIn.value = hist[histIdx]||''; e.preventDefault(); }
  if (e.key === 'ArrowDown') { histIdx = Math.max(histIdx-1, -1); cmdIn.value = histIdx>=0?hist[histIdx]:''; e.preventDefault(); }
});

async function changeMap() {
  const m = document.getElementById('mapIn').value.trim();
  if (!m) { log('// Informe um nome de mapa', 'info'); return; }
  const fd = new FormData(); fd.append('map', m);
  log('> changelevel ' + m, 'cmd');
  try {
    const d = await (await fetch('?api=changelevel', {method:'POST',body:fd})).json();
    log(d.output, d.success ? 'out' : 'err');
  } catch(e) { log('Falha: ' + e.message, 'err'); }
}

// ─── Server status ────────────────────────────────────────────────────────────
async function refreshStatus() {
  try {
    const d = await (await fetch('?api=status')).json();
    document.getElementById('sDot').className = 'dot ' + (d.online ? 'on' : 'off');
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
      ? players.map((p,i) => `<tr><td class="lbl">${i+1}</td><td>${esc(p.name)}</td><td>${p.score}</td><td>${p.time}</td>
          <td><button class="hint-btn" onclick="hintKick()">kick</button></td></tr>`).join('')
      : '<tr><td colspan="5" style="color:#333;text-align:center;padding:20px">Nenhum jogador</td></tr>';
  } catch {}
}
function hintKick() { log('// Use "status" para obter o #userid, depois: kick #<id>', 'info'); cmdIn.value='status'; cmdIn.focus(); }

// ─── Maps tab ─────────────────────────────────────────────────────────────────
let allMaps = [];
let cycleSet = new Set();

async function loadMaps() {
  try {
    allMaps = await (await fetch('?api=maps_list')).json();
    document.getElementById('mapCount').textContent = allMaps.length ? `(${allMaps.length})` : '';
    renderMaps(allMaps);
  } catch { document.getElementById('mapList').innerHTML = '<div style="color:#e87070;padding:16px;text-align:center">Erro ao carregar mapas.<br>Volume HLDS montado?</div>'; }
}

function renderMaps(maps) {
  const list = document.getElementById('mapList');
  if (!maps.length) { list.innerHTML = '<div style="color:#444;text-align:center;padding:24px">Nenhum mapa encontrado</div>'; return; }
  list.innerHTML = maps.map(m => `
    <div class="map-item">
      <span class="map-name ${cycleSet.has(m) ? 'in-cycle' : ''}" title="${esc(m)}">${esc(m)}${cycleSet.has(m) ? '<span class="tag">rotação</span>' : ''}</span>
      <button class="mibtn play" onclick="playNow('${esc(m)}')">▶</button>
      <button class="mibtn add" onclick="addToCycle('${esc(m)}')">+</button>
    </div>`).join('');
}

function filterMaps() {
  const q = document.getElementById('mapSearch').value.toLowerCase();
  renderMaps(allMaps.filter(m => m.includes(q)));
}

function playNow(map) {
  const fd = new FormData(); fd.append('map', map);
  log('> changelevel ' + map, 'cmd');
  // Switch to server tab to show console output
  document.querySelector('[data-tab=server]').click();
  fetch('?api=changelevel', {method:'POST',body:fd})
    .then(r => r.json())
    .then(d => log(d.output, d.success ? 'out' : 'err'))
    .catch(e => log('Falha: ' + e.message, 'err'));
}

function addToCycle(map) {
  const area = document.getElementById('cycleArea');
  const lines = area.value.split('\n').map(l => l.trim()).filter(Boolean);
  if (!lines.includes(map)) {
    area.value = (area.value.trimEnd() ? area.value.trimEnd() + '\n' : '') + map + '\n';
    updateCycleSet();
    renderMaps(allMaps.filter(m => m.includes(document.getElementById('mapSearch').value.toLowerCase())));
    document.getElementById('cycleMsg').textContent = '';
  }
}

function updateCycleSet() {
  const lines = document.getElementById('cycleArea').value.split('\n').map(l => l.trim()).filter(Boolean);
  cycleSet = new Set(lines);
}

async function loadCycle() {
  try {
    const d = await (await fetch('?api=mapcycle_get')).json();
    document.getElementById('cycleArea').value = d.content || '';
    updateCycleSet();
    const lines = (d.content||'').split('\n').filter(l=>l.trim()).length;
    document.getElementById('cycleInfo').textContent = lines ? `(${lines} mapas)` : '';
    document.getElementById('cycleMsg').textContent = '';
  } catch { document.getElementById('cycleMsg').textContent = '⚠️ Erro ao carregar rotação'; }
}

async function saveCycle() {
  const content = document.getElementById('cycleArea').value;
  const fd = new FormData(); fd.append('content', content);
  document.getElementById('cycleMsg').textContent = 'Salvando...';
  try {
    const d = await (await fetch('?api=mapcycle_save', {method:'POST',body:fd})).json();
    if (d.success) {
      document.getElementById('cycleMsg').textContent = `✅ Salvo (${d.saved} mapas)`;
      document.getElementById('cycleMsg').style.color = '#7ab87a';
      updateCycleSet();
      renderMaps(allMaps.filter(m => m.includes(document.getElementById('mapSearch').value.toLowerCase())));
    } else {
      document.getElementById('cycleMsg').textContent = '❌ ' + (d.error || 'Erro');
      document.getElementById('cycleMsg').style.color = '#e87070';
    }
  } catch { document.getElementById('cycleMsg').textContent = '❌ Falha na requisição'; document.getElementById('cycleMsg').style.color='#e87070'; }
}

document.getElementById('cycleArea').addEventListener('input', updateCycleSet);

// ─── Upload ───────────────────────────────────────────────────────────────────
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.remove('drag');
  const file = e.dataTransfer.files[0];
  if (file) uploadMap(file);
}

async function uploadMap(file) {
  if (!file) return;
  if (!file.name.endsWith('.bsp')) { setUploadMsg('❌ Somente arquivos .bsp', '#e87070'); return; }
  if (file.size > 64 * 1024 * 1024) { setUploadMsg('❌ Arquivo muito grande (máx 64 MB)', '#e87070'); return; }
  setUploadMsg(`⏳ Enviando ${file.name} (${(file.size/1024/1024).toFixed(1)} MB)...`, '#888');
  const fd = new FormData(); fd.append('mapfile', file);
  try {
    const d = await (await fetch('?api=maps_upload', {method:'POST',body:fd})).json();
    if (d.success) {
      setUploadMsg(`✅ ${d.name} instalado!`, '#7ab87a');
      loadMaps();  // refresh map list
    } else {
      setUploadMsg('❌ ' + (d.error || 'Erro'), '#e87070');
    }
  } catch(e) { setUploadMsg('❌ Falha: ' + e.message, '#e87070'); }
  document.getElementById('bspFile').value = '';
}

function setUploadMsg(msg, color) {
  const el = document.getElementById('uploadMsg');
  el.textContent = msg; el.style.color = color || '';
}

// ─── Settings tab ─────────────────────────────────────────────────────────────
const SETTINGS = [
  { section:'⏱ Partida', items:[
    { key:'mp_timelimit',  label:'Duração da Partida',    unit:'min', min:0,  max:60,    step:1,    def:30,   hint:'0 = sem limite de tempo' },
    { key:'mp_maxrounds',  label:'Máx. Rounds por Mapa',  unit:'',    min:0,  max:30,    step:1,    def:0,    hint:'0 = sem limite de rounds' },
    { key:'mp_winlimit',   label:'Vitórias para Trocar',   unit:'',    min:0,  max:20,    step:1,    def:0,    hint:'0 = desativado' },
  ]},
  { section:'🔄 Rounds', items:[
    { key:'mp_roundtime',  label:'Tempo do Round',         unit:'min', min:1,  max:5,     step:0.5,  def:5,    nextRound:true },
    { key:'mp_freezetime', label:'Freeze Time',            unit:'seg', min:0,  max:30,    step:1,    def:6,    nextRound:true },
    { key:'mp_buytime',    label:'Tempo de Compra',        unit:'min', min:0,  max:2,     step:0.25, def:1.5,  nextRound:true },
    { key:'mp_c4timer',    label:'Timer da C4',            unit:'seg', min:10, max:90,    step:1,    def:35,   nextRound:true },
  ]},
  { section:'👥 Jogadores', items:[
    { key:'mp_startmoney',      label:'Dinheiro Inicial',         unit:'$',  min:800, max:16000, step:200, def:1800, nextRound:true },
    { key:'mp_limitteams',      label:'Desequilíbrio Máx.',       unit:'',   min:0,   max:5,     step:1,   def:2,    hint:'Diferença tolerada entre times (0 = desativado)' },
    { key:'mp_friendlyfire',    label:'Fogo Amigo',    toggle:true, def:0,    nextRound:true },
    { key:'mp_autoteambalance', label:'Balance Automático',       toggle:true, def:1 },
    { key:'mp_autokick',        label:'Auto Kick (idle/cheat)',   toggle:true, def:1 },
  ]},
];

function buildSettings() {
  const grid = document.getElementById('cfgGrid');
  grid.innerHTML = '';
  for (const sec of SETTINGS) {
    const card = document.createElement('div');
    card.className = 'card';
    let inner = `<h2>${sec.section}</h2>`;
    for (const s of sec.items) {
      const nextTag = s.nextRound ? ' <span style="color:#555;font-size:10px;font-weight:normal">próx. round</span>' : '';
      if (s.toggle) {
        inner += `<div class="toggle-row">
          <span class="cfg-label">${s.label}${nextTag}</span>
          <button class="toggle-btn${s.def?' on':''}" id="tog_${s.key}" data-key="${s.key}" data-val="${s.def}" onclick="toggleCfg(this)">${s.def?'ON':'OFF'}</button>
        </div>`;
      } else {
        inner += `<div class="cfg-row">
          <div class="cfg-row-head">
            <span class="cfg-label">${s.label}${nextTag}</span>
            <span class="cfg-val" id="val_${s.key}">${s.def}${s.unit?' '+s.unit:''}</span>
          </div>
          <input type="range" id="rng_${s.key}" data-key="${s.key}" data-unit="${s.unit||''}"
                 min="${s.min}" max="${s.max}" step="${s.step}" value="${s.def}" oninput="syncRange(this)">
          ${s.hint?`<div class="cfg-subtext">${s.hint}</div>`:''}
        </div>`;
      }
    }
    card.innerHTML = inner;
    grid.appendChild(card);
  }
}

function syncRange(el) {
  const unit = el.dataset.unit;
  document.getElementById('val_'+el.dataset.key).textContent = el.value + (unit?' '+unit:'');
}

function toggleCfg(btn) {
  const v = btn.dataset.val === '1' ? '0' : '1';
  btn.dataset.val = v;
  btn.textContent = v === '1' ? 'ON' : 'OFF';
  btn.classList.toggle('on', v === '1');
}

// ─── Bots tab ─────────────────────────────────────────────────────────────────
function syncSkill(v) {
  document.getElementById('val-skill').textContent = v;
  const hi = Math.min(100, parseInt(v) + 20);
  document.getElementById('skill-hint').textContent = `Skill ${v}–${hi} (faixa aplicada ao servidor)`;
}

async function applyBots() {
  const quota = document.getElementById('rng-quota').value;
  const skill = document.getElementById('rng-skill').value;
  const msg   = document.getElementById('botsMsg');
  msg.textContent = 'Aplicando...'; msg.className = 'cfg-msg';
  try {
    const fd = new FormData(); fd.append('quota', quota); fd.append('skill', skill);
    const d = await (await fetch('?api=bots_apply', {method:'POST',body:fd})).json();
    msg.textContent = d.success ? ('✔ ' + d.output) : ('⚠️ ' + (d.error || d.output));
    msg.className = 'cfg-msg ' + (d.success ? 'ok' : 'err');
  } catch(e) { msg.textContent = '⚠️ Erro de comunicação'; msg.className = 'cfg-msg err'; }
}

async function kickAllBots() {
  const msg = document.getElementById('botsMsg');
  msg.textContent = 'Removendo bots...'; msg.className = 'cfg-msg';
  try {
    const d = await (await fetch('?api=bots_kickall', {method:'POST',body:new FormData()})).json();
    msg.textContent = d.success ? '✔ ' + d.output : '⚠️ ' + (d.error||d.output);
    msg.className = 'cfg-msg ' + (d.success ? 'ok' : 'err');
  } catch(e) { msg.textContent = '⚠️ Erro de comunicação'; msg.className = 'cfg-msg err'; }
}

async function disableBots() {
  const slider = document.getElementById('rng-quota');
  slider.value = 0;
  document.getElementById('val-quota').textContent = '0';
  await kickAllBots();
}

async function loadBotnames() {
  const msg  = document.getElementById('botnamesMsg');
  const area = document.getElementById('botnamesArea');
  msg.textContent = 'Carregando...'; msg.style.color = '#666';
  try {
    const d = await (await fetch('?api=botnames_get')).json();
    if (!d.exists) { msg.textContent = '⚠️ PODBot não instalado ou ainda inicializando o servidor.'; msg.style.color = '#e87070'; return; }
    area.value = d.content;
    msg.textContent = ''; 
  } catch(e) { msg.textContent = '⚠️ Erro ao carregar'; msg.style.color = '#e87070'; }
}

async function saveBotnames() {
  const msg  = document.getElementById('botnamesMsg');
  const fd   = new FormData(); fd.append('content', document.getElementById('botnamesArea').value);
  msg.textContent = 'Salvando...'; msg.style.color = '#666';
  try {
    const d = await (await fetch('?api=botnames_save', {method:'POST',body:fd})).json();
    msg.textContent = d.success ? `✔ ${d.saved} nomes salvos com sucesso.` : '⚠️ ' + d.error;
    msg.style.color = d.success ? '#6bc46b' : '#e87070';
  } catch(e) { msg.textContent = '⚠️ Erro de comunicação'; msg.style.color = '#e87070'; }
}

async function loadSettings() {
  setCfgMsg('Carregando...', '');
  try {
    const d = await (await fetch('?api=settings_get')).json();
    if (!d.success) { setCfgMsg('⚠️ Servidor offline ou RCON indisponível', 'err'); return; }
    for (const [k, v] of Object.entries(d.values)) {
      const rng = document.getElementById('rng_'+k);
      const tog = document.getElementById('tog_'+k);
      if (rng) { rng.value = v; syncRange(rng); }
      else if (tog) {
        tog.dataset.val = v === '1' ? '1' : '0';
        tog.textContent = v === '1' ? 'ON' : 'OFF';
        tog.classList.toggle('on', v === '1');
      }
    }
    setCfgMsg('✅ Valores carregados do servidor', 'ok');
  } catch(e) { setCfgMsg('❌ Falha: '+e.message, 'err'); }
}

async function applySettings() {
  setCfgMsg('Aplicando...', '');
  const fd = new FormData();
  let hasNextRound = false;
  for (const sec of SETTINGS) {
    for (const s of sec.items) {
      const el = s.toggle ? document.getElementById('tog_'+s.key) : document.getElementById('rng_'+s.key);
      if (el) { fd.append(s.key, s.toggle ? el.dataset.val : el.value); if (s.nextRound) hasNextRound = true; }
    }
  }
  try {
    const d = await (await fetch('?api=settings_set', {method:'POST', body:fd})).json();
    if (d.success) {
      const extra = hasNextRound
        ? ' — <a href="#" onclick="rconSilent(\'mp_restartgame 1\');return false" style="color:#e8a000">reiniciar round agora</a>'
        : '';
      setCfgMsg('✅ ' + d.output + extra, 'ok');
    } else {
      setCfgMsg('❌ ' + d.output, 'err');
    }
  } catch(e) { setCfgMsg('❌ Falha: '+e.message, 'err'); }
}

async function rconSilent(cmd) {
  const fd = new FormData(); fd.append('cmd', cmd);
  await fetch('?api=rcon', {method:'POST', body:fd});
  setCfgMsg('✅ Round reiniciado — configurações ativas!', 'ok');
}

function setCfgMsg(msg, cls) {
  const el = document.getElementById('cfgMsg');
  el.textContent = msg; el.className = 'cfg-msg ' + cls;
}

// ─── Utils ────────────────────────────────────────────────────────────────────
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ─── Init ─────────────────────────────────────────────────────────────────────
refreshStatus(); refreshPlayers();
setInterval(refreshStatus, 30000); setInterval(refreshPlayers, 30000);
</script>
<?php endif ?>
</body>
</html>
