import { useMemo } from 'react';
import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
  type UseQueryOptions,
} from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export type TicketStatus = 'issued' | 'used' | 'revoked' | 'expired';

export type TicketType = 'general' | 'vip' | 'staff';

export interface TicketResource {
  id: string;
  event_id: string;
  guest_id: string;
  type: TicketType;
  price_cents: number;
  status: TicketStatus;
  seat_section: string | null;
  seat_row: string | null;
  seat_code: string | null;
  issued_at: string | null;
  expires_at: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface TicketListResponse {
  data: TicketResource[];
}

export interface TicketSingleResponse {
  data: TicketResource;
}

export interface TicketPayload {
  type?: TicketType;
  price_cents?: number | null;
  seat_section?: string | null;
  seat_row?: string | null;
  seat_code?: string | null;
  expires_at?: string | null;
  status?: TicketStatus;
}

export function useGuestTickets(
  guestId: string | undefined,
  options?: UseQueryOptions<TicketListResponse, unknown, TicketListResponse, [string, string, string]>,
) {
  const queryKey: [string, string, string] = useMemo(
    () => ['guests', guestId ?? '', 'tickets'],
    [guestId],
  );

  return useQuery<TicketListResponse, unknown, TicketListResponse, [string, string, string]>({
    queryKey,
    queryFn: async () => apiFetch<TicketListResponse>(`/guests/${guestId}/tickets`),
    enabled: Boolean(guestId),
    ...options,
  });
}

export function useIssueTicket(
  guestId: string,
  options?: UseMutationOptions<TicketSingleResponse, unknown, TicketPayload>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...rest } = options ?? {};

  return useMutation<TicketSingleResponse, unknown, TicketPayload>({
    mutationFn: async (payload: TicketPayload) =>
      apiFetch<TicketSingleResponse>(`/guests/${guestId}/tickets`, {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data, variables, context) => {
      void queryClient.invalidateQueries({ queryKey: ['guests', guestId, 'tickets'] });
      onSuccess?.(data, variables, context);
    },
    ...rest,
  });
}

export function useUpdateTicket(
  guestId: string,
  options?: UseMutationOptions<TicketSingleResponse, unknown, { ticketId: string; payload: TicketPayload }>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...rest } = options ?? {};

  return useMutation<TicketSingleResponse, unknown, { ticketId: string; payload: TicketPayload }>({
    mutationFn: async ({ ticketId, payload }) =>
      apiFetch<TicketSingleResponse>(`/tickets/${ticketId}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data, variables, context) => {
      void queryClient.invalidateQueries({ queryKey: ['guests', guestId, 'tickets'] });
      onSuccess?.(data, variables, context);
    },
    ...rest,
  });
}

export function useDeleteTicket(
  guestId: string,
  options?: UseMutationOptions<void, unknown, { ticketId: string }>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...rest } = options ?? {};

  return useMutation<void, unknown, { ticketId: string }>({
    mutationFn: async ({ ticketId }) =>
      apiFetch<void>(`/tickets/${ticketId}`, {
        method: 'DELETE',
      }),
    onSuccess: (data, variables, context) => {
      void queryClient.invalidateQueries({ queryKey: ['guests', guestId, 'tickets'] });
      onSuccess?.(data, variables, context);
    },
    ...rest,
  });
}

