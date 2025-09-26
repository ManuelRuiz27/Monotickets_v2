import { ApiError } from '../api/client';

export interface ParsedApiError {
  code?: string;
  message?: string;
  details?: unknown;
  errors?: Record<string, string[] | string>;
}

function parseRawError(raw: string): ParsedApiError | null {
  if (raw.trim() === '') {
    return null;
  }

  try {
    const parsed = JSON.parse(raw) as
      | { message?: string; errors?: Record<string, string[] | string> }
      | { error?: { code?: string; message?: string; details?: unknown } };

    if ('error' in (parsed as ParsedApiError) && (parsed as { error?: ParsedApiError }).error) {
      const inner = (parsed as { error: ParsedApiError }).error;
      return {
        code: inner.code,
        message: inner.message,
        details: inner.details,
      };
    }

    if ('message' in parsed || 'errors' in parsed) {
      return parsed as ParsedApiError;
    }

    return null;
  } catch {
    return null;
  }
}

export function extractApiErrorMessage(error: unknown, fallbackMessage: string): string {
  if (error instanceof ApiError) {
    const parsed = parseRawError(error.message);

    if (parsed) {
      if (parsed.errors) {
        const firstEntry = Object.values(parsed.errors)[0];
        if (Array.isArray(firstEntry)) {
          return firstEntry[0] ?? fallbackMessage;
        }
        if (typeof firstEntry === 'string' && firstEntry.trim() !== '') {
          return firstEntry;
        }
      }

      if (parsed.message && parsed.message.trim() !== '') {
        return parsed.message;
      }
    }

    if (error.message.trim() !== '') {
      return error.message;
    }

    return fallbackMessage;
  }

  if (error instanceof Error && error.message.trim() !== '') {
    return error.message;
  }

  return fallbackMessage;
}

export function extractApiErrorCode(error: unknown): string | undefined {
  if (error instanceof ApiError) {
    const parsed = parseRawError(error.message);
    if (parsed?.code) {
      return parsed.code;
    }
  }

  return undefined;
}
