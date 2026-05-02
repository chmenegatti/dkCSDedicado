import { useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Search, Play, Plus, Save, RotateCw, Upload, X, GripVertical, Map as MapIcon } from "lucide-react";
import { api } from "@/lib/api";
import { toast } from "sonner";

export default function MapsTab() {
  const queryClient = useQueryClient();
  const [q, setQ] = useState("");
  const [rotation, setRotation] = useState<string[]>([]);

  const { data: maps = [], isLoading } = useQuery({
    queryKey: ['maps'],
    queryFn: api.mapsList,
  });

  const { data: mapcycleData, refetch: refetchMapcycle } = useQuery({
    queryKey: ['mapcycle'],
    queryFn: api.mapcycleGet,
  });

  useEffect(() => {
    if (mapcycleData?.content) {
      const lines = mapcycleData.content.split('\n').map((l) => l.trim()).filter(Boolean);
      setRotation(lines);
    }
  }, [mapcycleData]);

  const filtered = useMemo(
    () => maps.filter((m) => m.toLowerCase().includes(q.toLowerCase())),
    [maps, q]
  );

  const add = (m: string) => !rotation.includes(m) && setRotation([...rotation, m]);
  const remove = (m: string) => setRotation(rotation.filter((x) => x !== m));

  const handleSaveRotation = async () => {
    try {
      const res = await api.mapcycleSave(rotation.join('\n'));
      if (res.success) {
        toast.success(`Rotação salva (${res.saved} mapas)`);
      } else {
        toast.error(res.error ?? 'Falha ao salvar rotação');
      }
    } catch {
      toast.error('Erro ao salvar rotação');
    }
  };

  const handleReload = async () => {
    await refetchMapcycle();
    toast.success('Rotação recarregada');
  };

  const handlePlay = async (map: string) => {
    try {
      const res = await api.changelevel(map);
      if (res.success) {
        toast.success(res.output);
      } else {
        toast.error(res.output);
      }
    } catch {
      toast.error('Falha ao trocar mapa');
    }
  };

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    try {
      const res = await api.mapsUpload(file);
      if (res.success) {
        if (res.maps && res.maps.length > 1) {
          toast.success(`${res.maps.length} mapas extraídos: ${res.maps.join(', ')}`);
        } else {
          const name = res.maps?.[0] ?? res.name;
          toast.success(`Mapa "${name}" enviado com sucesso`);
        }
        queryClient.invalidateQueries({ queryKey: ['maps'] });
      } else {
        toast.error(res.error ?? 'Falha no upload');
      }
    } catch {
      toast.error('Erro ao enviar mapa');
    }
    e.target.value = '';
  };

  return (
    <div className="grid grid-cols-12 gap-3 sm:gap-5">
      <div className="col-span-12 lg:col-span-7 space-y-3 sm:space-y-5">
        <section className="panel">
          <div className="panel-header justify-between">
            <span className="flex items-center gap-2">
              <MapIcon className="h-3.5 w-3.5 text-primary" />
              Mapas Instalados <span className="text-muted-foreground/70">({maps.length})</span>
            </span>
          </div>
          <div className="p-4 space-y-3">
            <div className="flex items-center gap-2 px-3 rounded-md bg-background border border-border focus-within:border-primary/60 focus-within:ring-1 focus-within:ring-primary/40 transition-colors">
              <Search className="h-4 w-4 text-muted-foreground" />
              <input
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Buscar mapa…"
                className="flex-1 py-2.5 bg-transparent font-mono text-sm placeholder:text-muted-foreground/60 focus:outline-none"
              />
            </div>
            {isLoading ? (
              <div className="py-8 text-center font-mono text-sm text-muted-foreground">
                Carregando mapas...
              </div>
            ) : (
              <div className="max-h-[440px] overflow-y-auto scrollbar-thin space-y-1 pr-1">
                {filtered.map((m) => {
                  const inRot = rotation.includes(m);
                  return (
                    <div
                      key={m}
                      className="group flex items-center justify-between px-3 py-2 rounded-md hover:bg-surface-elevated border border-transparent hover:border-border/60 transition-all"
                    >
                      <div className="flex items-center gap-3 font-mono text-sm">
                        <span>{m}</span>
                        {inRot && (
                          <span className="text-[10px] px-1.5 py-0.5 rounded bg-secondary/15 text-secondary border border-secondary/30 uppercase tracking-wider">
                            rotação
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-1.5 opacity-50 group-hover:opacity-100 transition-opacity">
                        <button
                          onClick={() => handlePlay(m)}
                          className="h-7 w-7 grid place-items-center rounded bg-accent/10 text-accent border border-accent/30 hover:bg-accent/20 transition-colors"
                        >
                          <Play className="h-3 w-3" />
                        </button>
                        <button
                          onClick={() => add(m)}
                          disabled={inRot}
                          className="h-7 w-7 grid place-items-center rounded bg-secondary/10 text-secondary border border-secondary/30 hover:bg-secondary/20 transition-colors disabled:opacity-30"
                        >
                          <Plus className="h-3 w-3" />
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <Upload className="h-3.5 w-3.5 text-primary" />
            Upload de Mapa <span className="text-muted-foreground/70 normal-case">(.bsp / .zip / .rar, máx 64 mb)</span>
          </div>
          <div className="p-4">
            <label className="flex flex-col items-center justify-center gap-3 py-10 rounded-lg border-2 border-dashed border-border hover:border-primary/60 hover:bg-primary/5 cursor-pointer transition-all group">
              <Upload className="h-8 w-8 text-muted-foreground group-hover:text-primary transition-colors" />
              <div className="text-center">
                <div className="font-mono text-sm">
                  Clique ou arraste um arquivo aqui
                </div>
                <div className="font-mono text-xs text-muted-foreground mt-1">
                  <span className="text-primary">.bsp</span> · <span className="text-primary">.zip</span> · <span className="text-primary">.rar</span> &nbsp;— máx 64 MB
                </div>
              </div>
              <input type="file" accept=".bsp,.zip,.rar,application/zip,application/x-rar-compressed" className="hidden" onChange={handleUpload} />
            </label>
          </div>
        </section>
      </div>

      <div className="col-span-12 lg:col-span-5">
        <section className="panel sticky top-24">
          <div className="panel-header">
            <GripVertical className="h-3.5 w-3.5 text-primary" />
            Rotação de Mapas <span className="text-muted-foreground/70">({rotation.length} mapas)</span>
          </div>
          <div className="p-4 space-y-3">
            <div className="min-h-[360px] max-h-[440px] overflow-y-auto scrollbar-thin space-y-1.5 p-2 rounded-md bg-background/50 border border-border/60">
              {rotation.length === 0 && (
                <div className="grid place-items-center h-full py-20 text-muted-foreground/60 font-mono text-sm">
                  Adicione mapas à rotação
                </div>
              )}
              {rotation.map((m, i) => (
                <div
                  key={m}
                  className="flex items-center justify-between px-3 py-2 rounded-md bg-surface-elevated border border-border/50 group"
                >
                  <div className="flex items-center gap-3 font-mono text-sm">
                    <GripVertical className="h-3.5 w-3.5 text-muted-foreground/50 cursor-grab" />
                    <span className="text-primary/80 text-xs w-4">{i + 1}</span>
                    <span>{m}</span>
                  </div>
                  <button
                    onClick={() => remove(m)}
                    className="opacity-0 group-hover:opacity-100 h-6 w-6 grid place-items-center rounded text-destructive hover:bg-destructive/15 transition-all"
                  >
                    <X className="h-3.5 w-3.5" />
                  </button>
                </div>
              ))}
            </div>
            <div className="flex gap-2">
              <button
                onClick={handleSaveRotation}
                className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-md bg-gradient-tactical text-secondary-foreground font-mono text-sm font-semibold hover:shadow-tactical transition-all"
              >
                <Save className="h-3.5 w-3.5" />
                Salvar Rotação
              </button>
              <button
                onClick={handleReload}
                className="flex items-center gap-2 px-4 py-2.5 rounded-md bg-surface-elevated border border-border font-mono text-xs hover:border-primary/60 hover:text-primary transition-colors"
              >
                <RotateCw className="h-3 w-3" />
                Recarregar
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
