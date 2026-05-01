import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Settings, Timer, Users, Check, Download, Clock } from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "sonner";

const Slider = ({
  label, hint, value, min, max, step = 1, onChange, suffix,
}: { label: string; hint?: string; value: number; min: number; max: number; step?: number; onChange: (v: number) => void; suffix: string; }) => (
  <div>
    <div className="flex items-center justify-between mb-2">
      <label className="font-mono text-sm">
        {label} {hint && <span className="text-muted-foreground/70 text-xs">{hint}</span>}
      </label>
      <span className="font-mono text-sm font-semibold text-primary text-glow-primary tabular-nums">
        {value} {suffix}
      </span>
    </div>
    <input
      type="range" min={min} max={max} step={step} value={value}
      onChange={(e) => onChange(+e.target.value)}
      className="w-full accent-primary"
    />
  </div>
);

const Toggle = ({ label, hint, value, onChange }: { label: string; hint?: string; value: boolean; onChange: (v: boolean) => void }) => (
  <div className="flex items-center justify-between py-1">
    <span className="font-mono text-sm">
      {label} {hint && <span className="text-muted-foreground/70 text-xs">{hint}</span>}
    </span>
    <button
      onClick={() => onChange(!value)}
      className={`relative h-6 w-12 rounded-full border transition-colors ${
        value ? "bg-secondary/20 border-secondary/60" : "bg-surface-elevated border-border"
      }`}
    >
      <span className={`absolute top-0.5 h-5 w-5 rounded-full transition-transform ${
        value ? "translate-x-6 bg-secondary shadow-tactical" : "translate-x-0.5 bg-muted-foreground"
      }`} />
      <span className={`absolute inset-0 grid place-items-center font-mono text-[9px] font-bold tracking-wider ${value ? "pl-1 text-secondary" : "pr-1 text-muted-foreground justify-self-end"}`}>
        {value ? "ON" : "OFF"}
      </span>
    </button>
  </div>
);

