import { useMemo } from 'react';
import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
  type UseQueryOptions,
} from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import type { VenuesListResponse, VenueResource } from './useVenuesApi';

export interface CheckpointResource {
  id: string;
  event_id: string;
  venue_id: string;
  name: string;
  description: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface CheckpointsListResponse {
  data: CheckpointResource[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface CheckpointSingleResponse {
  data: CheckpointResource;
}

export interface CheckpointFilters {
  page?: number;
  perPage?: number;
}

export interface CheckpointPayload {
  name: string;
  description?: string | null;
  event_id: string;
  venue_id: string;
}

export interface EventCheckpointResource extends CheckpointResource {
  venue_name?: string | null;
}

function buildQueryString(filters: CheckpointFilters): string {
  const params = new URLSearchParams();
  params.set('page', String((filters.page ?? 0) + 1));
  params.set('per_page', String(filters.perPage ?? 10));
  return params.toString();
}

export function useVenueCheckpoints(
  eventId: string | undefined,
  venueId: string | undefined,
  filters: CheckpointFilters,
  options?: UseQueryOptions<
    CheckpointsListResponse,
    unknown,
    CheckpointsListResponse,
    [string, string, string, string, string, CheckpointFilters]
  >,
) {
  const queryKey: [string, string, string, string, string, CheckpointFilters] = useMemo(
    () => ['events', eventId ?? '', 'venues', venueId ?? '', 'checkpoints', filters],
    [eventId, venueId, filters],
  );

  return useQuery<
    CheckpointsListResponse,
    unknown,
    CheckpointsListResponse,
    [string, string, string, string, string, CheckpointFilters]
  >({
    queryKey,
    queryFn: async () => {
      const queryString = buildQueryString(filters);
      return apiFetch<CheckpointsListResponse>(
        `/events/${eventId}/venues/${venueId}/checkpoints?${queryString}`,
      );
    },
    enabled: Boolean(eventId) && Boolean(venueId),
    keepPreviousData: true,
    ...options,
  });
}

export function useCreateCheckpoint(
  eventId: string,
  venueId: string,
  options?: UseMutationOptions<CheckpointSingleResponse, unknown, CheckpointPayload>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<CheckpointSingleResponse, unknown, CheckpointPayload>({
    mutationFn: async (payload: CheckpointPayload) =>
      apiFetch<CheckpointSingleResponse>(`/events/${eventId}/venues/${venueId}/checkpoints`, {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    onSuccess: (
      data: CheckpointSingleResponse,
      variables: CheckpointPayload,
      context: unknown,
    ) => {
      void queryClient.invalidateQueries({
        queryKey: ['events', eventId, 'venues', venueId, 'checkpoints'],
      });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

export function useUpdateCheckpoint(
  eventId: string,
  venueId: string,
  options?: UseMutationOptions<CheckpointSingleResponse, unknown, { checkpointId: string; payload: CheckpointPayload }>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<
    CheckpointSingleResponse,
    unknown,
    { checkpointId: string; payload: CheckpointPayload }
  >({
    mutationFn: async ({ checkpointId, payload }: { checkpointId: string; payload: CheckpointPayload }) =>
      apiFetch<CheckpointSingleResponse>(
        `/events/${eventId}/venues/${venueId}/checkpoints/${checkpointId}`,
        {
          method: 'PATCH',
          body: JSON.stringify(payload),
        },
      ),
    onSuccess: (
      data: CheckpointSingleResponse,
      variables: { checkpointId: string; payload: CheckpointPayload },
      context: unknown,
    ) => {
      void queryClient.invalidateQueries({
        queryKey: ['events', eventId, 'venues', venueId, 'checkpoints'],
      });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

export function useDeleteCheckpoint(
  eventId: string,
  venueId: string,
  options?: UseMutationOptions<null, unknown, { checkpointId: string }>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<null, unknown, { checkpointId: string }>({
    mutationFn: async ({ checkpointId }: { checkpointId: string }) =>
      apiFetch<null>(`/events/${eventId}/venues/${venueId}/checkpoints/${checkpointId}`, {
        method: 'DELETE',
      }),
    onSuccess: (data: null, variables: { checkpointId: string }, context: unknown) => {
      void queryClient.invalidateQueries({
        queryKey: ['events', eventId, 'venues', venueId, 'checkpoints'],
      });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

export function useEventCheckpoints(
  eventId: string | undefined,
  options?: Omit<
    UseQueryOptions<EventCheckpointResource[], unknown, EventCheckpointResource[], [string, string, string]>,
    'queryKey' | 'queryFn'
  >,
) {
  return useQuery<EventCheckpointResource[], unknown, EventCheckpointResource[], [string, string, string]>({
    queryKey: ['events', eventId ?? '', 'checkpoints', 'all'],
    queryFn: async () => {
      if (!eventId) {
        return [];
      }

      const venues: VenueResource[] = [];

      let venuePage = 1;
      let venueTotalPages = 1;

      while (venuePage <= venueTotalPages) {
        const response = await apiFetch<VenuesListResponse>(
          `/events/${eventId}/venues?page=${venuePage}&per_page=50`,
        );
        venues.push(...(response.data ?? []));
        venueTotalPages = response.meta?.total_pages ?? venueTotalPages;
        venuePage += 1;
        if (!response.meta?.total_pages) {
          break;
        }
      }

      const checkpoints: EventCheckpointResource[] = [];

      for (const venue of venues) {
        let checkpointPage = 1;
        let checkpointTotalPages = 1;

        while (checkpointPage <= checkpointTotalPages) {
          const response = await apiFetch<CheckpointsListResponse>(
            `/events/${eventId}/venues/${venue.id}/checkpoints?page=${checkpointPage}&per_page=50`,
          );

          const venueCheckpoints = (response.data ?? []).map((checkpoint) => ({
            ...checkpoint,
            venue_name: venue.name,
          }));
          checkpoints.push(...venueCheckpoints);

          checkpointTotalPages = response.meta?.total_pages ?? checkpointTotalPages;
          checkpointPage += 1;
          if (!response.meta?.total_pages) {
            break;
          }
        }
      }

      checkpoints.sort((a, b) => {
        const venueA = a.venue_name ?? '';
        const venueB = b.venue_name ?? '';
        if (venueA.localeCompare(venueB) !== 0) {
          return venueA.localeCompare(venueB);
        }
        return (a.name ?? '').localeCompare(b.name ?? '');
      });

      return checkpoints;
    },
    enabled: Boolean(eventId),
    staleTime: 5 * 60 * 1000,
    ...options,
  });
}
