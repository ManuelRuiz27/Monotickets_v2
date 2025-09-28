import { useAuthStore } from '../auth/store';

const API_URL = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? '';

export class ApiError extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

export type ApiOptions = RequestInit & {
  tenantId?: string | null;
};

async function parseJSON<T>(response: Response): Promise<T> {
  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();
  if (!text) {
    return undefined as T;
  }
  try {
    return JSON.parse(text) as T;
  } catch (error) {
    throw new ApiError('Respuesta inválida del servidor', response.status);
  }
}

export function resolveApiUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) {
    return path;
  }
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  return `${API_URL}${normalizedPath}`;
}

export async function apiFetch<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const { token, tenantId: storeTenantId, logout } = useAuthStore.getState();

  const finalTenantId = options.tenantId ?? storeTenantId ?? undefined;
  const headers = new Headers(options.headers ?? {});
  if (!headers.has('Content-Type') && options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }
  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }
  if (finalTenantId) {
    headers.set('X-Tenant-ID', finalTenantId);
  }

  const response = await fetch(resolveApiUrl(path), {
    ...options,
    headers,
  });

  if (response.status === 401 || response.status === 403) {
    logout();
    throw new ApiError('Sesión expirada o sin permisos suficientes', response.status);
  }

  if (!response.ok) {
    const errorMessage = await response.text();
    throw new ApiError(errorMessage || 'Error inesperado en la API', response.status);
  }

  return parseJSON<T>(response);
}
