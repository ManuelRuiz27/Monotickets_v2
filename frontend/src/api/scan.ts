import { apiFetch } from './client';

export type ScanResult = 'valid' | 'duplicate' | 'invalid' | 'revoked' | 'expired' | string;

export interface ScanRequest {
  qr_code: string;
  scanned_at?: string;
  checkpoint_id?: string | null;
  device_id?: string | null;
  offline?: boolean;
  event_id?: string | null;
}

export interface ScanTicketInfo {
  id: string;
  event_id: string | null;
  status: string;
  type: string;
  issued_at: string | null;
  expires_at: string | null;
  guest: { id: string; full_name: string } | null;
  event: { id: string; name: string; checkin_policy: string } | null;
}

export interface ScanAttendanceInfo {
  id: string;
  result: ScanResult;
  checkpoint_id: string | null;
  hostess_user_id: string | null;
  scanned_at: string | null;
  device_id: string | null;
  offline: boolean;
  metadata: Record<string, unknown> | null;
}

export interface ScanResponsePayload {
  result: ScanResult;
  message: string;
  reason?: string | null;
  qr_code: string;
  ticket: ScanTicketInfo | null;
  attendance: ScanAttendanceInfo | null;
}

export interface ScanResponse {
  data: ScanResponsePayload;
}

export async function scanTicket(payload: ScanRequest): Promise<ScanResponse> {
  const body: Record<string, unknown> = {
    qr_code: payload.qr_code,
    scanned_at: payload.scanned_at ?? new Date().toISOString(),
  };

  if (payload.checkpoint_id !== undefined) {
    body.checkpoint_id = payload.checkpoint_id;
  }
  if (payload.device_id !== undefined) {
    body.device_id = payload.device_id;
  }
  if (payload.offline !== undefined) {
    body.offline = payload.offline;
  }
  if (payload.event_id !== undefined) {
    body.event_id = payload.event_id;
  }

  return apiFetch<ScanResponse>('/scan', {
    method: 'POST',
    body: JSON.stringify(body),
  });
}

export interface ScanBatchRequest {
  scans: ScanRequest[];
}

export interface ScanBatchResultItem extends ScanResponsePayload {
  index: number;
  status?: number;
}

export interface ScanBatchResponse {
  data: ScanBatchResultItem[];
  meta?: {
    summary?: {
      valid: number;
      duplicate: number;
      errors: number;
    };
    total_scans?: number;
    next_cursor?: string | null;
  };
}

export interface EventAttendance {
  id: string;
  event_id: string;
  ticket_id: string;
  guest_id: string | null;
  result: string;
  checkpoint_id: string | null;
  hostess_user_id: string | null;
  scanned_at: string | null;
  device_id: string | null;
  offline: boolean;
  metadata: Record<string, unknown> | null;
}

export interface AttendancesSinceResponse {
  data: EventAttendance[];
  meta?: {
    next_cursor?: string | null;
  };
}

export async function syncScanBatch(payload: ScanBatchRequest): Promise<ScanBatchResponse> {
  return apiFetch<ScanBatchResponse>('/scan/batch', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

interface AttendancesSinceParams {
  cursor?: string;
  limit?: number;
}

export async function fetchAttendancesSince(
  eventId: string,
  params: AttendancesSinceParams = {}
): Promise<AttendancesSinceResponse> {
  const query = new URLSearchParams();
  if (params.cursor) {
    query.set('cursor', params.cursor);
  }
  if (params.limit) {
    query.set('limit', params.limit.toString());
  }

  const suffix = query.toString() ? `?${query.toString()}` : '';
  return apiFetch<AttendancesSinceResponse>(`/events/${eventId}/attendances/since${suffix}`);
}
