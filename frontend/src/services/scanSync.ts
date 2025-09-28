import { useEffect, useState } from 'react';
import { ApiError } from '../api/client';
import {
  fetchAttendancesSince,
  scanTicket,
  syncScanBatch,
  type EventAttendance,
  type ScanBatchResultItem,
  type ScanRequest,
  type ScanResponsePayload,
} from '../api/scan';
import {
  applyBatchResult,
  getLastSyncCursor,
  getPendingScans,
  markScansAsSent,
  observeAttendanceHistory,
  observePendingCount,
  queueOfflineScan,
  recordAttendanceFromOnlineResult,
  revertScansToPending,
  saveAttendanceFromApi,
  setLastSyncCursor,
  type AttendanceCacheRecord,
} from './offlineQueue';

const OFFLINE_MESSAGE = 'Sin conexión. El escaneo se guardó y se sincronizará automáticamente.';
const FALLBACK_MESSAGE =
  'No se pudo contactar con el servidor. El escaneo se guardó para sincronizarse cuando regrese la conexión.';

const MAX_BATCH_SIZE = 25;
const MAX_RECONCILE_ITERATIONS = 5;

interface SyncEventDuplicate {
  type: 'duplicate';
  record: AttendanceCacheRecord;
}

export type SyncEvent = SyncEventDuplicate;
export type SyncEventListener = (event: SyncEvent) => void;

const syncEventListeners = new Set<SyncEventListener>();

function emitSyncEvent(event: SyncEvent): void {
  syncEventListeners.forEach((listener) => {
    try {
      listener(event);
    } catch (error) {
      console.error('Error notificando evento de sincronización', error);
    }
  });
}

export function subscribeToSyncEvents(listener: SyncEventListener): () => void {
  syncEventListeners.add(listener);
  return () => {
    syncEventListeners.delete(listener);
  };
}

function isNavigatorOnline(): boolean {
  if (typeof navigator === 'undefined') {
    return true;
  }
  return navigator.onLine;
}

function buildPendingResponse(
  payload: ScanRequest & { scanned_at: string },
  localId: number,
  message: string
): ScanResponsePayload {
  return {
    result: 'pending',
    message,
    reason: null,
    qr_code: payload.qr_code,
    ticket: null,
    attendance: {
      id: `local-${localId}`,
      result: 'pending',
      checkpoint_id: payload.checkpoint_id ?? null,
      hostess_user_id: null,
      scanned_at: payload.scanned_at,
      device_id: payload.device_id ?? null,
      offline: true,
      metadata: null,
    },
  };
}

export async function processScan(payload: ScanRequest): Promise<ScanResponsePayload> {
  const scannedAt = payload.scanned_at ?? new Date().toISOString();
  const normalizedPayload: ScanRequest & { scanned_at: string } = {
    ...payload,
    scanned_at: scannedAt,
  };

  const offlinePayload = {
    qr_code: normalizedPayload.qr_code,
    scanned_at: scannedAt,
    checkpoint_id: normalizedPayload.checkpoint_id ?? null,
    device_id: normalizedPayload.device_id ?? null,
    event_id: normalizedPayload.event_id ?? null,
  };

  const shouldQueueOffline = !isNavigatorOnline();

  if (shouldQueueOffline) {
    const record = await queueOfflineScan(offlinePayload, OFFLINE_MESSAGE);
    return buildPendingResponse(normalizedPayload, record.local, OFFLINE_MESSAGE);
  }

  try {
    const response = await scanTicket({ ...normalizedPayload, offline: false });
    await recordAttendanceFromOnlineResult(normalizedPayload.event_id ?? null, response.data);
    return response.data;
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.status >= 500) {
        const record = await queueOfflineScan(offlinePayload, FALLBACK_MESSAGE);
        return buildPendingResponse(normalizedPayload, record.local, FALLBACK_MESSAGE);
      }
      throw error;
    }

    const record = await queueOfflineScan(offlinePayload, FALLBACK_MESSAGE);
    return buildPendingResponse(normalizedPayload, record.local, FALLBACK_MESSAGE);
  }
}

let syncInProgress = false;

export interface SyncAttemptOptions {
  eventId?: string | null;
  forceReconciliation?: boolean;
}

export interface SyncAttemptResult {
  performed: boolean;
  duplicates: AttendanceCacheRecord[];
  error?: unknown;
}

