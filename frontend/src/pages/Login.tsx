import { FormEvent, useState } from 'react';
import { useLocation, useNavigate, type Location } from 'react-router-dom';
import { apiFetch, ApiError } from '../api/client';
import { useAuthStore } from '../auth/store';

interface LoginResponse {
  token: string;
  user: {
    id: string;
    name: string;
    email: string;
    role: 'organizer' | 'superadmin' | string;
    tenantId?: string;
  };
}

const Login = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { login, setLoading, loading } = useAuthStore();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenant, setTenant] = useState('');
  const [error, setError] = useState<string | null>(null);

  const from = (location.state as { from?: Location })?.from?.pathname || '/';

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const response = await apiFetch<LoginResponse>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
        tenantId: tenant || undefined,
      });

      login({ token: response.token, user: { ...response.user, tenantId: response.user.tenantId ?? tenant || undefined } });
      navigate(from, { replace: true });
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'No se pudo iniciar sesión';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ display: 'grid', placeItems: 'center', minHeight: '100vh' }}>
      <div style={{ background: 'white', padding: '2.5rem', borderRadius: '0.75rem', boxShadow: '0 15px 35px rgba(15, 23, 42, 0.1)' }}>
        <h1 style={{ marginTop: 0 }}>Bienvenido</h1>
        <p style={{ marginBottom: '1.5rem', color: '#475569' }}>Accede para continuar con la administración.</p>
        {error && <div className="alert">{error}</div>}
        <form onSubmit={handleSubmit}>
          <label>
            Correo electrónico
            <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
          </label>
          <label>
            Contraseña
            <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} required />
          </label>
          <label>
            Tenant (opcional)
            <input type="text" value={tenant} onChange={(event) => setTenant(event.target.value)} placeholder="org-123" />
          </label>
          <button type="submit" disabled={loading}>
            {loading ? 'Ingresando…' : 'Iniciar sesión'}
          </button>
        </form>
      </div>
    </div>
  );
};

export default Login;
