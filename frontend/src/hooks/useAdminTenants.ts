import { useMemo } from 'react';
import { useQuery, useMutation, useQueryClient, type UseQueryOptions } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface AdminTenantUsageSummary {
  event_count: number;
  user_count: number;
  scan_count: number;
}

export interface AdminTenantSubscription {
  id: string;
  status: string;
  current_period_start: string | null;
  current_period_end: string | null;
  trial_end: string | null;
  cancel_at_period_end: boolean;
}

export interface AdminTenantPlan {
  id: string;
  code: string;
  name: string;
  price_cents: number;
  billing_cycle: string;
  limits: Record<string, unknown>;
  features: Record<string, unknown>;
}

export interface AdminTenantSummary {
  id: string;
  name: string | null;
  slug: string | null;
  status: string;
  plan: AdminTenantPlan | null;
  subscription: AdminTenantSubscription | null;
  usage: AdminTenantUsageSummary;
  limits_override: Record<string, number | null>;
  effective_limits: Record<string, unknown>;
  created_at: string | null;
  updated_at: string | null;
}

export interface AdminTenantsResponse {
  data: AdminTenantSummary[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface AdminTenantFilters {
  page?: number;
  perPage?: number;
  status?: string;
  search?: string;
}

type AdminTenantsQueryKey = ['admin', 'tenants', AdminTenantFilters];

const buildQueryString = (filters: AdminTenantFilters): string => {
  const params = new URLSearchParams();

  if (filters.page) {
    params.set('page', String(filters.page));
  }

  if (filters.perPage) {
    params.set('per_page', String(filters.perPage));
  }

  if (filters.status && filters.status !== 'all') {
    params.set('status', filters.status);
  }

  if (filters.search) {
    params.set('search', filters.search);
  }

  return params.toString();
};

export function useAdminTenants(
  filters: AdminTenantFilters,
  options?: UseQueryOptions<AdminTenantsResponse, unknown, AdminTenantsResponse, AdminTenantsQueryKey>,
) {
  const queryKey: AdminTenantsQueryKey = useMemo(() => ['admin', 'tenants', filters], [filters]);

  return useQuery<AdminTenantsResponse, unknown, AdminTenantsResponse, AdminTenantsQueryKey>({
    queryKey,
    queryFn: async () => {
      const qs = buildQueryString(filters);
      const suffix = qs ? `?${qs}` : '';
      return apiFetch<AdminTenantsResponse>(`/admin/tenants${suffix}`);
    },
    keepPreviousData: true,
    ...options,
  });
}

export interface CreateTenantPayload {
  name: string;
  slug: string;
  plan_id: string;
  status?: string;
  trial_days?: number | null;
  limit_overrides?: Record<string, number | null> | null;
}

export interface UpdateTenantPayload {
  name?: string;
  slug?: string;
  status?: string;
  plan_id?: string;
  subscription_status?: string;
  cancel_at_period_end?: boolean;
  trial_end?: string | null;
  limit_overrides?: Record<string, number | null> | null;
}

export interface AdminTenantResponse {
  data: AdminTenantSummary;
}

export function useCreateTenant(options?: { onSuccess?: (tenant: AdminTenantSummary) => void }) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: CreateTenantPayload) => {
      const response = await apiFetch<AdminTenantResponse>('/admin/tenants', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      return response.data;
    },
    onSuccess: (tenant) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] });
      options?.onSuccess?.(tenant);
    },
  });
}

export function useUpdateTenant(
  tenantId: string,
  options?: { onSuccess?: (tenant: AdminTenantSummary) => void },
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: UpdateTenantPayload) => {
      const response = await apiFetch<AdminTenantResponse>(`/admin/tenants/${tenantId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      });
      return response.data;
    },
    onSuccess: (tenant) => {
      void queryClient.invalidateQueries({ queryKey: ['admin', 'tenants'] });
      options?.onSuccess?.(tenant);
    },
  });
}
