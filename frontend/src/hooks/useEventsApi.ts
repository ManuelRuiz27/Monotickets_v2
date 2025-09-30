import { useMemo } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient, type UseMutationOptions } from '@tanstack/react-query';
import { apiFetch } from '../api/client';
import type { AppQueryOptions } from './queryTypes';

export type EventStatus = 'draft' | 'published' | 'archived';
export type CheckinPolicy = 'single' | 'multiple';

export interface EventResource {
  id: string;
  tenant_id: string;
  organizer_user_id: string;
  code: string;
  name: string;
  description: string | null;
  start_at: string | null;
  end_at: string | null;
  timezone: string;
  status: EventStatus;
  capacity: number | null;
  checkin_policy: CheckinPolicy;
  settings_json: Record<string, unknown> | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface EventsListResponse {
  data: EventResource[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
}

export interface EventSingleResponse {
  data: EventResource;
}

export interface EventFilters {
  page?: number;
  perPage?: number;
  search?: string;
  status?: EventStatus[];
  from?: string | null;
  to?: string | null;
}

export interface BaseEventPayload {
  organizer_user_id: string;
  code: string;
  name: string;
  description?: string | null;
  start_at: string;
  end_at: string;
  timezone: string;
  status: EventStatus;
  capacity?: number | null;
  checkin_policy: CheckinPolicy;
  settings_json?: Record<string, unknown> | null;
}

export interface CreateEventPayload extends BaseEventPayload {
  tenant_id?: string | null;
}

export type UpdateEventPayload = Partial<CreateEventPayload>;

export interface UseEventsListResult {
  data?: EventsListResponse;
  isLoading: boolean;
  isError: boolean;
  error: unknown;
  refetch: () => Promise<EventsListResponse | undefined>;
}

function buildQueryString(filters: EventFilters): string {
  const params = new URLSearchParams();

  params.set('page', String((filters.page ?? 0) + 1));
  params.set('per_page', String(filters.perPage ?? 10));

  if (filters.search?.trim()) {
    params.set('search', filters.search.trim());
  }

  if (filters.status && filters.status.length > 0) {
    params.set('status', filters.status.join(','));
  }

  if (filters.from) {
    params.set('from', filters.from);
  }

  if (filters.to) {
    params.set('to', filters.to);
  }

  return params.toString();
}

export function useEventsList(
  filters: EventFilters,
  options?: AppQueryOptions<EventsListResponse, EventsListResponse, [string, EventFilters]>,
): UseEventsListResult {
  const queryKey: [string, EventFilters] = useMemo(() => ['events', filters], [filters]);

  const query = useQuery<EventsListResponse, unknown, EventsListResponse, [string, EventFilters]>({
    queryKey,
    queryFn: async () => {
      const queryString = buildQueryString(filters);
      return apiFetch<EventsListResponse>(`/events?${queryString}`);
    },
    placeholderData: options?.placeholderData ?? keepPreviousData,
    ...options,
  });

  return {
    data: query.data,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    refetch: async () => {
      const result = await query.refetch();
      return result.data;
    },
  };
}

export function useEvent(
  eventId: string | undefined,
  options?: AppQueryOptions<EventSingleResponse, EventSingleResponse, [string, string]>,
) {
  return useQuery<EventSingleResponse, unknown, EventSingleResponse, [string, string]>({
    queryKey: ['events', eventId ?? ''],
    queryFn: async () => apiFetch<EventSingleResponse>(`/events/${eventId}`),
    enabled: Boolean(eventId),
    ...options,
  });
}

export function useCreateEvent(options?: UseMutationOptions<EventSingleResponse, unknown, CreateEventPayload>) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<EventSingleResponse, unknown, CreateEventPayload>({
    mutationFn: async (payload: CreateEventPayload) =>
      apiFetch<EventSingleResponse>('/events', {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data: EventSingleResponse, variables: CreateEventPayload, context: unknown) => {
      void queryClient.invalidateQueries({ queryKey: ['events'] });
      onSuccess?.(data, variables, context, undefined as never);
    },
    ...restOptions,
  });
}

export function useUpdateEvent(
  eventId: string,
  options?: UseMutationOptions<EventSingleResponse, unknown, UpdateEventPayload>
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<EventSingleResponse, unknown, UpdateEventPayload>({
    mutationFn: async (payload: UpdateEventPayload) =>
      apiFetch<EventSingleResponse>(`/events/${eventId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data: EventSingleResponse, variables: UpdateEventPayload, context: unknown) => {
      void queryClient.invalidateQueries({ queryKey: ['events'] });
      void queryClient.invalidateQueries({ queryKey: ['events', eventId] });
      onSuccess?.(data, variables, context, undefined as never);
    },
    ...restOptions,
  });
}

export function useArchiveEvent(
  options?: UseMutationOptions<EventSingleResponse, unknown, { eventId: string }>
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<EventSingleResponse, unknown, { eventId: string }>({
    mutationFn: async ({ eventId }: { eventId: string }) =>
      apiFetch<EventSingleResponse>(`/events/${eventId}`, {
        method: 'PATCH',
        body: JSON.stringify({ status: 'archived' as EventStatus }),
      }),
    onSuccess: (data: EventSingleResponse, variables: { eventId: string }, context: unknown) => {
      void queryClient.invalidateQueries({ queryKey: ['events'] });
      if (data?.data?.id) {
        void queryClient.invalidateQueries({ queryKey: ['events', data.data.id] });
      }
      onSuccess?.(data, variables, context, undefined as never);
    },
    ...restOptions,
  });
}

export function useDeleteEvent(options?: UseMutationOptions<null, unknown, { eventId: string }>) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<null, unknown, { eventId: string }>({
    mutationFn: async ({ eventId }: { eventId: string }) =>
      apiFetch<null>(`/events/${eventId}`, {
        method: 'DELETE',
      }),
    onSuccess: (data: null, variables: { eventId: string }, context: unknown) => {
      void queryClient.invalidateQueries({ queryKey: ['events'] });
      onSuccess?.(data, variables, context, undefined as never);
    },
    ...restOptions,
  });
}

export const EVENT_STATUS_LABELS: Record<EventStatus, string> = {
  draft: 'Borrador',
  published: 'Publicado',
  archived: 'Archivado',
};

export const CHECKIN_POLICY_LABELS: Record<CheckinPolicy, string> = {
  single: 'Un solo ingreso',
  multiple: 'Múltiples ingresos',
};
