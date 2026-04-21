import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, ApiError } from '@/lib/api';
import { auth, type AuthUser } from '@/lib/auth';
import { Button } from '@/components/ui/Button';
import { LogIn } from 'lucide-react';

type LoginResponse =
  | { mfa_required: false; token: string; user: AuthUser }
  | { mfa_required: true; pre_token: string; expires_at: string };

export function LoginPage() {
  const [email, setEmail] = useState('fmorell@logisticaargentinasrl.com.ar');
  const [password, setPassword] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setErr(null);
    setLoading(true);
    try {
      const res = await api.post<LoginResponse>('/api/erp/auth/login', { email, password });
      if (res.mfa_required) {
        setErr('MFA requerido — flujo pendiente de implementación.');
        return;
      }
      auth.setToken(res.token);
      auth.setUser(res.user);
      navigate('/erp/dashboard');
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Error de conexión');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-navy-900 p-6">
      <div className="w-full max-w-md bg-white rounded-xl shadow-2xl p-8">
        <div className="flex items-center gap-3 mb-6">
          <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-azure to-navy-600 flex items-center justify-center text-white font-bold shadow-brand">
            LA
          </div>
          <div>
            <div className="text-[15px] font-semibold text-navy-800">ERP Logística Argentina</div>
            <div className="text-[11px] text-ink-muted uppercase tracking-wider">Sesión</div>
          </div>
        </div>

        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Email
            </label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full px-3 py-2 text-[13px] border border-line-strong rounded-md focus:outline-2 focus:outline-azure"
              required
              autoFocus
            />
          </div>
          <div>
            <label className="block text-[11px] font-semibold text-ink-muted uppercase tracking-wider mb-1">
              Contraseña
            </label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-3 py-2 text-[13px] border border-line-strong rounded-md focus:outline-2 focus:outline-azure"
              required
            />
          </div>
          {err && (
            <div className="px-3 py-2 bg-danger-bg text-danger text-[12px] rounded-md border border-danger/20">
              {err}
            </div>
          )}
          <Button type="submit" variant="primary" className="w-full justify-center" disabled={loading}>
            <LogIn className="w-3 h-3" />
            {loading ? 'Ingresando…' : 'Ingresar'}
          </Button>
        </form>

        <div className="mt-6 text-[11px] text-ink-muted text-center">
          Entorno de desarrollo · MariaDB local · v0.3.0
        </div>
      </div>
    </div>
  );
}
