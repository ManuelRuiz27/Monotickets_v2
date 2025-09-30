import { FormEvent, useCallback, useMemo, useState } from 'react';
import { useLocation, useNavigate, type Location } from 'react-router-dom';
import {
  Alert,
  Box,
  Button,
  Container,
  Link,
  Paper,
  Stack,
  TextField,
  Typography,
  CircularProgress,
} from '@mui/material';
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
    roles?: string[];
  };
}

interface FormErrors {
  email?: string;
  password?: string;
}

const Login = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { login, setLoading, loading } = useAuthStore();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenant, setTenant] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<FormErrors>({});

  const from = (location.state as { from?: Location })?.from?.pathname || '/';

  const validate = useCallback((): FormErrors => {
    const nextErrors: FormErrors = {};

    if (!email.trim()) {
      nextErrors.email = 'Ingresa tu correo electrónico.';
    }

    if (!password.trim()) {
      nextErrors.password = 'Ingresa tu contraseña.';
    }

    return nextErrors;
  }, [email, password]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    const validationErrors = validate();
    setErrors(validationErrors);
    if (Object.keys(validationErrors).length > 0) {
      return;
    }
    setLoading(true);

    try {
      const response = await apiFetch<LoginResponse>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
        tenantId: tenant || undefined,
      });

      const resolvedTenantId = response.user.tenantId ?? (tenant || undefined);
      login({ token: response.token, user: { ...response.user, tenantId: resolvedTenantId } });
      navigate(from, { replace: true });
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'No se pudo iniciar sesión';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  const helperText = useMemo(() => ({
    email: errors.email ?? 'Usa tu correo corporativo registrado.',
    password: errors.password ?? 'Tu contraseña es sensible a mayúsculas/minúsculas.',
  }), [errors.email, errors.password]);

  return (
    <Container
      component="main"
      maxWidth="sm"
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        py: 6,
      }}
    >
      <Paper
        elevation={12}
        component="section"
        aria-labelledby="login-title"
        sx={{ width: '100%', p: { xs: 3, md: 5 } }}
      >
        <Stack spacing={3}>
          <Box>
            <Typography id="login-title" variant="h4" component="h1" gutterBottom>
              Bienvenido de vuelta
            </Typography>
            <Typography variant="body1" color="text.secondary">
              Ingresa tus credenciales para administrar tus eventos y reportes.
            </Typography>
          </Box>

          {error && (
            <Alert severity="error" role="alert" aria-live="assertive">
              {error}
            </Alert>
          )}

          <Box component="form" noValidate onSubmit={handleSubmit} aria-describedby="login-helper">
            <Stack spacing={2.5}>
              <TextField
                label="Correo electrónico"
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
                fullWidth
                autoComplete="email"
                error={Boolean(errors.email)}
                helperText={helperText.email}
              />
              <TextField
                label="Contraseña"
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                fullWidth
                autoComplete="current-password"
                error={Boolean(errors.password)}
                helperText={helperText.password}
              />
              <TextField
                label="Tenant (opcional)"
                type="text"
                value={tenant}
                onChange={(event) => setTenant(event.target.value)}
                fullWidth
                placeholder="org-123"
                helperText="Permite forzar el contexto si gestionas múltiples organizaciones."
              />

              <Button
                type="submit"
                variant="contained"
                size="large"
                disabled={loading}
                aria-live="polite"
                sx={{ alignSelf: 'flex-start', minWidth: 180, position: 'relative' }}
              >
                {loading && (
                  <CircularProgress
                    size={20}
                    color="inherit"
                    sx={{ position: 'absolute', left: 16 }}
                    aria-hidden
                  />
                )}
                <Box component="span" sx={{ pl: loading ? 3 : 0 }}>
                  {loading ? 'Ingresando…' : 'Iniciar sesión'}
                </Box>
              </Button>
            </Stack>
          </Box>

          <Typography variant="caption" color="text.secondary" id="login-helper">
            ¿Necesitas ayuda? Contacta al administrador de tu tenant o visita la{' '}
            <Link href="https://monotickets.com/support" target="_blank" rel="noreferrer">
              mesa de soporte
            </Link>
            .
          </Typography>
        </Stack>
      </Paper>
    </Container>
  );
};

export default Login;
