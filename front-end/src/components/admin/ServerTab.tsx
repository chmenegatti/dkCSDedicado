import { useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Activity, Users, MapPin, RotateCw, Send, Zap, ShieldOff, Shield, Infinity as InfinityIcon } from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "sonner";

const QuickAction = ({
  icon: Icon,
  label,
  variant = "default",
  onClick,
}: {
  icon: any;
  label: string;
  variant?: "default" | "danger" | "success";
  onClick?: () => void;
}) => {
  const styles = {
    default: "hover:border-primary/60 hover:text-primary hover:shadow-glow",
    danger: "hover:border-destructive/60 hover:text-destructive",
    success: "hover:border-secondary/60 hover:text-secondary hover:shadow-tactical",
  }[variant];
  return (
    <button
      onClick={onClick}
      className={`flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border border-border bg-surface-elevated text-xs font-mono text-muted-foreground transition-all ${styles}`}
    >
      <Icon className="h-3.5 w-3.5" />
      {label}
    </button>
  );
};

export default function ServerTab() {
  const [cmd, setCmd] = useState("");
  const [mapInput, setMapInput] = useState("");
  const [logs, setLogs] = useState<string[]>([
    "// cs16:27015 — pressione Enter para enviar",
    '// Para kickar: rode "status", encontre o #userid, depois: kick #<id>',
  ]);
  const consoleRef = useRef<HTMLDivElement>(null);

  const { data: status, isLoading: statusLoading } = useQuery({
    queryKey: ['status'],
    queryFn: api.status,
    refetchInterval: 5000,
  });

  const { data: players = [], isLoading: playersLoading } = useQuery({
    queryKey: ['players'],
    queryFn: api.players,
    refetchInterval: 5000,
  });

  const appendLog = (lines: string[]) => {
    setLogs((l) => [...l, ...lines]);
    setTimeout(() => {
      if (consoleRef.current) {
        consoleRef.current.scrollTop = consoleRef.current.scrollHeight;
      }
    }, 50);
  };

  const runRcon = async (command: string) => {
    appendLog([`> ${command}`]);
    try {
      const res = await api.rcon(command);
      appendLog([res.output || '(sem output)']);
    } catch {
      appendLog(['[erro] falha ao executar comando']);
    }
  };

  const send = () => {
    if (!cmd.trim()) return;
    const command = cmd.trim();
    setCmd("");
    runRcon(command);
  };

  const handleChangelevel = async () => {
    const map = mapInput.trim();
    if (!map) return;
    try {
      const res = await api.changelevel(map);
      if (res.success) {
        toast.success(res.output);
        setMapInput("");
      } else {
        toast.error(res.output);
      }
    } catch {
      toast.error("Falha ao trocar mapa");
    }
  };

  return (
    <div className="grid grid-cols-12 gap-5">
      {/* Left column */}
      <div className="col-span-12 lg:col-span-3 space-y-5">
        {/* Status */}
        <section className="panel overflow-hidden">
          <div className="panel-header">
            <Activity className="h-3.5 w-3.5 text-primary" />
            Status
          </div>
          <div className="p-5 space-y-3 font-mono text-sm">
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Status</span>
              {statusLoading ? (
                <span className="text-muted-foreground">...</span>
              ) : status?.online ? (
                <span className="flex items-center gap-2 text-status-online">
                  <span className="pulse-dot text-status-online" /> Online
                </span>
              ) : (
                <span className="flex items-center gap-2 text-status-offline">
                  <span className="h-2 w-2 rounded-full bg-current" /> Offline
                </span>
              )}
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Mapa</span>
              <span className="text-primary text-glow-primary">{status?.map ?? '—'}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground">Jogadores</span>
              {status?.online ? (
                <span>
                  <span className="text-foreground">{status.players ?? 0}</span>
                  <span className="text-muted-foreground">/{status.maxplayers ?? '—'}</span>
                </span>
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </div>
          </div>
        </section>

        {/* Trocar mapa */}
        <section className="panel">
          <div className="panel-header">
            <MapPin className="h-3.5 w-3.5 text-primary" />
            Trocar Mapa
          </div>
          <div className="p-4 space-y-3">
            <input
              value={mapInput}
              onChange={(e) => setMapInput(e.target.value)}
              onKeyDown={(e) => e.key === "Enter" && handleChangelevel()}
              placeholder="de_inferno, cs_assault…"
              className="w-full px-3 py-2.5 rounded-md bg-background border border-border font-mono text-sm placeholder:text-muted-foreground/60 focus:outline-none focus:border-primary/60 focus:ring-1 focus:ring-primary/40 transition-colors"
            />
            <button
              onClick={handleChangelevel}
              className="w-full py-2.5 rounded-md bg-gradient-primary text-primary-foreground text-sm font-mono font-semibold hover:shadow-glow transition-all"
            >
              changelevel
            </button>
          </div>
        </section>

        {/* Quick actions */}
        <section className="panel">
          <div className="panel-header">
            <Zap className="h-3.5 w-3.5 text-primary" />
            Ações Rápidas
          </div>
          <div className="p-4 grid grid-cols-2 gap-2">
            <QuickAction icon={RotateCw} label="Reiniciar Round" onClick={() => runRcon('mp_restartgame 1')} />
            <QuickAction icon={Activity} label="Status" onClick={() => runRcon('status')} />
            <QuickAction icon={Shield} label="Cheats ON" variant="success" onClick={() => runRcon('sv_cheats 1')} />
            <QuickAction icon={ShieldOff} label="Cheats OFF" variant="danger" onClick={() => runRcon('sv_cheats 0')} />
            <QuickAction icon={Zap} label="FF ON" onClick={() => runRcon('mp_friendlyfire 1')} />
            <QuickAction icon={InfinityIcon} label="Sem Limite" onClick={() => runRcon('mp_timelimit 0')} />
          </div>
        </section>
      </div>

      {/* Right column */}
      <div className="col-span-12 lg:col-span-9 space-y-5">
        {/* Players */}
        <section className="panel">
          <div className="panel-header justify-between">
            <span className="flex items-center gap-2">
              <Users className="h-3.5 w-3.5 text-primary" />
              Jogadores <span className="text-muted-foreground/70">({players.length})</span>
            </span>
            <span className="text-[10px]">Atualiza a cada 5s</span>
          </div>
          <div className="overflow-hidden">
            {playersLoading ? (
              <div className="p-8 text-center font-mono text-sm text-muted-foreground">
                Carregando...
              </div>
            ) : players.length === 0 ? (
              <div className="p-8 text-center font-mono text-sm text-muted-foreground">
                Nenhum jogador online
              </div>
            ) : (
              <table className="w-full font-mono text-sm">
                <thead>
                  <tr className="text-[10px] uppercase tracking-[0.18em] text-muted-foreground/70 border-b border-border/50">
                    <th className="text-left font-normal py-2.5 pl-5 w-10">#</th>
                    <th className="text-left font-normal py-2.5">Nome</th>
                    <th className="text-right font-normal py-2.5">Score</th>
                    <th className="text-right font-normal py-2.5 pr-5">Tempo</th>
                  </tr>
                </thead>
                <tbody>
                  {players.map((p, i) => (
                    <tr
                      key={i}
                      className="border-b border-border/30 last:border-0 group hover:bg-surface-elevated/60 transition-colors"
                    >
                      <td className="py-3 pl-5 text-muted-foreground">{i + 1}</td>
                      <td className="py-3">
                        <span>{p.name}</span>
                      </td>
                      <td className="py-3 text-right text-primary text-glow-primary">{p.score}</td>
                      <td className="py-3 text-right text-muted-foreground pr-5">{p.time}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>

        {/* RCON Console */}
        <section className="panel">
          <div className="panel-header">
            <span className="pulse-dot text-status-online" />
            Console RCON
          </div>
          <div className="p-4 space-y-3">
            <div
              ref={consoleRef}
              className="h-72 overflow-y-auto scrollbar-thin rounded-md bg-background border border-border/60 p-4 font-mono text-xs leading-relaxed"
            >
              {logs.map((l, i) => (
                <div
                  key={i}
                  className={
                    l.startsWith("//")
                      ? "text-muted-foreground/70 italic"
                      : l.startsWith(">")
                      ? "text-primary"
                      : "text-foreground/80"
                  }
                >
                  {l}
                </div>
              ))}
            </div>
            <div className="flex gap-2">
              <div className="flex-1 flex items-center gap-2 px-3 rounded-md bg-background border border-border focus-within:border-primary/60 focus-within:ring-1 focus-within:ring-primary/40 transition-colors">
                <span className="font-mono text-primary text-sm">$</span>
                <input
                  value={cmd}
                  onChange={(e) => setCmd(e.target.value)}
                  onKeyDown={(e) => e.key === "Enter" && send()}
                  placeholder="comando rcon…"
                  className="flex-1 py-2.5 bg-transparent font-mono text-sm placeholder:text-muted-foreground/60 focus:outline-none"
                />
              </div>
              <button
                onClick={send}
                className="px-5 rounded-md bg-gradient-primary text-primary-foreground font-mono text-sm font-semibold hover:shadow-glow transition-all flex items-center gap-2"
              >
                <Send className="h-3.5 w-3.5" />
                Enviar
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
