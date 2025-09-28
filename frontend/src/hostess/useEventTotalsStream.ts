import { useEffect, useMemo, useRef, useState } from 'react';
import { resolveApiUrl } from '../api/client';
import { useAuthStore } from '../auth/store';

export interface StreamTotals {
  valid: number;
  duplicate: number;
  invalid: number;
}

interface StreamCheckpointTotals extends StreamTotals {
  checkpoint_id: string | null;
}

interface StreamPayload {
  event_id: string;
  generated_at: string;
  last_change_at: string | null;
  totals: StreamTotals;
  checkpoints: StreamCheckpointTotals[];
}

type ConnectionStatus = 'idle' | 'connecting' | 'online' | 'offline';

interface UseEventTotalsStreamResult {
  connectionStatus: ConnectionStatus;
  eventTotals: StreamTotals | null;
  checkpointTotals: StreamTotals | null;
  latencyAverageMs: number | null;
}

const EMPTY_TOTALS: StreamTotals = { valid: 0, duplicate: 0, invalid: 0 };

export function useEventTotalsStream(
  eventId: string | null,
  checkpointId: string | null,
): UseEventTotalsStreamResult {
  const { token, tenantId } = useAuthStore((state) => ({
    token: state.token,
    tenantId: state.tenantId,
  }));

  const [status, setStatus] = useState<ConnectionStatus>('idle');
  const [payload, setPayload] = useState<StreamPayload | null>(null);
  const [latencyAverage, setLatencyAverage] = useState<number | null>(null);
  const latenciesRef = useRef<Array<{ ts: number; latency: number }>>([]);

  useEffect(() => {
    latenciesRef.current = [];
    setLatencyAverage(null);
    setPayload(null);

    if (!eventId) {
      setStatus('idle');
      return;
    }

    if (!token) {
      setStatus('offline');
      return;
    }

    let cancelled = false;
    let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
    let activeController: AbortController | null = null;

    const scheduleReconnect = () => {
      if (cancelled || reconnectTimer !== null) {
        return;
      }
      reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        void connect();
      }, 5000);
    };

    const connect = async () => {
      if (cancelled) {
        return;
      }

      setStatus((current) => (current === 'online' ? current : 'connecting'));

      const controller = new AbortController();
      activeController = controller;

      try {
        const response = await fetch(resolveApiUrl(`/events/${eventId}/stream`), {
          method: 'GET',
          headers: {
            Authorization: `Bearer ${token}`,
            ...(tenantId ? { 'X-Tenant-ID': tenantId } : {}),
            Accept: 'text/event-stream',
          },
          cache: 'no-store',
          signal: controller.signal,
        });

        if (cancelled) {
          controller.abort();
          return;
        }

        if (!response.ok || !response.body) {
          throw new Error('Invalid SSE response');
        }

        setStatus('online');

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (!cancelled) {
          const { value, done } = await reader.read();
          if (done) {
            throw new Error('Stream closed');
          }

          buffer += decoder.decode(value, { stream: true });
          const normalized = buffer.replace(/\r/g, '');
          const segments = normalized.split('\n\n');
          buffer = segments.pop() ?? '';

          for (const segment of segments) {
            if (segment.trim() !== '') {
              processEvent(segment);
            }
          }
        }
      } catch (error) {
        if (cancelled) {
          return;
        }

        setStatus('offline');
        scheduleReconnect();
      }
    };

    const processEvent = (rawEvent: string) => {
      const lines = rawEvent.split(/\r?\n/);
      let eventName = 'message';
      const dataLines: string[] = [];

      for (const line of lines) {
        if (!line) {
          continue;
        }
        if (line.startsWith(':')) {
          continue;
        }
        if (line.startsWith('event:')) {
          eventName = line.slice(6).trim();
          continue;
        }
        if (line.startsWith('data:')) {
          dataLines.push(line.slice(5).trim());
        }
      }

      if (eventName !== 'totals' || dataLines.length === 0) {
        return;
      }

      try {
        const decoded = JSON.parse(dataLines.join('\n')) as StreamPayload;
        setPayload(decoded);
        const generatedAt = Date.parse(decoded.generated_at);
        if (!Number.isNaN(generatedAt)) {
          const now = Date.now();
          const latency = Math.max(0, now - generatedAt);
          const bucket = latenciesRef.current;
          bucket.push({ ts: now, latency });
          const cutoff = now - 60_000;
          while (bucket.length > 0 && bucket[0].ts < cutoff) {
            bucket.shift();
          }

          if (bucket.length > 0) {
            const sum = bucket.reduce((acc, item) => acc + item.latency, 0);
            setLatencyAverage(sum / bucket.length);
          } else {
            setLatencyAverage(null);
          }
        }
      } catch (streamError) {
        // eslint-disable-next-line no-console
        console.error('Unable to parse SSE payload', streamError);
      }
    };

    void connect();

    return () => {
      cancelled = true;
      if (reconnectTimer !== null) {
        clearTimeout(reconnectTimer);
      }
      if (activeController) {
        activeController.abort();
      }
    };
  }, [eventId, token, tenantId]);

  const eventTotals = payload?.totals ?? null;

  const checkpointTotals = useMemo(() => {
    if (!payload) {
      return null;
    }

    if (!checkpointId) {
      return payload.totals;
    }

    return (
      payload.checkpoints.find((checkpoint) => checkpoint.checkpoint_id === checkpointId) ?? {
        ...EMPTY_TOTALS,
      }
    );
  }, [payload, checkpointId]);

  return {
    connectionStatus: status,
    eventTotals,
    checkpointTotals,
    latencyAverageMs: latencyAverage,
  };
}

