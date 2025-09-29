import { useQuery } from '@tanstack/react-query';
import { apiFetch, ApiError, resolveApiUrl } from '../api/client';
import { useAuthStore } from '../auth/store';

export type InvoiceStatus = 'pending' | 'paid' | 'void' | string;

export interface InvoiceSummary {
  id: string;
  tenant_id: string;
  status: InvoiceStatus;
  period_start: string | null;
  period_end: string | null;
  issued_at: string | null;
  due_at: string | null;
  paid_at: string | null;
  total_cents: number;
}

export interface InvoiceListResponse {
  data: InvoiceSummary[];
  meta?: {
    can_export_pdf?: boolean;
  };
}

export interface InvoiceLineItem {
  type: string;
  description: string;
  quantity: number;
  unit_price_cents: number;
  amount_cents: number;
}

export interface InvoicePayment {
  id: string;
  provider: string;
  provider_charge_id: string;
  amount_cents: number;
  currency: string;
  status: string;
  processed_at: string | null;
}

export interface InvoiceDetail extends InvoiceSummary {
  subtotal_cents: number;
  tax_cents: number;
  line_items: InvoiceLineItem[];
  payments: InvoicePayment[];
}

export interface InvoiceDetailResponse {
  data: InvoiceDetail;
}

export function useTenantInvoices() {
  return useQuery<InvoiceListResponse>({
    queryKey: ['billing', 'invoices'],
    queryFn: async () => apiFetch<InvoiceListResponse>('/billing/invoices'),
  });
}

export function useTenantInvoice(invoiceId?: string) {
  return useQuery<InvoiceDetailResponse>({
    queryKey: ['billing', 'invoices', invoiceId],
    enabled: Boolean(invoiceId),
    queryFn: async () => apiFetch<InvoiceDetailResponse>(`/billing/invoices/${invoiceId}`),
  });
}

export async function payInvoice(invoiceId: string): Promise<InvoiceDetail> {
  const response = await apiFetch<InvoiceDetailResponse>(`/billing/invoices/${invoiceId}/pay`, {
    method: 'POST',
  });

  return response.data;
}

export async function fetchInvoicePdf(invoiceId: string): Promise<Blob> {
  const { token, tenantId } = useAuthStore.getState();
  const headers = new Headers({
    Accept: 'application/pdf',
  });

  if (token) {
    headers.set('Authorization', `Bearer ${token}`);
  }

  if (tenantId) {
    headers.set('X-Tenant-ID', tenantId);
  }

  const response = await fetch(resolveApiUrl(`/billing/invoices/${invoiceId}/pdf`), {
    headers,
  });

  if (!response.ok) {
    const message = await response.text();
    throw new ApiError(message || 'No fue posible descargar la factura en PDF.', response.status);
  }

  return response.blob();
}
