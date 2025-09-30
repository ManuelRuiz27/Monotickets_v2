import Dexie, { type Table } from 'dexie';
import { liveQuery } from 'dexie';
import type {
  ScanBatchResultItem,
  ScanResponsePayload,
  EventAttendance,
} from '../api/scan';

export const HISTORY_LIMIT = 50;

export type QueueStatus = 'pending' | 'sent' | 'confirmed';

interface QueueScanRow {
  local?: number;
  qr_code: string;
  checkpoint_id: string | null;
  device_id: string | null;
  scanned_at: string;
  status: QueueStatus;
  attempts: number;
  event_id: string | null;
  offline: boolean;
  result?: string | null;
  message?: string | null;
  reason?: string | null;
  conflict?: boolean;
  error_message?: string | null;
  created_at: string;
  updated_at: string;
}

export type QueueScanRecord = QueueScanRow & { local: number };

interface AttendanceCacheRow {
  id?: number;
  event_id: string | null;
  qr_code: string;
  result: string;
  message: string | null;
  reason: string | null;
  scanned_at: string | null;
  status: QueueStatus;
  local_scan_id: number | null;
  offline: boolean;
  conflict: boolean;
  device_id: string | null;
  checkpoint_id: string | null;
  attendance_id: string | null;
  metadata: Record<string, unknown> | null;
  updated_at: string;
}

export type AttendanceCacheRecord = AttendanceCacheRow & { id: number };

interface SyncStateRow {
  key: string;
  value: string;
  updated_at: string;
}

class ScanQueueDatabase extends Dexie {
  queue_scans!: Table<QueueScanRow, number>;
  attendances_cache!: Table<AttendanceCacheRow, number>;
  sync_state!: Table<SyncStateRow, string>;

  constructor() {
    super('MonoticketsScanQueue');
    this.version(1).stores({
      queue_scans: '++local,status,scanned_at,event_id',
      attendances_cache: '++id,attendance_id,event_id,scanned_at',
      sync_state: '&key',
    });
  }
}

const db = new ScanQueueDatabase();

function ensureRecordHasLocal(record: QueueScanRow): QueueScanRecord {
  if (record.local === undefined) {
    throw new Error('Queue record is missing local identifier.');
  }
  return record as QueueScanRecord;
}

const RESULT_MESSAGES: Record<string, string> = {
  valid: 'Entrada registrada correctamente.',
  duplicate: 'Entrada duplicada detectada.',
  invalid: 'Ticket inválido.',
  revoked: 'Ticket revocado.',
  expired: 'Ticket expirado.',
};

function resolveResultMessage(result: string, fallback: string | null = null): string {
  return RESULT_MESSAGES[result] ?? fallback ?? `Resultado: ${result}`;
}

async function pruneAttendanceHistory(eventId: string | null, limit: number): Promise<void> {
  const allForEvent = await db.attendances_cache
    .filter((row) => (eventId === null ? row.event_id == null : row.event_id === eventId))
    .toArray();

  if (allForEvent.length <= limit) {
    return;
  }

  const sorted = allForEvent.sort((left: AttendanceCacheRow, right: AttendanceCacheRow) => {
    const leftTime = left.scanned_at ?? '';
    const rightTime = right.scanned_at ?? '';
    if (leftTime === rightTime) {
      return (left.id ?? 0) - (right.id ?? 0);
    }
    return leftTime.localeCompare(rightTime);
  });

  const excess = sorted.slice(0, sorted.length - limit);
  const idsToDelete = excess
    .map((item: AttendanceCacheRow) => item.id)
    .filter((id: number | undefined): id is number => id !== undefined);

  if (idsToDelete.length > 0) {
    await db.attendances_cache.bulkDelete(idsToDelete);
  }
}

export interface QueueOfflineScanPayload {
  qr_code: string;
  scanned_at: string;
  checkpoint_id: string | null;
  device_id: string | null;
  event_id: string | null;
}

export async function queueOfflineScan(
  payload: QueueOfflineScanPayload,
  message = 'Escaneo pendiente de sincronización.'
): Promise<QueueScanRecord> {
  const now = new Date().toISOString();
  const baseRow: QueueScanRow = {
    qr_code: payload.qr_code,
    checkpoint_id: payload.checkpoint_id,
    device_id: payload.device_id,
    scanned_at: payload.scanned_at,
    status: 'pending',
    attempts: 0,
    event_id: payload.event_id,
    offline: true,
    result: 'pending',
    message,
    reason: null,
    conflict: false,
    error_message: null,
    created_at: now,
    updated_at: now,
  };

  const local = await db.queue_scans.add(baseRow);
  const record = ensureRecordHasLocal({ ...baseRow, local });

  await db.attendances_cache.add({
    event_id: payload.event_id,
    qr_code: payload.qr_code,
    result: 'pending',
    message,
    reason: null,
    scanned_at: payload.scanned_at,
    status: 'pending',
    local_scan_id: local,
    offline: true,
    conflict: false,
    device_id: payload.device_id,
    checkpoint_id: payload.checkpoint_id,
    attendance_id: null,
    metadata: null,
    updated_at: now,
  });

  await pruneAttendanceHistory(payload.event_id, HISTORY_LIMIT);

  return record;
}

