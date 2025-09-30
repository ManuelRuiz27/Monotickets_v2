import { fireEvent, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';
import Login from '../Login';
import { renderWithProviders } from '../../test-utils';
import { apiFetch } from '../../api/client';
import { useAuthStore } from '../../auth/store';

vi.mock('../../api/client', () => ({
  apiFetch: vi.fn(),
  ApiError: class extends Error {
    status = 500;
  },
}));

const mockNavigate = vi.fn();

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

describe('Login', () => {
  const initialState = useAuthStore.getState();

  afterEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState(initialState);
  });

  it('muestra mensajes de validación cuando faltan campos', async () => {
    renderWithProviders(<Login />);

    const submitButton = screen.getByRole('button', { name: /iniciar sesión/i });
    await userEvent.click(submitButton);

    expect(await screen.findByText('Ingresa tu correo electrónico.')).toBeInTheDocument();
    expect(screen.getByText('Ingresa tu contraseña.')).toBeInTheDocument();
    expect(apiFetch).not.toHaveBeenCalled();
  });

  it('inicia sesión y actualiza el store cuando las credenciales son válidas', async () => {
    const response = {
      token: 'demo-token',
      user: {
        id: 'user-1',
        name: 'Demo User',
        email: 'demo@example.com',
        role: 'organizer',
      },
    };

    (apiFetch as unknown as vi.Mock).mockResolvedValue(response);

    renderWithProviders(<Login />);

    const emailInput = screen.getByLabelText(/correo electrónico/i);
    const passwordInput = screen.getByLabelText(/contraseña/i);
    const submitButton = screen.getByRole('button', { name: /iniciar sesión/i });

    await userEvent.type(emailInput, 'demo@example.com');
    await userEvent.type(passwordInput, 'super-secret');
    fireEvent.click(submitButton);

    expect(apiFetch).toHaveBeenCalledWith('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email: 'demo@example.com', password: 'super-secret' }),
      tenantId: undefined,
    });

    await screen.findByText('Ingresando…');

    await screen.findByRole('button', { name: /^Iniciar sesión$/i });

    const storeState = useAuthStore.getState();
    expect(storeState.token).toBe('demo-token');
    expect(storeState.user?.email).toBe('demo@example.com');
    expect(mockNavigate).toHaveBeenCalled();
  });
});
