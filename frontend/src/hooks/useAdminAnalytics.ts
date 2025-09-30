import { useMemo } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import type { AppQueryOptions } from './queryTypes';

export interface AdminAnalyticsFilters {
  tenantId?: string | null;
  from?: string | null;
  to?: string | null;
}

export interface AdminAnalyticsOverview {
  invited: number;
  confirmed: number;
  attendances: number;
  duplicates: number;
  unique_attendees: number;
  occupancy_rate: number | null;
}

export interface AdminAnalyticsAttendanceEntry {
  hour: string | null;
  valid: number;
  duplicate: number;
  unique: number;
}

export interface AdminAnalyticsEventInfo {
  id: string;
  tenant_id: string | null;
  name: string;
  start_at: string | null;
  end_at: string | null;
  timezone: string | null;
  status: string;
}

export interface AdminAnalyticsCard {
  event: AdminAnalyticsEventInfo;
  overview: AdminAnalyticsOverview;
  attendance: AdminAnalyticsAttendanceEntry[];
}

export interface AdminAnalyticsTenant {
  id: string;
  name: string | null;
  slug: string | null;
}

export interface AdminAnalyticsResponse {
  data: AdminAnalyticsCard[];
  meta: {
    tenants: AdminAnalyticsTenant[];
  };
}

type AdminAnalyticsQueryKey = [string, AdminAnalyticsFilters];

function buildQueryString(filters: AdminAnalyticsFilters): string {
  const params = new URLSearchParams();

  if (filters.tenantId) {
    params.set('tenant_id', filters.tenantId);
  }

  if (filters.from) {
    params.set('from', filters.from);
  }

  if (filters.to) {
    params.set('to', filters.to);
  }

  return params.toString();
}

export function useAdminAnalytics(
  filters: AdminAnalyticsFilters,
  options?: AppQueryOptions<AdminAnalyticsResponse, AdminAnalyticsResponse, AdminAnalyticsQueryKey>,
) {
  const queryKey: AdminAnalyticsQueryKey = useMemo(() => ['admin-analytics', filters], [filters]);

  return useQuery<AdminAnalyticsResponse, unknown, AdminAnalyticsResponse, AdminAnalyticsQueryKey>({
    queryKey,
    queryFn: async () => {
      const queryString = buildQueryString(filters);
      const suffix = queryString ? `?${queryString}` : '';
      return apiFetch<AdminAnalyticsResponse>(`/admin/analytics${suffix}`);
    },
    placeholderData: options?.placeholderData ?? keepPreviousData,
    ...options,
  });
}
