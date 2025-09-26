import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type Role = 'organizer' | 'superadmin' | 'guest' | string;

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  role: Role;
  tenantId?: string;
}

interface AuthState {
  token: string | null;
  user: AuthUser | null;
  tenantId: string | null;
  loading: boolean;
  login: (payload: { token: string; user: AuthUser }) => void;
  logout: () => void;
  refresh: (payload: { token: string; user?: Partial<AuthUser> }) => void;
  setLoading: (value: boolean) => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      tenantId: null,
      loading: false,
      login: ({ token, user }) => {
        set({
          token,
          user,
          tenantId: user.tenantId ?? null,
          loading: false,
        });
      },
      logout: () => {
        set({ token: null, user: null, tenantId: null, loading: false });
      },
      refresh: ({ token, user }) => {
        const currentUser = get().user;
        const mergedUser = user
          ? ({ ...(currentUser ?? {}), ...user } as AuthUser)
          : currentUser;
        set({
          token,
          user: mergedUser ?? null,
          tenantId: user?.tenantId ?? mergedUser?.tenantId ?? null,
        });
      },
      setLoading: (value: boolean) => set({ loading: value }),
    }),
    {
      name: 'monotickets-auth',
      partialize: (state) => ({ token: state.token, user: state.user, tenantId: state.tenantId }),
    }
  )
);