export async function attemptSync(options: SyncAttemptOptions = {}): Promise<SyncAttemptResult> {
  if (syncInProgress) {
    return { performed: false, duplicates: [] };
  }

  if (!isNavigatorOnline()) {
    return { performed: false, duplicates: [] };
  }

  syncInProgress = true;
  const duplicates: AttendanceCacheRecord[] = [];
  const eventsToReconcile = new Set<string>();
  let processedAny = false;

  try {
    while (true) {
      const batch = await getPendingScans(MAX_BATCH_SIZE);
      if (batch.length === 0) {
        break;
      }

      processedAny = true;
      await markScansAsSent(batch);

      const requestPayload = batch.map((record) => ({
        qr_code: record.qr_code,
        scanned_at: record.scanned_at,
        checkpoint_id: record.checkpoint_id ?? undefined,
        device_id: record.device_id ?? undefined,
        offline: true,
        event_id: record.event_id ?? undefined,
      }));

      let response: { data: ScanBatchResultItem[] };
      try {
        response = await syncScanBatch({ scans: requestPayload });
      } catch (error) {
        const message =
          error instanceof ApiError && error.message
            ? error.message
            : 'No se pudo sincronizar los escaneos pendientes.';
        await revertScansToPending(batch, message);
        throw error;
      }

      for (const result of response.data) {
        const source = batch[result.index];
        if (!source) {
          continue;
        }

        if (source.event_id) {
          eventsToReconcile.add(source.event_id);
        }

        const updated = await applyBatchResult(source, result);
        if (updated && updated.conflict && result.result === 'duplicate') {
          duplicates.push(updated);
        }
      }

      if (batch.length < MAX_BATCH_SIZE) {
        break;
      }
    }

    if (options.eventId) {
      if (options.forceReconciliation || processedAny) {
        eventsToReconcile.add(options.eventId);
      }
    }

    for (const eventId of eventsToReconcile) {
      await reconcileAttendancesForEvent(eventId);
    }

    duplicates.forEach((record) => emitSyncEvent({ type: 'duplicate', record }));

    return { performed: processedAny, duplicates };
  } catch (error) {
    return { performed: processedAny, duplicates, error };
  } finally {
    syncInProgress = false;
  }
}

async function reconcileAttendancesForEvent(eventId: string): Promise<void> {
  if (!eventId) {
    return;
  }

  if (!isNavigatorOnline()) {
    return;
  }

  let cursor = await getLastSyncCursor(eventId);
  let iterations = 0;

  while (iterations < MAX_RECONCILE_ITERATIONS) {
    try {
      const response = await fetchAttendancesSince(eventId, {
        cursor: cursor ?? undefined,
      });

      const attendances: EventAttendance[] = response.data ?? [];
      for (const attendance of attendances) {
        await saveAttendanceFromApi(eventId, attendance);
      }

      const nextCursor = response.meta?.next_cursor ?? null;
      if (nextCursor && nextCursor !== cursor) {
        await setLastSyncCursor(eventId, nextCursor);
        cursor = nextCursor;
      } else {
        const lastScannedAt = attendances.length > 0 ? attendances[attendances.length - 1].scanned_at : null;
        if (lastScannedAt) {
          await setLastSyncCursor(eventId, lastScannedAt);
        }
        break;
      }

      if (attendances.length === 0) {
        break;
      }

      iterations += 1;
    } catch (error) {
      console.error('No fue posible reconciliar los escaneos del evento', eventId, error);
      break;
    }
  }
}

export function useAttendanceHistory(eventId: string | null, limit = 20): AttendanceCacheRecord[] {
  const [history, setHistory] = useState<AttendanceCacheRecord[]>([]);

  useEffect(() => {
    const unsubscribe = observeAttendanceHistory(eventId, limit, setHistory);
    return () => unsubscribe();
  }, [eventId, limit]);

  return history;
}

export function usePendingQueueCount(): number {
  const [count, setCount] = useState(0);

  useEffect(() => {
    const unsubscribe = observePendingCount(setCount);
    return () => unsubscribe();
  }, []);

  return count;
}

export function useScanSync(eventId: string | null): void {
  useEffect(() => {
    let cancelled = false;

    const runSync = async (forceReconciliation: boolean) => {
      if (cancelled) {
        return;
      }

      if (eventId) {
        await attemptSync({ eventId, forceReconciliation });
      } else {
        await attemptSync();
      }
    };

    void runSync(true);

    if (typeof window === 'undefined') {
      return () => {
        cancelled = true;
      };
    }

    const handleOnline = () => {
      void runSync(false);
    };

    window.addEventListener('online', handleOnline);

    const interval = window.setInterval(() => {
      if (isNavigatorOnline()) {
        void runSync(false);
      }
    }, 15000);

    return () => {
      cancelled = true;
      window.removeEventListener('online', handleOnline);
      window.clearInterval(interval);
    };
  }, [eventId]);
}

export type { AttendanceCacheRecord } from './offlineQueue';
