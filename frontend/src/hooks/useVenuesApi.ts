import { useMemo } from 'react';
import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
  type UseQueryOptions,
} from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface VenueResource {
  id: string;
  event_id: string;
  name: string;
  address: string | null;
  lat: number | null;
  lng: number | null;
  notes: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface VenuesListResponse {
  data: VenueResource[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface VenueSingleResponse {
  data: VenueResource;
}

export interface VenueFilters {
  page?: number;
  perPage?: number;
}

export interface VenuePayload {
  name: string;
  address?: string | null;
  lat?: number | null;
  lng?: number | null;
  notes?: string | null;
}

function buildQueryString(filters: VenueFilters): string {
  const params = new URLSearchParams();
  params.set('page', String((filters.page ?? 0) + 1));
  params.set('per_page', String(filters.perPage ?? 10));
  return params.toString();
}

export function useEventVenues(
  eventId: string | undefined,
  filters: VenueFilters,
  options?: UseQueryOptions<VenuesListResponse, unknown, VenuesListResponse, [string, string, string, VenueFilters]>,
) {
  const queryKey: [string, string, string, VenueFilters] = useMemo(
    () => ['events', eventId ?? '', 'venues', filters],
    [eventId, filters],
  );

  return useQuery<VenuesListResponse, unknown, VenuesListResponse, [string, string, string, VenueFilters]>({
    queryKey,
    queryFn: async () => {
      const queryString = buildQueryString(filters);
      return apiFetch<VenuesListResponse>(`/events/${eventId}/venues?${queryString}`);
    },
    enabled: Boolean(eventId),
    keepPreviousData: true,
    ...options,
  });
}

export function useCreateVenue(
  eventId: string,
  options?: UseMutationOptions<VenueSingleResponse, unknown, VenuePayload>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<VenueSingleResponse, unknown, VenuePayload>({
    mutationFn: async (payload: VenuePayload) =>
      apiFetch<VenueSingleResponse>(`/events/${eventId}/venues`, {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data: VenueSingleResponse, variables: VenuePayload, context: unknown) => {
      void queryClient.invalidateQueries({ queryKey: ['events', eventId, 'venues'] });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

export function useUpdateVenue(
  eventId: string,
  options?: UseMutationOptions<VenueSingleResponse, unknown, { venueId: string; payload: VenuePayload }>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<VenueSingleResponse, unknown, { venueId: string; payload: VenuePayload }>({
    mutationFn: async ({ venueId, payload }: { venueId: string; payload: VenuePayload }) =>
      apiFetch<VenueSingleResponse>(`/events/${eventId}/venues/${venueId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),
    onSuccess: (
      data: VenueSingleResponse,
      variables: { venueId: string; payload: VenuePayload },
      context: unknown,
    ) => {
      void queryClient.invalidateQueries({ queryKey: ['events', eventId, 'venues'] });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

export function useDeleteVenue(
  eventId: string,
  options?: UseMutationOptions<null, unknown, { venueId: string }>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<null, unknown, { venueId: string }>({
    mutationFn: async ({ venueId }: { venueId: string }) =>
      apiFetch<null>(`/events/${eventId}/venues/${venueId}`, {
        method: 'DELETE',
      }),
    onSuccess: (data: null, variables: { venueId: string }, context: unknown) => {
      void queryClient.invalidateQueries({ queryKey: ['events', eventId, 'venues'] });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

