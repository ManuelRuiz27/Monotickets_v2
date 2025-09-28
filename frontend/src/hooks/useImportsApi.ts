import { useMutation, useQuery, useQueryClient, type UseMutationOptions, type UseQueryOptions } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export type ImportSource = 'csv' | 'xlsx' | 'api';

export type ImportStatus = 'uploaded' | 'processing' | 'completed' | 'failed';

export interface ImportResource {
  id: string;
  tenant_id: string;
  event_id: string;
  source: ImportSource;
  status: ImportStatus;
  rows_total: number;
  rows_ok: number;
  rows_failed: number;
  progress: number | null;
  report_file_url: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface ImportResponse {
  data: ImportResource;
}

export interface CreateImportPayload {
  source: ImportSource;
  file_url: string;
  mapping: Record<string, string | null>;
  options?: {
    dedupe_by_email?: boolean;
  };
}

export function useCreateImport(
  eventId: string,
  options?: UseMutationOptions<ImportResponse, unknown, CreateImportPayload>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<ImportResponse, unknown, CreateImportPayload>({
    mutationFn: async (payload: CreateImportPayload) =>
      apiFetch<ImportResponse>(`/events/${eventId}/imports`, {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data, variables, context) => {
      void queryClient.invalidateQueries({ queryKey: ['events', eventId, 'imports'] });
      onSuccess?.(data, variables, context);
    },
    ...restOptions,
  });
}

export function useImport(
  importId: string | undefined,
  options?: UseQueryOptions<ImportResponse, unknown, ImportResponse, [string, string]>,
) {
  return useQuery<ImportResponse, unknown, ImportResponse, [string, string]>({
    queryKey: ['imports', importId ?? ''],
    queryFn: async () => apiFetch<ImportResponse>(`/imports/${importId}`),
    enabled: Boolean(importId),
    ...options,
  });
}
