import { useMemo } from 'react';
import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
  type UseQueryOptions,
} from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export type RsvpStatus = 'none' | 'invited' | 'confirmed' | 'declined';

export const RSVP_STATUS_LABELS: Record<RsvpStatus, string> = {
  none: 'Sin respuesta',
  invited: 'Invitado',
  confirmed: 'Confirmado',
  declined: 'Rechazado',
};

export interface GuestResource {
  id: string;
  event_id: string;
  guest_list_id: string | null;
  full_name: string;
  email: string | null;
  phone: string | null;
  organization: string | null;
  rsvp_status: RsvpStatus | null;
  rsvp_at: string | null;
  allow_plus_ones: boolean;
  plus_ones_limit: number | null;
  custom_fields_json: Record<string, unknown> | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface GuestsListResponse {
  data: GuestResource[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface GuestSingleResponse {
  data: GuestResource;
}

export interface GuestFilters {
  page?: number;
  perPage?: number;
  search?: string;
  rsvpStatus?: RsvpStatus[];
  guestListId?: string | null;
}

export interface GuestPayload {
  full_name: string;
  email?: string | null;
  phone?: string | null;
  organization?: string | null;
  rsvp_status?: RsvpStatus | null;
  rsvp_at?: string | null;
  allow_plus_ones?: boolean;
  plus_ones_limit?: number | null;
  custom_fields_json?: Record<string, unknown> | null;
  guest_list_id?: string | null;
}

function buildQueryString(filters: GuestFilters): string {
  const params = new URLSearchParams();

  params.set('page', String((filters.page ?? 0) + 1));
  params.set('per_page', String(filters.perPage ?? 10));

  if (filters.search && filters.search.trim() !== '') {
    params.set('search', filters.search.trim());
  }

  if (filters.rsvpStatus && filters.rsvpStatus.length > 0) {
    filters.rsvpStatus.forEach((status) => {
      params.append('rsvp_status[]', status);
    });
  }

  if (filters.guestListId !== undefined) {
    if (filters.guestListId === null) {
      params.set('list', '');
    } else if (filters.guestListId.trim() !== '') {
      params.set('list', filters.guestListId);
    } else {
      params.set('list', '');
    }
  }

  return params.toString();
}

export function useEventGuests(
  eventId: string | undefined,
  filters: GuestFilters,
  options?: UseQueryOptions<GuestsListResponse, unknown, GuestsListResponse, [string, string, string, GuestFilters]>,
) {
  const queryKey: [string, string, string, GuestFilters] = useMemo(
    () => ['events', eventId ?? '', 'guests', filters],
    [eventId, filters],
  );

  return useQuery<GuestsListResponse, unknown, GuestsListResponse, [string, string, string, GuestFilters]>({
    queryKey,
    queryFn: async () => {
      const queryString = buildQueryString(filters);
      return apiFetch<GuestsListResponse>(`/events/${eventId}/guests?${queryString}`);
    },
    enabled: Boolean(eventId),
    keepPreviousData: true,
    ...options,
  });
}

export function useGuest(
  guestId: string | undefined,
  options?: UseQueryOptions<GuestSingleResponse, unknown, GuestSingleResponse, [string, string]>,
) {
  return useQuery<GuestSingleResponse, unknown, GuestSingleResponse, [string, string]>({
    queryKey: ['guests', guestId ?? ''],
    queryFn: async () => apiFetch<GuestSingleResponse>(`/guests/${guestId}`),
    enabled: Boolean(guestId),
    ...options,
  });
}

export function useCreateGuest(
  eventId: string,
  options?: UseMutationOptions<GuestSingleResponse, unknown, GuestPayload>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<GuestSingleResponse, unknown, GuestPayload>({
    mutationFn: async (payload: GuestPayload) =>
      apiFetch<GuestSingleResponse>(`/events/${eventId}/guests`, {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data, variables, context) => {
      void queryClient.invalidateQueries({ queryKey: ['events', eventId, 'guests'] });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

export function useUpdateGuest(
  eventId: string,
  options?: UseMutationOptions<GuestSingleResponse, unknown, { guestId: string; payload: GuestPayload }>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<GuestSingleResponse, unknown, { guestId: string; payload: GuestPayload }>({
    mutationFn: async ({ guestId, payload }) =>
      apiFetch<GuestSingleResponse>(`/guests/${guestId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data, variables, context) => {
      void queryClient.invalidateQueries({ queryKey: ['events', eventId, 'guests'] });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}
