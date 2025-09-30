import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import type { AppQueryOptions } from './queryTypes';

export interface EventAnalyticsFilters {
  from?: string | null;
  to?: string | null;
  hourPage?: number;
  hourPerPage?: number;
  checkpointPage?: number;
  checkpointPerPage?: number;
}

export interface AnalyticsPaginationMeta {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface AnalyticsHourlyEntry {
  hour: string | null;
  valid: number;
  duplicate: number;
  unique: number;
}

export interface AnalyticsCheckpointEntry {
  checkpoint_id: string | null;
  name: string | null;
  valid: number;
  duplicate: number;
  invalid: number;
}

export interface AnalyticsDuplicateEntry {
  ticket_id: string | null;
  qr_code: string | null;
  guest_name: string | null;
  occurrences: number;
  last_scanned_at: string | null;
}

export interface AnalyticsErrorEntry {
  ticket_id: string | null;
  result: string;
  qr_code: string | null;
  guest_name: string | null;
  occurrences: number;
  last_scanned_at: string | null;
}

export interface PaginatedAnalytics<T> {
  data: T[];
  meta: AnalyticsPaginationMeta;
}

export interface EventAnalyticsResponse {
  data: {
    hourly: PaginatedAnalytics<AnalyticsHourlyEntry>;
    checkpoints: PaginatedAnalytics<AnalyticsCheckpointEntry> & {
      totals: {
        valid: number;
        duplicate: number;
        invalid: number;
      };
    };
    duplicates: PaginatedAnalytics<AnalyticsDuplicateEntry>;
    errors: PaginatedAnalytics<AnalyticsErrorEntry>;
  };
}

type EventAnalyticsQueryKey = [string, string, EventAnalyticsFilters];

const buildQueryString = (filters: EventAnalyticsFilters): string => {
  const params = new URLSearchParams();

  if (filters.from) {
    params.set('from', filters.from);
  }

  if (filters.to) {
    params.set('to', filters.to);
  }

  if (filters.hourPage) {
    params.set('hour_page', String(filters.hourPage));
  }

  if (filters.hourPerPage) {
    params.set('hour_per_page', String(filters.hourPerPage));
  }

  if (filters.checkpointPage) {
    params.set('checkpoint_page', String(filters.checkpointPage));
  }

  if (filters.checkpointPerPage) {
    params.set('checkpoint_per_page', String(filters.checkpointPerPage));
  }

  return params.toString();
};

export const useEventAnalytics = (
  eventId: string | undefined,
  filters: EventAnalyticsFilters = {},
  options?: AppQueryOptions<EventAnalyticsResponse, EventAnalyticsResponse, EventAnalyticsQueryKey>,
) => {
  const queryKey: EventAnalyticsQueryKey = useMemo(
    () => ['events-analytics', eventId ?? '', filters],
    [eventId, filters],
  );

  return useQuery<EventAnalyticsResponse, unknown, EventAnalyticsResponse, EventAnalyticsQueryKey>({
    queryKey,
    enabled: Boolean(eventId),
    queryFn: async () => {
      const queryString = buildQueryString(filters);
      const suffix = queryString ? `?${queryString}` : '';
      return apiFetch<EventAnalyticsResponse>(`/events/${eventId}/analytics${suffix}`);
    },
    ...options,
  });
};
