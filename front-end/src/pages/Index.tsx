import { Gamepad2, LogOut, Server, Map, Bot, Settings } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import ServerTab from "@/components/admin/ServerTab";
import MapsTab from "@/components/admin/MapsTab";
import BotsTab from "@/components/admin/BotsTab";
import ConfigTab from "@/components/admin/ConfigTab";
import { api, clearPassword } from "@/lib/api";

const tabs = [
  { id: "server", label: "Servidor", icon: Server },
  { id: "maps", label: "Mapas", icon: Map },
  { id: "bots", label: "Bots", icon: Bot },
  { id: "config", label: "Configurações", icon: Settings },
] as const;

const Index = () => {
  const [active, setActive] = useState<(typeof tabs)[number]["id"]>("server");
  const navigate = useNavigate();

  const { data: status } = useQuery({
    queryKey: ['status'],
    queryFn: api.status,
    refetchInterval: 10000,
  });

  const handleLogout = () => {
    clearPassword();
    navigate("/login");
  };

  return (
    <div className="min-h-screen relative">
      <div className="absolute inset-0 grid-bg pointer-events-none" />
      <div className="absolute inset-x-0 top-0 h-[400px] bg-gradient-glow pointer-events-none" />

      <div className="relative">
        {/* Header */}
        <header className="border-b border-border/60 backdrop-blur-xl bg-background/40 sticky top-0 z-30">
          <div className="max-w-[1400px] mx-auto px-6 h-16 flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="relative">
                <div className="absolute inset-0 bg-gradient-primary blur-md opacity-60" />
                <div className="relative h-9 w-9 rounded-lg bg-gradient-primary grid place-items-center">
                  <Gamepad2 className="h-5 w-5 text-primary-foreground" strokeWidth={2.5} />
                </div>
              </div>
              <div>
                <h1 className="font-mono text-lg font-bold tracking-tight">
                  CS <span className="text-primary text-glow-primary">1.6</span> Admin
                </h1>
                <p className="text-[10px] font-mono uppercase tracking-[0.2em] text-muted-foreground -mt-0.5">
                  Counter-Strike Server Panel
                </p>
              </div>
            </div>

            <div className="flex items-center gap-4">
              <div className="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-md bg-surface border border-border/60">
                <span className={`pulse-dot ${status?.online ? 'text-status-online' : 'text-status-offline'}`} />
                <span className="font-mono text-xs text-muted-foreground">
                  {status?.name ?? 'Counter-Strike Server Panel'}
                </span>
              </div>
              <button
                onClick={handleLogout}
                className="group flex items-center gap-2 px-3 py-1.5 rounded-md hover:bg-destructive/10 text-muted-foreground hover:text-destructive transition-colors text-sm font-mono"
              >
                <LogOut className="h-4 w-4" />
                sair
              </button>
            </div>
          </div>

          {/* Tabs */}
          <nav className="max-w-[1400px] mx-auto px-6 flex items-center gap-1">
            {tabs.map((t) => {
              const Icon = t.icon;
              const isActive = active === t.id;
              return (
                <button
                  key={t.id}
                  onClick={() => setActive(t.id)}
                  className={`relative flex items-center gap-2 px-4 py-3 text-sm font-mono transition-colors ${
                    isActive ? "text-primary" : "text-muted-foreground hover:text-foreground"
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  {t.label}
                  {isActive && (
                    <span className="absolute inset-x-2 -bottom-px h-0.5 bg-gradient-primary rounded-full shadow-glow" />
                  )}
                </button>
              );
            })}
          </nav>
        </header>

        <main className="max-w-[1400px] mx-auto px-6 py-6 animate-slide-up" key={active}>
          {active === "server" && <ServerTab />}
          {active === "maps" && <MapsTab />}
          {active === "bots" && <BotsTab />}
          {active === "config" && <ConfigTab />}
        </main>
      </div>
    </div>
  );
};

export default Index;
