import { useMemo } from 'react';
import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
  type UseQueryOptions,
} from '@tanstack/react-query';
import { apiFetch } from '../api/client';

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
