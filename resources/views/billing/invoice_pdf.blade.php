<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ $invoice->id }}</title>
    <style>
        :root {
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --border-color: #e2e8f0;
            --accent: #2563eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Helvetica", "Arial", sans-serif;
            font-size: 12px;
            color: var(--text-primary);
            margin: 24px;
            line-height: 1.5;
        }

        h1, h2, h3 {
            margin: 0;
            color: var(--text-primary);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .header-meta {
            text-align: right;
        }

        .header-meta span {
            display: block;
            color: var(--text-secondary);
        }

        .section {
            margin-bottom: 24px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .summary-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px;
        }

        .summary-card span {
            display: block;
        }

        .summary-label {
            font-size: 10px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid var(--border-color);
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f8fafc;
            color: var(--text-secondary);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tfoot td {
            font-weight: bold;
        }

        .totals {
            margin-top: 12px;
            width: 40%;
            margin-left: auto;
        }

        .totals td {
            border: none;
            padding: 4px 0;
        }

        .totals tr:last-child td {
            border-top: 1px solid var(--border-color);
            padding-top: 8px;
        }

        .status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #b45309;
        }

        .status-paid {
            background-color: #dcfce7;
            color: #166534;
        }

        .status-void {
            background-color: #e2e8f0;
            color: #1e293b;
        }

        .payments-table td {
            border: none;
            padding: 6px 0;
        }

        .payments-table tr + tr td {
            border-top: 1px solid var(--border-color);
        }
    </style>
</head>
<body>
@php
    /** @var \App\Models\Invoice $invoice */
    /** @var \App\Models\Tenant|null $tenant */
    $formatCurrency = static fn (int $amount): string => '$' . number_format($amount / 100, 2, ',', '.');
    $formatDate = static fn (?\Carbon\CarbonImmutable $date): string => $date?->timezone('UTC')->format('d/m/Y') ?? '—';
    $statusClasses = [
        'pending' => 'status status-pending',
        'paid' => 'status status-paid',
        'void' => 'status status-void',
    ];
    $statusLabels = [
        'pending' => 'Pendiente',
        'paid' => 'Pagada',
        'void' => 'Anulada',
    ];
    $statusClass = $statusClasses[$invoice->status] ?? 'status';
    $statusLabel = $statusLabels[$invoice->status] ?? ucfirst($invoice->status);
    $lineItems = is_array($invoice->line_items_json) ? $invoice->line_items_json : [];
    $payments = $invoice->relationLoaded('payments') ? $invoice->payments : collect();
@endphp
    <div class="header">
        <div>
            <h1>Factura</h1>
            <span style="color: var(--text-secondary);">{{ $tenant?->name ?? 'Tenant sin nombre' }}</span>
        </div>
        <div class="header-meta">
            <span>Número</span>
            <strong>{{ $invoice->id }}</strong>
            <span style="margin-top: 8px;">Estado</span>
            <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
        </div>
    </div>

    <div class="section">
        <div class="summary-grid">
            <div class="summary-card">
                <span class="summary-label">Periodo</span>
                <span>{{ $formatDate($invoice->period_start) }} – {{ $formatDate($invoice->period_end) }}</span>
            </div>
            <div class="summary-card">
                <span class="summary-label">Emitida</span>
                <span>{{ $formatDate($invoice->issued_at) }}</span>
                <span class="summary-label" style="margin-top: 8px;">Vencimiento</span>
                <span>{{ $formatDate($invoice->due_at) }}</span>
            </div>
            <div class="summary-card">
                <span class="summary-label">Total</span>
                <span style="font-size: 16px; font-weight: 600;">{{ $formatCurrency((int) $invoice->total_cents) }}</span>
                <span class="summary-label" style="margin-top: 8px;">Pagada</span>
                <span>{{ $formatDate($invoice->paid_at) }}</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Detalle de conceptos</h2>
        <table>
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th>Cantidad</th>
                    <th>Precio unitario</th>
                    <th>Importe</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($lineItems as $item)
                <tr>
                    <td>{{ $item['description'] ?? ucfirst($item['type'] ?? 'Concepto') }}</td>
                    <td>{{ number_format((int) ($item['quantity'] ?? 0)) }}</td>
                    <td>{{ $formatCurrency((int) ($item['unit_price_cents'] ?? 0)) }}</td>
                    <td>{{ $formatCurrency((int) ($item['amount_cents'] ?? 0)) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align: center; color: var(--text-secondary);">No hay conceptos registrados para esta factura.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td style="color: var(--text-secondary);">Subtotal</td>
                <td style="text-align: right;">{{ $formatCurrency((int) $invoice->subtotal_cents) }}</td>
            </tr>
            <tr>
                <td style="color: var(--text-secondary);">Impuestos</td>
                <td style="text-align: right;">{{ $formatCurrency((int) $invoice->tax_cents) }}</td>
            </tr>
            <tr>
                <td>Total</td>
                <td style="text-align: right; font-size: 14px;">{{ $formatCurrency((int) $invoice->total_cents) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Pagos registrados</h2>
        @if ($payments->isEmpty())
            <p style="color: var(--text-secondary);">No se han registrado pagos para esta factura.</p>
        @else
            <table class="payments-table">
                <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td><strong>{{ strtoupper($payment->provider) }}</strong></td>
                        <td>{{ $formatCurrency((int) $payment->amount_cents) }}</td>
                        <td>{{ $payment->processed_at ? $payment->processed_at->timezone('UTC')->format('d/m/Y H:i') : '—' }}</td>
                        <td style="color: var(--text-secondary);">{{ $payment->provider_charge_id }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</body>
</html>
