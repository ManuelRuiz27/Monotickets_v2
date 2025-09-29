import { useMemo } from 'react';
import { useQuery, type UseQueryOptions } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface DashboardFilters {
  from?: string | null;
  to?: string | null;
}

export interface DashboardOverviewResponse {
  data: {
    invited: number;
    confirmed: number;
    attendances: number;
    duplicates: number;
    unique_attendees: number;
    occupancy_rate: number | null;
  };
}

export interface AttendanceByHourEntry {
  hour: string | null;
  valid: number;
  duplicate: number;
  unique: number;
}

export interface AttendanceByHourResponse {
  data: AttendanceByHourEntry[];
}

export interface CheckpointTotalsEntry {
  checkpoint_id: string | null;
  name: string | null;
  valid: number;
  duplicate: number;
}

export interface CheckpointTotalsResponse {
  data: CheckpointTotalsEntry[];
}

export interface RsvpFunnelResponse {
  data: {
    invited: number;
    confirmed: number;
    declined: number;
  };
}

export interface GuestsByListEntry {
  list: string | null;
  count: number;
}

export interface GuestsByListResponse {
  data: GuestsByListEntry[];
}

function buildDashboardQueryString(filters: DashboardFilters): string {
  const params = new URLSearchParams();

  if (filters.from) {
    params.set('from', filters.from);
  }

  if (filters.to) {
    params.set('to', filters.to);
  }

  return params.toString();
}

export function useEventDashboardOverview(
  eventId: string | undefined,
  filters: DashboardFilters,
  options?: UseQueryOptions<DashboardOverviewResponse, unknown, DashboardOverviewResponse, [string, string, string, DashboardFilters]>,
) {
  const queryKey: [string, string, string, DashboardFilters] = useMemo(
    () => ['events', eventId ?? '', 'dashboard-overview', filters],
    [eventId, filters],
  );

  return useQuery<DashboardOverviewResponse, unknown, DashboardOverviewResponse, [string, string, string, DashboardFilters]>({
    queryKey,
    queryFn: async () => {
      const queryString = buildDashboardQueryString(filters);
      const suffix = queryString ? `?${queryString}` : '';
      return apiFetch<DashboardOverviewResponse>(`/events/${eventId}/dashboard/overview${suffix}`);
    },
    enabled: Boolean(eventId),
    ...options,
  });
}

export function useEventDashboardAttendanceByHour(
  eventId: string | undefined,
  filters: DashboardFilters,
  options?: UseQueryOptions<AttendanceByHourResponse, unknown, AttendanceByHourResponse, [string, string, string, DashboardFilters]>,
) {
  const queryKey: [string, string, string, DashboardFilters] = useMemo(
    () => ['events', eventId ?? '', 'dashboard-attendance-by-hour', filters],
    [eventId, filters],
  );

  return useQuery<AttendanceByHourResponse, unknown, AttendanceByHourResponse, [string, string, string, DashboardFilters]>({
    queryKey,
    queryFn: async () => {
      const queryString = buildDashboardQueryString(filters);
      const suffix = queryString ? `?${queryString}` : '';
      return apiFetch<AttendanceByHourResponse>(`/events/${eventId}/dashboard/attendance-by-hour${suffix}`);
    },
    enabled: Boolean(eventId),
    ...options,
  });
}

export function useEventDashboardCheckpointTotals(
  eventId: string | undefined,
  filters: DashboardFilters,
  options?: UseQueryOptions<CheckpointTotalsResponse, unknown, CheckpointTotalsResponse, [string, string, string, DashboardFilters]>,
) {
  const queryKey: [string, string, string, DashboardFilters] = useMemo(
    () => ['events', eventId ?? '', 'dashboard-checkpoint-totals', filters],
    [eventId, filters],
  );

  return useQuery<CheckpointTotalsResponse, unknown, CheckpointTotalsResponse, [string, string, string, DashboardFilters]>({
    queryKey,
    queryFn: async () => {
      const queryString = buildDashboardQueryString(filters);
      const suffix = queryString ? `?${queryString}` : '';
      return apiFetch<CheckpointTotalsResponse>(`/events/${eventId}/dashboard/checkpoint-totals${suffix}`);
    },
    enabled: Boolean(eventId),
    ...options,
  });
}

export function useEventDashboardRsvpFunnel(
  eventId: string | undefined,
  options?: UseQueryOptions<RsvpFunnelResponse, unknown, RsvpFunnelResponse, [string, string, string]>,
) {
  return useQuery<RsvpFunnelResponse, unknown, RsvpFunnelResponse, [string, string, string]>({
    queryKey: ['events', eventId ?? '', 'dashboard-rsvp-funnel'],
    queryFn: async () => apiFetch<RsvpFunnelResponse>(`/events/${eventId}/dashboard/rsvp-funnel`),
    enabled: Boolean(eventId),
    ...options,
  });
}

export function useEventDashboardGuestsByList(
  eventId: string | undefined,
  options?: UseQueryOptions<GuestsByListResponse, unknown, GuestsByListResponse, [string, string, string]>,
) {
  return useQuery<GuestsByListResponse, unknown, GuestsByListResponse, [string, string, string]>({
    queryKey: ['events', eventId ?? '', 'dashboard-guests-by-list'],
    queryFn: async () => apiFetch<GuestsByListResponse>(`/events/${eventId}/dashboard/guests-by-list`),
    enabled: Boolean(eventId),
    ...options,
  });
}

