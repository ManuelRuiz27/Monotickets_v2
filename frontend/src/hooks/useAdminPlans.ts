import { useQuery, type UseQueryOptions } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface AdminPlan {
  id: string;
  code: string;
  name: string;
  price_cents: number;
  billing_cycle: string;
  limits: Record<string, unknown>;
  features: Record<string, unknown>;
}

export interface AdminPlansResponse {
  data: AdminPlan[];
}

type AdminPlansQueryKey = ['admin', 'plans'];

export function useAdminPlans(
  options?: UseQueryOptions<AdminPlansResponse, unknown, AdminPlansResponse, AdminPlansQueryKey>,
) {
  const queryKey: AdminPlansQueryKey = ['admin', 'plans'];

  return useQuery<AdminPlansResponse, unknown, AdminPlansResponse, AdminPlansQueryKey>({
    queryKey,
    queryFn: async () => apiFetch<AdminPlansResponse>('/admin/plans'),
    staleTime: 5 * 60 * 1000,
    ...options,
  });
}