export async function recordAttendanceFromOnlineResult(
  eventId: string | null,
  payload: ScanResponsePayload
): Promise<void> {
  const attendance = payload.attendance;
  const now = new Date().toISOString();
  const message = payload.message ?? resolveResultMessage(payload.result);
  const targetEventId = eventId ?? payload.ticket?.event_id ?? null;
  const scannedAt = attendance?.scanned_at ?? payload.attendance?.scanned_at ?? payload.ticket?.issued_at ?? now;

  const existing = attendance?.id
    ? await db.attendances_cache.where('attendance_id').equals(attendance.id).first()
    : undefined;

  const baseData: AttendanceCacheRow = {
    event_id: targetEventId,
    qr_code: payload.qr_code,
    result: payload.result,
    message,
    reason: payload.reason ?? null,
    scanned_at: scannedAt,
    status: 'confirmed',
    local_scan_id: null,
    offline: attendance?.offline ?? false,
    conflict: payload.result === 'duplicate',
    device_id: attendance?.device_id ?? null,
    checkpoint_id: attendance?.checkpoint_id ?? null,
    attendance_id: attendance?.id ?? null,
    metadata: attendance?.metadata ?? null,
    updated_at: now,
  };

  if (existing?.id !== undefined) {
    await db.attendances_cache.update(existing.id, baseData);
  } else {
    await db.attendances_cache.add(baseData);
  }

  await pruneAttendanceHistory(targetEventId, HISTORY_LIMIT);
}

export async function getPendingScans(limit = 50): Promise<QueueScanRecord[]> {
  const pending = await db.queue_scans.where('status').equals('pending').sortBy('scanned_at');
  return pending.slice(0, limit).map(ensureRecordHasLocal);
}

export async function markScansAsSent(records: QueueScanRecord[]): Promise<void> {
  const now = new Date().toISOString();
  await Promise.all(
    records.map((record) => {
      record.attempts += 1;
      record.status = 'sent';
      return db.queue_scans.update(record.local, {
        status: 'sent',
        attempts: record.attempts,
        updated_at: now,
        error_message: null,
      });
    })
  );
}

export async function revertScansToPending(records: QueueScanRecord[], errorMessage: string): Promise<void> {
  const now = new Date().toISOString();
  await Promise.all(
    records.map((record) => {
      record.status = 'pending';
      return db.queue_scans.update(record.local, {
        status: 'pending',
        updated_at: now,
        error_message: errorMessage,
      });
    })
  );
}

export async function applyBatchResult(
  record: QueueScanRecord,
  result: ScanBatchResultItem
): Promise<AttendanceCacheRecord | null> {
  const now = new Date().toISOString();
  const isDuplicate = result.result === 'duplicate';

  await db.queue_scans.update(record.local, {
    status: 'confirmed',
    result: result.result,
    message: result.message ?? resolveResultMessage(result.result),
    reason: result.reason ?? null,
    conflict: isDuplicate,
    updated_at: now,
    error_message: null,
  });

  const existingHistory = await db.attendances_cache.where('local_scan_id').equals(record.local).first();
  const historyUpdates: Partial<AttendanceCacheRow> = {
    result: result.result,
    message: result.message ?? resolveResultMessage(result.result),
    reason: result.reason ?? null,
    status: 'confirmed',
    conflict: isDuplicate,
    updated_at: now,
    offline: result.attendance?.offline ?? true,
    device_id: result.attendance?.device_id ?? record.device_id ?? null,
    checkpoint_id: result.attendance?.checkpoint_id ?? record.checkpoint_id ?? null,
    metadata: result.attendance?.metadata ?? null,
    scanned_at: result.attendance?.scanned_at ?? record.scanned_at,
    attendance_id: result.attendance?.id ?? existingHistory?.attendance_id ?? null,
  };

  if (existingHistory?.id !== undefined) {
    await db.attendances_cache.update(existingHistory.id, historyUpdates);
    await pruneAttendanceHistory(record.event_id, HISTORY_LIMIT);
    const refreshed = await db.attendances_cache.get(existingHistory.id);
    return refreshed ? (refreshed as AttendanceCacheRecord) : null;
  }

  const newHistory: AttendanceCacheRow = {
    event_id: record.event_id,
    qr_code: record.qr_code,
    result: historyUpdates.result ?? result.result,
    message: historyUpdates.message ?? resolveResultMessage(result.result),
    reason: historyUpdates.reason ?? null,
    scanned_at: historyUpdates.scanned_at ?? record.scanned_at,
    status: 'confirmed',
    local_scan_id: record.local,
    offline: historyUpdates.offline ?? true,
    conflict: historyUpdates.conflict ?? isDuplicate,
    device_id: historyUpdates.device_id ?? record.device_id ?? null,
    checkpoint_id: historyUpdates.checkpoint_id ?? record.checkpoint_id ?? null,
    attendance_id: historyUpdates.attendance_id ?? null,
    metadata: historyUpdates.metadata ?? null,
    updated_at: now,
  };

  const id = await db.attendances_cache.add(newHistory);
  await pruneAttendanceHistory(record.event_id, HISTORY_LIMIT);
  const stored = await db.attendances_cache.get(id);
  return stored ? (stored as AttendanceCacheRecord) : null;
}

