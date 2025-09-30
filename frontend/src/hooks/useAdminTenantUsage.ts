import { useMemo } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import type { AdminTenantSummary } from './useAdminTenants';
import type { AppQueryOptions } from './queryTypes';

export interface AdminTenantUsageBreakdownEntry {
  event_id: string;
  event_name: string | null;
  value: number;
}

export interface AdminTenantUsageEntry {
  period_start: string;
  period_end: string;
  event_count: number;
  user_count: number;
  scan_total: number;
  scan_breakdown: AdminTenantUsageBreakdownEntry[];
}

export interface AdminTenantUsageResponse {
  data: AdminTenantUsageEntry[];
  meta: {
    tenant: AdminTenantSummary;
    requested_period: {
      from: string;
      to: string;
    };
  };
}

export interface AdminTenantUsageFilters {
  from?: string;
  to?: string;
}

type AdminTenantUsageKey = ['admin', 'tenants', string, 'usage', AdminTenantUsageFilters];

const buildQueryString = (filters: AdminTenantUsageFilters): string => {
  const params = new URLSearchParams();

  if (filters.from) {
    params.set('from', filters.from);
  }

  if (filters.to) {
    params.set('to', filters.to);
  }

  return params.toString();
};

export function useAdminTenantUsage(
  tenantId: string | undefined,
  filters: AdminTenantUsageFilters,
  options?: AppQueryOptions<AdminTenantUsageResponse, AdminTenantUsageResponse, AdminTenantUsageKey>,
) {
  const queryKey: AdminTenantUsageKey = useMemo(
    () => ['admin', 'tenants', tenantId ?? 'unknown', 'usage', filters],
    [tenantId, filters],
  );

  return useQuery<AdminTenantUsageResponse, unknown, AdminTenantUsageResponse, AdminTenantUsageKey>({
    queryKey,
    enabled: Boolean(tenantId),
    queryFn: async () => {
      if (!tenantId) {
        throw new Error('tenantId is required');
      }

      const qs = buildQueryString(filters);
      const suffix = qs ? `?${qs}` : '';
      return apiFetch<AdminTenantUsageResponse>(`/admin/tenants/${tenantId}/usage${suffix}`);
    },
    placeholderData: options?.placeholderData ?? keepPreviousData,
    ...options,
  });
}
