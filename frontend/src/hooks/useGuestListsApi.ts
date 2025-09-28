import { useMemo } from 'react';
import { useQuery, type UseQueryOptions } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface GuestListResource {
  id: string;
  event_id: string;
  name: string;
  description: string | null;
  criteria_json: Record<string, unknown> | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface GuestListsResponse {
  data: GuestListResource[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface GuestListFilters {
  page?: number;
  perPage?: number;
}

function buildQueryString(filters: GuestListFilters): string {
  const params = new URLSearchParams();
  params.set('page', String((filters.page ?? 0) + 1));
  params.set('per_page', String(filters.perPage ?? 10));
  return params.toString();
}

export function useEventGuestLists(
  eventId: string | undefined,
  filters: GuestListFilters,
  options?: UseQueryOptions<GuestListsResponse, unknown, GuestListsResponse, [string, string, string, GuestListFilters]>,
) {
  const queryKey: [string, string, string, GuestListFilters] = useMemo(
    () => ['events', eventId ?? '', 'guest-lists', filters],
    [eventId, filters],
  );

  return useQuery<GuestListsResponse, unknown, GuestListsResponse, [string, string, string, GuestListFilters]>({
    queryKey,
    queryFn: async () => {
      const queryString = buildQueryString(filters);
      return apiFetch<GuestListsResponse>(`/events/${eventId}/guest-lists?${queryString}`);
    },
    enabled: Boolean(eventId),
    keepPreviousData: true,
    ...options,
  });
}
