<?php
// ─── Config ───────────────────────────────────────────────────────────────────
$CS_HOST        = getenv('CS_HOST')        ?: 'cs16';
$CS_PORT        = (int)(getenv('CS_PORT')  ?: 27015);
$RCON_PASSWORD  = getenv('RCON_PASSWORD')  ?: '';
$PANEL_PASSWORD = getenv('PANEL_PASSWORD') ?: '';
$HLDS_DIR       = rtrim(getenv('HLDS_DIR') ?: '/srv/hlds', '/');
$CSTRIKE        = "$HLDS_DIR/cstrike";

// ─── CORS ─────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Panel-Password');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Auth ─────────────────────────────────────────────────────────────────────
$providedPassword = $_SERVER['HTTP_X_PANEL_PASSWORD'] ?? ($_GET['X-Panel-Password'] ?? '');
$auth = !$PANEL_PASSWORD || hash_equals(trim($PANEL_PASSWORD), trim($providedPassword));

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
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (!$auth) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['api'])) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

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

