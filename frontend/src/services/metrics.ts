import { resolveApiUrl } from '../api/client';

type NumericMetric = 'scanLatencyMs' | 'batchSize' | 'retries' | 'cameraFps';

interface MetricAggregate {
  count: number;
  sum: number;
  min: number;
  max: number;
}

interface RateAggregate {
  success: number;
  total: number;
}

export interface MetricsSnapshot {
  timestamp: string;
  metrics: Partial<Record<NumericMetric, SerializedMetricAggregate>> & {
    decodeSuccessRate?: SerializedRateAggregate;
  };
}

interface SerializedMetricAggregate {
  count: number;
  sum: number;
  min: number;
  max: number;
  average: number;
}

interface SerializedRateAggregate {
  success: number;
  total: number;
  rate: number;
}

const METRIC_NAMES: readonly NumericMetric[] = ['scanLatencyMs', 'batchSize', 'retries', 'cameraFps'];

const numericAggregates: Record<NumericMetric, MetricAggregate> = {
  scanLatencyMs: createEmptyAggregate(),
  batchSize: createEmptyAggregate(),
  retries: createEmptyAggregate(),
  cameraFps: createEmptyAggregate(),
};

const rateAggregate: RateAggregate = { success: 0, total: 0 };

const METRICS_ENDPOINT = (import.meta.env.VITE_METRICS_URL as string | undefined)?.trim();

let schedulerInitialized = false;
let intervalId: number | null = null;
let visibilityChangeHandler: (() => void) | null = null;

function createEmptyAggregate(): MetricAggregate {
  return {
    count: 0,
    sum: 0,
    min: Number.POSITIVE_INFINITY,
    max: Number.NEGATIVE_INFINITY,
  };
}

function ensureScheduler(): void {
  if (schedulerInitialized) {
    return;
  }
  schedulerInitialized = true;

  if (typeof window === 'undefined') {
    return;
  }

  intervalId = window.setInterval(() => {
    void flushMetrics();
  }, 60000);

  visibilityChangeHandler = () => {
    if (document.visibilityState === 'hidden') {
      flushMetricsSync();
    }
  };

  window.addEventListener('beforeunload', flushMetricsSync);
  document.addEventListener('visibilitychange', visibilityChangeHandler);
}

function updateAggregate(name: NumericMetric, value: number): void {
  if (!Number.isFinite(value)) {
    return;
  }

  ensureScheduler();
  const aggregate = numericAggregates[name];
  aggregate.count += 1;
  aggregate.sum += value;
  aggregate.min = Math.min(aggregate.min, value);
  aggregate.max = Math.max(aggregate.max, value);
}

function updateRate(success: boolean): void {
  ensureScheduler();
  rateAggregate.total += 1;
  if (success) {
    rateAggregate.success += 1;
  }
}

function hasMetrics(): boolean {
  const hasNumeric = METRIC_NAMES.some((name) => numericAggregates[name].count > 0);
  return hasNumeric || rateAggregate.total > 0;
}

function serializeAggregate(aggregate: MetricAggregate): SerializedMetricAggregate | null {
  if (aggregate.count === 0) {
    return null;
  }

  return {
    count: aggregate.count,
    sum: aggregate.sum,
    min: aggregate.min,
    max: aggregate.max,
    average: aggregate.sum / aggregate.count,
  };
}

function serializeRate(): SerializedRateAggregate | null {
  if (rateAggregate.total === 0) {
    return null;
  }

  return {
    success: rateAggregate.success,
    total: rateAggregate.total,
    rate: rateAggregate.success / rateAggregate.total,
  };
}

function resetAggregates(): void {
  for (const name of METRIC_NAMES) {
    numericAggregates[name] = createEmptyAggregate();
  }
  rateAggregate.success = 0;
  rateAggregate.total = 0;
}

function buildSnapshot(): MetricsSnapshot | null {
  if (!hasMetrics()) {
    return null;
  }

  const metrics: MetricsSnapshot['metrics'] = {};
  for (const name of METRIC_NAMES) {
    const serialized = serializeAggregate(numericAggregates[name]);
    if (serialized) {
      metrics[name] = serialized;
    }
  }

  const rate = serializeRate();
  if (rate) {
    metrics.decodeSuccessRate = rate;
  }

  return {
    timestamp: new Date().toISOString(),
    metrics,
  };
}

async function sendSnapshot(snapshot: MetricsSnapshot, { sync = false } = {}): Promise<void> {
  if (!METRICS_ENDPOINT) {
    return;
  }

  const payload = JSON.stringify(snapshot);
  const url = resolveApiUrl(METRICS_ENDPOINT);

  if (sync && typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
    try {
      const blob = new Blob([payload], { type: 'application/json' });
      navigator.sendBeacon(url, blob);
      return;
    } catch (error) {
      console.warn('No se pudieron enviar las métricas mediante sendBeacon.', error);
    }
  }

  try {
    await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: payload,
      keepalive: true,
    });
  } catch (error) {
    console.warn('No se pudieron enviar las métricas agregadas.', error);
  }
}

export function recordScanLatency(durationMs: number): void {
  updateAggregate('scanLatencyMs', durationMs);
}

export function recordBatchSize(size: number): void {
  updateAggregate('batchSize', size);
}

export function recordRetry(count = 1): void {
  updateAggregate('retries', count);
}

export function recordCameraFps(fps: number): void {
  updateAggregate('cameraFps', fps);
}

export function recordDecodeAttempt(success: boolean): void {
  updateRate(success);
}

export function getMetricsSnapshot(): MetricsSnapshot | null {
  return buildSnapshot();
}

export async function flushMetrics(): Promise<void> {
  const snapshot = buildSnapshot();
  if (!snapshot) {
    return;
  }
  resetAggregates();
  await sendSnapshot(snapshot);
}

export function flushMetricsSync(): void {
  const snapshot = buildSnapshot();
  if (!snapshot) {
    return;
  }
  resetAggregates();
  void sendSnapshot(snapshot, { sync: true });
}

export function shutdownMetrics(): void {
  if (typeof window === 'undefined') {
    resetAggregates();
    return;
  }
  if (intervalId !== null) {
    window.clearInterval(intervalId);
    intervalId = null;
  }
  window.removeEventListener('beforeunload', flushMetricsSync);
  if (visibilityChangeHandler) {
    document.removeEventListener('visibilitychange', visibilityChangeHandler);
    visibilityChangeHandler = null;
  }
  resetAggregates();
  schedulerInitialized = false;
}
