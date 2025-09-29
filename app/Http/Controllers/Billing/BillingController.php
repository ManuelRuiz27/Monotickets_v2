<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Exceptions\BillingPeriodAlreadyClosedException;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BillingController extends Controller
{
    use InteractsWithTenants;

    public function __construct(private readonly BillingService $billingService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->resolveTenantContext($request, $user);

        if ($tenantId === null && ! $this->isSuperAdmin($user)) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to list invoices.'],
            ]);
        }

        $invoices = $this->invoiceQuery($user, $tenantId)
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $invoices->map(fn (Invoice $invoice): array => $this->formatInvoiceSummary($invoice))->all(),
            'meta' => [
                'can_export_pdf' => $this->canExportInvoicePdf($tenantId),
            ],
        ]);
    }

    public function show(Request $request, string $invoiceId): JsonResponse
    {
        [$invoice] = $this->resolveInvoice($request, $invoiceId);

        if ($invoice === null) {
            return ApiResponse::error('invoice_not_found', 'The invoice could not be found for the current context.', null, Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $this->formatInvoice($invoice),
        ]);
    }

    public function downloadPdf(Request $request, string $invoiceId)
    {
        [$invoice, $tenantId] = $this->resolveInvoice($request, $invoiceId, ['payments', 'tenant.latestSubscription.plan']);

        if ($invoice === null) {
            return ApiResponse::error('invoice_not_found', 'The invoice could not be found for the current context.', null, Response::HTTP_NOT_FOUND);
        }

        $tenant = $invoice->tenant;

        if ($tenantId === null && $tenant !== null) {
            $tenantId = (string) $tenant->id;
        }

        if ($tenantId !== null && ! $this->canExportInvoicePdf($tenantId)) {
            return ApiResponse::error('FEATURE_NOT_AVAILABLE', 'Your current plan does not include PDF export capabilities.', ['feature' => 'exports.pdf'], Response::HTTP_FORBIDDEN);
        }

        $options = new Options();
        $options->set('defaultFont', 'helvetica');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $html = view('billing.invoice_pdf', [
            'invoice' => $invoice,
            'tenant' => $tenant,
        ])->render();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $filename = sprintf('invoice-%s.pdf', $invoice->id);

        return response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->resolveTenantContext($request, $user);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to preview billing.'],
            ]);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return ApiResponse::error('tenant_not_found', 'The selected tenant could not be found.', null, Response::HTTP_NOT_FOUND);
        }

        $subscription = $tenant->activeSubscription();

        if ($subscription === null) {
            return ApiResponse::error('subscription_not_found', 'The tenant does not have an active subscription.', null, Response::HTTP_NOT_FOUND);
        }

        $subscription->loadMissing('plan');

        $closeAtInput = $request->input('close_at');
        $closeAt = $closeAtInput instanceof CarbonImmutable
            ? $closeAtInput
            : ($closeAtInput !== null ? CarbonImmutable::parse((string) $closeAtInput) : CarbonImmutable::now());

        $simulation = $request->input('simulate', []);

        if (! is_array($simulation)) {
            $simulation = [];
        }

        $preview = $this->billingService->preview($subscription, $closeAt, [
            'user_count' => array_key_exists('user_count', $simulation) ? $simulation['user_count'] : null,
            'scan_count' => array_key_exists('scan_count', $simulation) ? $simulation['scan_count'] : null,
        ]);

        return response()->json([
            'data' => $this->formatPreview($subscription->tenant_id, $preview),
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->resolveTenantContext($request, $user);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to close invoices.'],
            ]);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return ApiResponse::error('tenant_not_found', 'The selected tenant could not be found.', null, Response::HTTP_NOT_FOUND);
        }

        $subscription = $tenant->activeSubscription();

        if ($subscription === null) {
            return ApiResponse::error('subscription_not_found', 'The tenant does not have an active subscription.', null, Response::HTTP_NOT_FOUND);
        }

        $subscription->loadMissing('plan');

        try {
            $invoice = $this->billingService->closePeriod($subscription);
        } catch (BillingPeriodAlreadyClosedException $exception) {
            $invoice = $exception->invoice();
            $invoice->loadMissing('payments');

            return ApiResponse::error(
                'billing_period_closed',
                'The billing period has already been closed.',
                ['invoice' => $this->formatInvoice($invoice)],
                Response::HTTP_CONFLICT
            );
        }

        $created = $invoice->wasRecentlyCreated;
        $invoice->loadMissing('payments');

        return response()->json([
            'data' => $this->formatInvoice($invoice),
        ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function pay(Request $request, string $invoiceId): JsonResponse
    {
        [$invoice] = $this->resolveInvoice($request, $invoiceId);

        if ($invoice === null) {
            return ApiResponse::error('invoice_not_found', 'The invoice could not be found for the current context.', null, Response::HTTP_NOT_FOUND);
        }

        if ($invoice->status === Invoice::STATUS_VOID) {
            return ApiResponse::error('invoice_void', 'The invoice is void and cannot be paid.', null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($invoice->status !== Invoice::STATUS_PAID) {
            DB::transaction(function () use ($invoice) {
                $now = CarbonImmutable::now();

                $payment = new Payment([
                    'invoice_id' => $invoice->id,
                    'provider' => 'stub',
                    'provider_charge_id' => 'stub-' . Str::uuid()->toString(),
                    'amount_cents' => $invoice->total_cents,
                    'currency' => 'USD',
                    'status' => Payment::STATUS_PAID,
                    'processed_at' => $now,
                ]);
                $payment->save();

                $invoice->status = Invoice::STATUS_PAID;
                $invoice->paid_at = $now;
                $invoice->save();
            });
        }

        $invoice->refresh()->load('payments');

        return response()->json([
            'data' => $this->formatInvoice($invoice),
        ]);
    }

    private function formatInvoiceSummary(Invoice $invoice): array
    {
        return [
            'id' => (string) $invoice->id,
            'tenant_id' => (string) $invoice->tenant_id,
            'status' => $invoice->status,
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'total_cents' => (int) $invoice->total_cents,
        ];
    }

    /**
     * @param array<int, string> $with
     * @return array{0: ?Invoice, 1: ?string, 2: User}
     */
    private function resolveInvoice(Request $request, string $invoiceId, array $with = ['payments']): array
    {
        $user = $request->user();
        $tenantId = $this->resolveTenantContext($request, $user);

        if ($tenantId === null && ! $this->isSuperAdmin($user)) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to access invoices.'],
            ]);
        }

        $query = $this->invoiceQuery($user, $tenantId)->where('id', $invoiceId);

        if (! empty($with)) {
            $query->with($with);
        }

        /** @var Invoice|null $invoice */
        $invoice = $query->first();

        return [$invoice, $tenantId, $user];
    }

    /**
     * @return Builder<Invoice>
     */
    private function invoiceQuery(User $user, ?string $tenantId): Builder
    {
        $query = Invoice::query();

        if (! $this->isSuperAdmin($user)) {
            $query->where('tenant_id', $tenantId);
        } elseif ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    private function canExportInvoicePdf(?string $tenantId): bool
    {
        if ($tenantId === null) {
            return true;
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return false;
        }

        $subscription = $tenant->activeSubscription();

        if ($subscription === null) {
            return false;
        }

        $plan = $subscription->plan;

        if ($plan === null) {
            return false;
        }

        $features = is_array($plan->features_json) ? $plan->features_json : [];

        return (bool) Arr::get($features, 'exports.pdf', true);
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function formatPreview(string $tenantId, array $preview): array
    {
        return [
            'tenant_id' => (string) $tenantId,
            'period_start' => $preview['period_start']->toIso8601String(),
            'period_end' => $preview['period_end']->toIso8601String(),
            'subtotal_cents' => (int) $preview['subtotal_cents'],
            'tax_cents' => (int) $preview['tax_cents'],
            'total_cents' => (int) $preview['total_cents'],
            'line_items' => array_map(fn (array $item): array => $this->formatLineItem($item), $preview['line_items']),
        ];
    }

    private function formatInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('payments');

        return [
            'id' => (string) $invoice->id,
            'tenant_id' => (string) $invoice->tenant_id,
            'status' => $invoice->status,
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'subtotal_cents' => (int) $invoice->subtotal_cents,
            'tax_cents' => (int) $invoice->tax_cents,
            'total_cents' => (int) $invoice->total_cents,
            'line_items' => array_map(fn (array $item): array => $this->formatLineItem($item), $invoice->line_items_json ?? []),
            'payments' => $invoice->payments->map(fn (Payment $payment): array => [
                'id' => (string) $payment->id,
                'provider' => $payment->provider,
                'provider_charge_id' => $payment->provider_charge_id,
                'amount_cents' => (int) $payment->amount_cents,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'processed_at' => $payment->processed_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function formatLineItem(array $item): array
    {
        return [
            'type' => $item['type'] ?? 'custom',
            'description' => $item['description'] ?? '',
            'quantity' => (int) ($item['quantity'] ?? 0),
            'unit_price_cents' => (int) ($item['unit_price_cents'] ?? 0),
            'amount_cents' => (int) ($item['amount_cents'] ?? 0),
        ];
    }
}