export async function saveAttendanceFromApi(
  eventId: string,
  attendance: EventAttendance
): Promise<void> {
  const now = new Date().toISOString();
  const message = resolveResultMessage(attendance.result);
  let metadataQr: string | null = null;
  if (attendance.metadata && typeof attendance.metadata === 'object') {
    const candidate = (attendance.metadata as Record<string, unknown>)['qr_code'];
    if (typeof candidate === 'string') {
      metadataQr = candidate;
    }
  }
  const qrCode = metadataQr ?? attendance.ticket_id ?? 'unknown';

  const existing = await db.attendances_cache.where('attendance_id').equals(attendance.id).first();
  const data: AttendanceCacheRow = {
    event_id: eventId,
    qr_code: qrCode,
    result: attendance.result,
    message,
    reason: null,
    scanned_at: attendance.scanned_at ?? null,
    status: 'confirmed',
    local_scan_id: null,
    offline: attendance.offline ?? false,
    conflict: attendance.result === 'duplicate',
    device_id: attendance.device_id ?? null,
    checkpoint_id: attendance.checkpoint_id ?? null,
    attendance_id: attendance.id,
    metadata: attendance.metadata ?? null,
    updated_at: now,
  };

  if (existing?.id !== undefined) {
    await db.attendances_cache.update(existing.id, data);
  } else {
    await db.attendances_cache.add(data);
  }

  await pruneAttendanceHistory(eventId, HISTORY_LIMIT);
}

function syncStateKey(eventId: string | null): string {
  return `cursor:${eventId ?? 'none'}`;
}

export async function getLastSyncCursor(eventId: string): Promise<string | null> {
  const entry = await db.sync_state.get(syncStateKey(eventId));
  return entry?.value ?? null;
}

export async function setLastSyncCursor(eventId: string, cursor: string | null): Promise<void> {
  const key = syncStateKey(eventId);
  if (cursor === null) {
    await db.sync_state.delete(key);
    return;
  }
  const now = new Date().toISOString();
  await db.sync_state.put({ key, value: cursor, updated_at: now });
}

export function observeAttendanceHistory(
  eventId: string | null,
  limit: number,
  callback: (records: AttendanceCacheRecord[]) => void
): () => void {
  const subscription = liveQuery(async () => {
    if (eventId) {
      const data = await db.attendances_cache.where('event_id').equals(eventId).toArray();
      return data
        .sort((left: AttendanceCacheRow, right: AttendanceCacheRow) => {
          const leftTime = left.scanned_at ?? '';
          const rightTime = right.scanned_at ?? '';
          if (leftTime === rightTime) {
            return (right.id ?? 0) - (left.id ?? 0);
          }
          return rightTime.localeCompare(leftTime);
        })
        .slice(0, limit);
    }

    const all = await db.attendances_cache.toArray();
    return all
      .sort((left: AttendanceCacheRow, right: AttendanceCacheRow) => {
        const leftTime = left.scanned_at ?? '';
        const rightTime = right.scanned_at ?? '';
        if (leftTime === rightTime) {
          return (right.id ?? 0) - (left.id ?? 0);
        }
        return rightTime.localeCompare(leftTime);
      })
      .slice(0, limit);
  }).subscribe({
    next: (rows: AttendanceCacheRow[]) => {
      const mapped = rows
        .map((row) => ({ ...row, id: row.id as number }))
        .filter((row): row is AttendanceCacheRecord => typeof row.id === 'number');
      callback(mapped);
    },
    error: (error: unknown) => {
      console.error('Error observando historial de escaneos', error);
    },
  });

  return () => subscription.unsubscribe();
}

export function observePendingCount(callback: (count: number) => void): () => void {
  const subscription = liveQuery(() => db.queue_scans.where('status').equals('pending').count()).subscribe({
    next: (count: number) => callback(count),
    error: (error: unknown) => {
      console.error('Error observando cola de escaneos', error);
    },
  });

  return () => subscription.unsubscribe();
}

export { db as scanQueueDb };
