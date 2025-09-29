import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface TenantOverviewUsage {
  event_count: number;
  user_count: number;
  scan_count: number;
}

export interface TenantOverviewPlan {
  id: string;
  code: string;
  name: string;
  billing_cycle: string;
  price_cents: number;
  limits: Record<string, unknown>;
}

export interface TenantOverviewSubscription {
  id: string;
  status: string;
  current_period_start: string | null;
  current_period_end: string | null;
  trial_end: string | null;
  cancel_at_period_end: boolean;
}

export interface TenantOverviewScanBreakdownEntry {
  event_id: string;
  event_name: string | null;
  value: number;
}

export interface TenantOverviewInvoiceLineItem {
  type: string;
  description: string;
  quantity: number;
  unit_price_cents: number;
  amount_cents: number;
}

export interface TenantOverviewInvoice {
  id: string;
  status: string;
  period_start: string | null;
  period_end: string | null;
  issued_at: string | null;
  due_at: string | null;
  paid_at: string | null;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  line_items: TenantOverviewInvoiceLineItem[];
}

export interface TenantOverviewResponse {
  data: {
    tenant: {
      id: string;
      name: string;
    };
    plan: TenantOverviewPlan | null;
    effective_limits: Record<string, unknown>;
    subscription: TenantOverviewSubscription | null;
    usage: TenantOverviewUsage;
    scan_breakdown: TenantOverviewScanBreakdownEntry[];
    latest_invoice: TenantOverviewInvoice | null;
    period: {
      start: string;
      end: string;
    };
  };
}

export function useTenantOverview() {
  return useQuery<TenantOverviewResponse>({
    queryKey: ['tenant', 'overview'],
    queryFn: async () => apiFetch<TenantOverviewResponse>('/tenants/me/overview'),
  });
}
