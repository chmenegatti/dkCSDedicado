import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Bot, Settings, Check, Power, Save, RotateCw, FileText } from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "sonner";

export default function BotsTab() {
  const [count, setCount] = useState(5);
  const [skill, setSkill] = useState(60);
  const [names, setNames] = useState("");

  const { data: botnamesData, refetch: refetchBotnames } = useQuery({
    queryKey: ['botnames'],
    queryFn: api.botnamesGet,
  });

  useEffect(() => {
    if (botnamesData?.content !== undefined) {
      setNames(botnamesData.content);
    }
  }, [botnamesData]);

  const skillLabel = skill < 30 ? "Fácil" : skill < 60 ? "Normal" : skill < 85 ? "Difícil" : "Insano";

  const handleApply = async () => {
    try {
      const res = await api.botsApply(count, skill);
      if (res.success) {
        toast.success(res.output);
      } else {
        toast.error(res.output);
      }
    } catch {
      toast.error('Falha ao aplicar bots');
    }
  };

  const handleKickall = async () => {
    try {
      const res = await api.botsKickall();
      if (res.success) {
        toast.success(res.output);
      } else {
        toast.error(res.output);
      }
    } catch {
      toast.error('Falha ao remover bots');
    }
  };

  const handleSaveNames = async () => {
    try {
      const res = await api.botnamesSave(names);
      if (res.success) {
        toast.success(`Nomes salvos (${res.saved} nomes)`);
      } else {
        toast.error(res.error ?? 'Falha ao salvar nomes');
      }
    } catch {
      toast.error('Erro ao salvar nomes');
    }
  };

  const handleReload = async () => {
    const result = await refetchBotnames();
    if (result.data?.content !== undefined) {
      setNames(result.data.content);
    }
    toast.success('Nomes recarregados');
  };

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="h-10 w-10 rounded-lg bg-primary/10 border border-primary/30 grid place-items-center">
          <Bot className="h-5 w-5 text-primary" />
        </div>
        <div>
          <h2 className="font-mono text-lg font-semibold">Gerenciar Bots <span className="text-muted-foreground text-sm">(PODBot mm)</span></h2>
          <p className="text-xs text-muted-foreground font-mono">Configure quantidade, habilidade e nomes</p>
        </div>
      </div>

      <div className="grid grid-cols-12 gap-5">
        <section className="panel col-span-12 lg:col-span-7">
          <div className="panel-header">
            <Settings className="h-3.5 w-3.5 text-primary" />
            Controle
          </div>
          <div className="p-6 space-y-7">
            <div>
              <div className="flex items-center justify-between mb-3">
                <label className="font-mono text-sm">Número de Bots</label>
                <span className="font-mono text-2xl font-bold text-primary text-glow-primary tabular-nums">{count}</span>
              </div>
              <input
                type="range" min={0} max={16} value={count}
                onChange={(e) => setCount(+e.target.value)}
                className="w-full accent-primary"
              />
              <p className="font-mono text-[11px] text-muted-foreground mt-2">
                0 = sem bots · PODBot mantém a quantidade automaticamente
              </p>
            </div>

            <div>
              <div className="flex items-center justify-between mb-3">
                <label className="font-mono text-sm">Habilidade <span className="text-muted-foreground">· {skillLabel}</span></label>
                <span className="font-mono text-2xl font-bold text-primary text-glow-primary tabular-nums">{skill}</span>
              </div>
              <input
                type="range" min={0} max={100} value={skill}
                onChange={(e) => setSkill(+e.target.value)}
                className="w-full accent-primary"
              />
              <div className="flex justify-between font-mono text-[10px] text-muted-foreground mt-2 uppercase tracking-wider">
                <span>Fácil</span><span>Normal</span><span>Difícil</span><span>Insano</span>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3 pt-2">
              <button
                onClick={handleApply}
                className="flex items-center justify-center gap-2 py-3 rounded-md bg-gradient-tactical text-secondary-foreground font-mono text-sm font-semibold hover:shadow-tactical transition-all"
              >
                <Check className="h-4 w-4" />
                Aplicar
              </button>
              <button
                onClick={handleKickall}
                className="flex items-center justify-center gap-2 py-3 rounded-md bg-destructive/10 text-destructive border border-destructive/40 font-mono text-sm font-semibold hover:bg-destructive/20 transition-all"
              >
                <Power className="h-4 w-4" />
                Desativar Bots
              </button>
            </div>

            <div className="pt-2 border-t border-border/50 space-y-1.5">
              <p className="font-mono text-[11px] text-muted-foreground">
                Requer <span className="text-primary">PODBot mm</span> instalado no servidor.
              </p>
              <p className="font-mono text-[11px] text-muted-foreground">
                Instalado automaticamente na primeira inicialização do container.
              </p>
            </div>
          </div>
        </section>

        <section className="panel col-span-12 lg:col-span-5">
          <div className="panel-header justify-between">
            <span className="flex items-center gap-2">
              <FileText className="h-3.5 w-3.5 text-primary" />
              Nomes dos Bots
            </span>
            <span className="text-[10px] normal-case">mín. 9 · máx. 21 chars/nome</span>
          </div>
          <div className="p-4 space-y-3">
            <textarea
              value={names}
              onChange={(e) => setNames(e.target.value)}
              rows={14}
              className="w-full p-3 rounded-md bg-background border border-border font-mono text-sm leading-relaxed focus:outline-none focus:border-primary/60 focus:ring-1 focus:ring-primary/40 transition-colors resize-none scrollbar-thin"
            />
            <div className="flex gap-2">
              <button
                onClick={handleSaveNames}
                className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-md bg-gradient-tactical text-secondary-foreground font-mono text-sm font-semibold hover:shadow-tactical transition-all"
              >
                <Save className="h-3.5 w-3.5" />
                Salvar Nomes
              </button>
              <button
                onClick={handleReload}
                className="flex items-center gap-2 px-4 py-2.5 rounded-md bg-surface-elevated border border-border font-mono text-xs hover:border-primary/60 hover:text-primary transition-colors"
              >
                <RotateCw className="h-3 w-3" />
                Recarregar
              </button>
            </div>
            <p className="font-mono text-[11px] text-muted-foreground">
              O prefixo <span className="text-primary">[POD]</span> e o nível são adicionados automaticamente pelo PODBot.
            </p>
          </div>
        </section>
      </div>
    </div>
  );
}
