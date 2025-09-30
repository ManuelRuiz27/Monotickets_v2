export type RuntimeEnvKey =
  | 'VITE_API_URL'
  | 'VITE_FINGERPRINT_ENCRYPTION_KEY'
  | 'VITE_METRICS_URL';

type RuntimeEnvRecord = Partial<Record<RuntimeEnvKey, string>>;

declare global {
  interface Window {
    __ENV__?: RuntimeEnvRecord;
  }
}

const runtimeEnv = (typeof window !== 'undefined' && window.__ENV__) || {};

const sanitize = (value: unknown): string | undefined => {
  if (typeof value !== 'string') {
    return undefined;
  }

  const trimmed = value.trim();

  return trimmed.length > 0 ? trimmed : undefined;
};

export const getRuntimeEnv = (key: RuntimeEnvKey): string | undefined => {
  const runtimeValue = sanitize(runtimeEnv[key]);
  if (runtimeValue) {
    return runtimeValue;
  }

  const importMetaValue = sanitize((import.meta.env as Record<string, unknown>)[key]);
  return importMetaValue ?? undefined;
};

export {};
