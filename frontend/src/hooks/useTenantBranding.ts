import { useMutation, useQuery, useQueryClient, type UseMutationOptions } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface TenantBrandingColors {
  primary: string | null;
  accent: string | null;
  bg: string | null;
  text: string | null;
}

export interface TenantBranding {
  logo_url: string | null;
  colors: TenantBrandingColors;
  email_from: string | null;
  email_reply_to: string | null;
}

export interface TenantBrandingResponse {
  data: TenantBranding;
}

export interface UpdateTenantBrandingPayload {
  logo_url?: string | null;
  colors?: Partial<TenantBrandingColors>;
  email_from?: string | null;
  email_reply_to?: string | null;
}

export function useTenantBranding() {
  return useQuery<TenantBrandingResponse>({
    queryKey: ['tenant', 'branding'],
    queryFn: async () => apiFetch<TenantBrandingResponse>('/tenants/me/branding'),
  });
}

export function useUpdateTenantBranding(
  options?: UseMutationOptions<TenantBrandingResponse, unknown, UpdateTenantBrandingPayload>,
) {
  const queryClient = useQueryClient();
  const { onSuccess, ...restOptions } = options ?? {};

  return useMutation<TenantBrandingResponse, unknown, UpdateTenantBrandingPayload>({
    mutationFn: async (payload: UpdateTenantBrandingPayload) =>
      apiFetch<TenantBrandingResponse>('/tenants/me/branding', {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),
    onSuccess: (data, variables, context) => {
      queryClient.setQueryData(['tenant', 'branding'], data);
      onSuccess?.(data, variables, context, undefined as never);
    },
    ...restOptions,
  });
}