export default function ConfigTab() {
  const [duration, setDuration] = useState(30);
  const [maxRounds, setMaxRounds] = useState(0);
  const [winsToChange, setWinsToChange] = useState(0);
  const [roundTime, setRoundTime] = useState(5);
  const [freezeTime, setFreezeTime] = useState(6);
  const [buyTime, setBuyTime] = useState(1.5);
  const [c4Timer, setC4Timer] = useState(35);
  const [startMoney, setStartMoney] = useState(1800);
  const [imbalance, setImbalance] = useState(2);
  const [ff, setFF] = useState(false);
  const [autoBalance, setAutoBalance] = useState(true);
  const [autoKick, setAutoKick] = useState(true);

  const { data: settingsData, isLoading, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: api.settingsGet,
  });

  const loadFromServer = async () => {
    const result = await refetch();
    const vals = result.data?.values;
    if (!vals) { toast.error('Falha ao carregar configurações'); return; }
    if (vals.mp_timelimit !== undefined) setDuration(parseFloat(vals.mp_timelimit));
    if (vals.mp_maxrounds !== undefined) setMaxRounds(parseInt(vals.mp_maxrounds));
    if (vals.mp_winlimit !== undefined) setWinsToChange(parseInt(vals.mp_winlimit));
    if (vals.mp_roundtime !== undefined) setRoundTime(parseFloat(vals.mp_roundtime));
    if (vals.mp_freezetime !== undefined) setFreezeTime(parseInt(vals.mp_freezetime));
    if (vals.mp_buytime !== undefined) setBuyTime(parseFloat(vals.mp_buytime));
    if (vals.mp_c4timer !== undefined) setC4Timer(parseInt(vals.mp_c4timer));
    if (vals.mp_startmoney !== undefined) setStartMoney(parseInt(vals.mp_startmoney));
    if (vals.mp_limitteams !== undefined) setImbalance(parseInt(vals.mp_limitteams));
    if (vals.mp_friendlyfire !== undefined) setFF(vals.mp_friendlyfire !== '0');
    if (vals.mp_autoteambalance !== undefined) setAutoBalance(vals.mp_autoteambalance !== '0');
    if (vals.mp_autokick !== undefined) setAutoKick(vals.mp_autokick !== '0');
    toast.success('Configurações carregadas');
  };

  const handleApply = async () => {
    try {
      const res = await api.settingsSet({
        mp_timelimit: duration,
        mp_maxrounds: maxRounds,
        mp_winlimit: winsToChange,
        mp_roundtime: roundTime,
        mp_freezetime: freezeTime,
        mp_buytime: buyTime,
        mp_c4timer: c4Timer,
        mp_startmoney: startMoney,
        mp_limitteams: imbalance,
        mp_friendlyfire: ff ? 1 : 0,
        mp_autoteambalance: autoBalance ? 1 : 0,
        mp_autokick: autoKick ? 1 : 0,
      });
      if (res.success) {
        toast.success(res.output);
      } else {
        toast.error(res.output);
      }
    } catch {
      toast.error('Falha ao aplicar configurações');
    }
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="h-10 w-10 rounded-lg bg-primary/10 border border-primary/30 grid place-items-center">
            <Settings className="h-5 w-5 text-primary" />
          </div>
          <div>
            <h2 className="font-mono text-lg font-semibold">Configurações de Jogo</h2>
            <p className="text-xs text-muted-foreground font-mono">Cvars e regras da partida</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={loadFromServer}
            disabled={isLoading}
            className="flex items-center gap-2 px-3 py-2 rounded-md bg-surface-elevated border border-border font-mono text-xs hover:border-primary/60 hover:text-primary transition-colors disabled:opacity-50"
          >
            <Download className="h-3 w-3" />
            Carregar do Servidor
          </button>
          <button
            onClick={handleApply}
            className="flex items-center gap-2 px-4 py-2 rounded-md bg-gradient-tactical text-secondary-foreground font-mono text-sm font-semibold hover:shadow-tactical transition-all"
          >
            <Check className="h-3.5 w-3.5" />
            Aplicar Configurações
          </button>
        </div>
      </div>

      <div className="grid grid-cols-12 gap-5">
        <section className="panel col-span-12 md:col-span-6 lg:col-span-4">
          <div className="panel-header">
            <Clock className="h-3.5 w-3.5 text-primary" />
            Partida
          </div>
          <div className="p-5 space-y-6">
            <Slider label="Duração da Partida" value={duration} min={0} max={120} onChange={setDuration} suffix="min" />
            <p className="-mt-4 font-mono text-[10px] text-muted-foreground">0 = sem limite de tempo</p>
            <Slider label="Máx. Rounds por Mapa" value={maxRounds} min={0} max={60} onChange={setMaxRounds} suffix="" />
            <p className="-mt-4 font-mono text-[10px] text-muted-foreground">0 = sem limite de rounds</p>
            <Slider label="Vitórias para Trocar" value={winsToChange} min={0} max={30} onChange={setWinsToChange} suffix="" />
            <p className="-mt-4 font-mono text-[10px] text-muted-foreground">0 = desativado</p>
          </div>
        </section>

        <section className="panel col-span-12 md:col-span-6 lg:col-span-4">
          <div className="panel-header">
            <Timer className="h-3.5 w-3.5 text-primary" />
            Rounds
          </div>
          <div className="p-5 space-y-6">
            <Slider label="Tempo do Round" hint="próx. round" value={roundTime} min={1} max={10} onChange={setRoundTime} suffix="min" />
            <Slider label="Freeze Time" hint="próx. round" value={freezeTime} min={0} max={15} onChange={setFreezeTime} suffix="seg" />
            <Slider label="Tempo de Compra" hint="próx. round" value={buyTime} min={0.5} max={5} step={0.5} onChange={setBuyTime} suffix="min" />
            <Slider label="Timer da C4" hint="próx. round" value={c4Timer} min={10} max={90} onChange={setC4Timer} suffix="seg" />
          </div>
        </section>

        <section className="panel col-span-12 lg:col-span-4">
          <div className="panel-header">
            <Users className="h-3.5 w-3.5 text-primary" />
            Jogadores
          </div>
          <div className="p-5 space-y-6">
            <Slider label="Dinheiro Inicial" hint="próx. round" value={startMoney} min={800} max={16000} step={100} onChange={setStartMoney} suffix="$" />
            <div>
              <Slider label="Desequilíbrio Máx." value={imbalance} min={0} max={5} onChange={setImbalance} suffix="" />
              <p className="font-mono text-[10px] text-muted-foreground mt-1">Diferença tolerada entre times (0 = desativado)</p>
            </div>
            <div className="space-y-3 pt-2 border-t border-border/50">
              <Toggle label="Fogo Amigo" hint="próx. round" value={ff} onChange={setFF} />
              <Toggle label="Balance Automático" value={autoBalance} onChange={setAutoBalance} />
              <Toggle label="Auto Kick" hint="(idle/cheat)" value={autoKick} onChange={setAutoKick} />
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
