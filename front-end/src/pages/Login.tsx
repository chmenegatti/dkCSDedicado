import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { Gamepad2 } from "lucide-react";
import { api, setPassword, isAuthenticated } from "@/lib/api";

export default function Login() {
  const [pw, setPw] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    if (isAuthenticated()) navigate("/", { replace: true });
  }, [navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!pw.trim()) return;
    setLoading(true);
    setError("");
    setPassword(pw);
    const ok = await api.checkAuth();
    if (ok) {
      navigate("/", { replace: true });
    } else {
      setPassword("");
      setError("Senha incorreta.");
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen relative flex items-center justify-center">
      <div className="absolute inset-0 grid-bg pointer-events-none" />
      <div className="absolute inset-x-0 top-0 h-[400px] bg-gradient-glow pointer-events-none" />

      <div className="relative w-full max-w-sm px-4 animate-slide-up">
        <div className="panel p-8 space-y-6">
          {/* Logo */}
          <div className="flex flex-col items-center gap-3">
            <div className="relative">
              <div className="absolute inset-0 bg-gradient-primary blur-md opacity-60" />
              <div className="relative h-12 w-12 rounded-xl bg-gradient-primary grid place-items-center">
                <Gamepad2 className="h-6 w-6 text-primary-foreground" strokeWidth={2.5} />
              </div>
            </div>
            <div className="text-center">
              <h1 className="font-mono text-xl font-bold tracking-tight">
                CS <span className="text-primary text-glow-primary">1.6</span> Admin
              </h1>
              <p className="text-[11px] font-mono uppercase tracking-[0.2em] text-muted-foreground mt-0.5">
                Counter-Strike Server Panel
              </p>
            </div>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <label className="font-mono text-xs uppercase tracking-wider text-muted-foreground">
                Senha do Painel
              </label>
              <input
                type="password"
                value={pw}
                onChange={(e) => setPw(e.target.value)}
                autoFocus
                placeholder="••••••••"
                className="w-full px-4 py-3 rounded-lg bg-background border border-border font-mono text-sm placeholder:text-muted-foreground/40 focus:outline-none focus:border-primary/60 focus:ring-1 focus:ring-primary/40 transition-colors"
              />
            </div>

            {error && (
              <p className="font-mono text-xs text-destructive">{error}</p>
            )}

            <button
              type="submit"
              disabled={loading || !pw.trim()}
              className="w-full py-3 rounded-lg bg-gradient-primary text-primary-foreground font-mono text-sm font-semibold hover:shadow-glow transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? "Verificando..." : "Entrar"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
