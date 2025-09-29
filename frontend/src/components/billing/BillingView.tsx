import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Container,
  Divider,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import PaymentIcon from '@mui/icons-material/Payment';
import RefreshIcon from '@mui/icons-material/Refresh';
import { saveAs } from 'file-saver';
import { extractApiErrorMessage } from '../../utils/apiErrors';
import { useToast } from '../common/ToastProvider';
import {
  fetchInvoicePdf,
  InvoiceDetail,
  InvoiceStatus,
  InvoiceSummary,
  payInvoice,
  useTenantInvoice,
  useTenantInvoices,
} from '../../hooks/useTenantInvoices';

const currencyFormatter = new Intl.NumberFormat('es-CL', {
  style: 'currency',
  currency: 'USD',
});

const formatCurrency = (valueCents: number) => currencyFormatter.format((valueCents ?? 0) / 100);

const formatDate = (value: string | null) => {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }
  return date.toLocaleDateString('es-CL', { year: 'numeric', month: 'short', day: 'numeric' });
};

const statusConfig: Record<InvoiceStatus, { label: string; color: 'default' | 'success' | 'warning' | 'info' | 'error' | 'primary' | 'secondary' }> = {
  pending: { label: 'Pendiente', color: 'warning' },
  paid: { label: 'Pagada', color: 'success' },
  void: { label: 'Anulada', color: 'default' },
};

const buildStatus = (status: InvoiceStatus) => statusConfig[status] ?? { label: status, color: 'default' as const };

const periodLabel = (invoice: InvoiceSummary) => {
  const start = formatDate(invoice.period_start);
  const end = formatDate(invoice.period_end);
  if (start === '—' && end === '—') {
    return '—';
  }
  return `${start} – ${end}`;
};

const BillingView = () => {
  const { data: listResponse, isLoading: listLoading, isError: listIsError, error: listError, refetch: refetchList } = useTenantInvoices();
  const invoices = listResponse?.data ?? [];
  const canExportPdf = Boolean(listResponse?.meta?.can_export_pdf ?? false);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [isPaying, setIsPaying] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const { showToast } = useToast();

  useEffect(() => {
    if (invoices.length === 0) {
      setSelectedId(null);
      return;
    }
    setSelectedId((prev) => {
      if (prev && invoices.some((invoice) => invoice.id === prev)) {
        return prev;
      }
      return invoices[0].id;
    });
  }, [invoices]);

  const {
    data: invoiceResponse,
    isLoading: detailLoading,
    isFetching: detailFetching,
    error: detailError,
    refetch: refetchDetail,
  } = useTenantInvoice(selectedId ?? undefined);

  const selectedInvoice = invoiceResponse?.data ?? null;
  const isDetailLoading = detailLoading || detailFetching;

  const handleSelectInvoice = useCallback((invoiceId: string) => {
    setSelectedId(invoiceId);
  }, []);

  const handlePayInvoice = useCallback(async () => {
    if (!selectedId) return;
    setIsPaying(true);
    try {
      const updated = await payInvoice(selectedId);
      showToast({
        severity: 'success',
        message: 'La factura fue marcada como pagada exitosamente.',
      });
      await Promise.all([refetchList(), refetchDetail()]);
      setSelectedId(updated.id);
    } catch (error) {
      showToast({
        severity: 'error',
        message: extractApiErrorMessage(error, 'No pudimos registrar el pago. Inténtalo nuevamente.'),
      });
    } finally {
      setIsPaying(false);
    }
  }, [selectedId, refetchList, refetchDetail, showToast]);

  const handleDownloadPdf = useCallback(async () => {
    if (!selectedId || !canExportPdf) return;
    setIsDownloading(true);
    try {
      const blob = await fetchInvoicePdf(selectedId);
      const filename = `factura-${selectedId}.pdf`;
      saveAs(blob, filename);
      showToast({
        severity: 'success',
        message: 'Descargamos la factura en PDF.',
      });
    } catch (error) {
      showToast({
        severity: 'error',
        message: extractApiErrorMessage(error, 'No pudimos descargar la factura en PDF.'),
      });
    } finally {
      setIsDownloading(false);
    }
  }, [selectedId, canExportPdf, showToast]);

  const handleRefresh = useCallback(() => {
    void refetchList();
    if (selectedId) {
      void refetchDetail();
    }
  }, [refetchList, refetchDetail, selectedId]);

  const outstandingPayments = useMemo(() => {
    if (!selectedInvoice) return [] as InvoiceDetail['payments'];
    return selectedInvoice.payments ?? [];
  }, [selectedInvoice]);

  return (
    <Container maxWidth="lg" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }} spacing={2}>
          <Box>
            <Typography variant="h4" component="h1">
              Facturación
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Consulta el historial de facturas del tenant, registra pagos manuales y descarga los detalles.
            </Typography>
          </Box>
          <Button variant="outlined" startIcon={<RefreshIcon />} onClick={handleRefresh} disabled={listLoading}>
            Actualizar
          </Button>
        </Stack>

        {listIsError ? (
          <Alert severity="error">{extractApiErrorMessage(listError, 'No fue posible cargar las facturas.')}</Alert>
        ) : null}

        <Stack direction={{ xs: 'column', lg: 'row' }} spacing={3} alignItems="stretch">
          <Paper variant="outlined" sx={{ flex: { lg: 1 }, p: 2 }}>
            <Stack spacing={2}>
              <Typography variant="h6">Facturas emitidas</Typography>
              {listLoading ? (
                <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
                  <CircularProgress size={32} />
                </Box>
              ) : invoices.length === 0 ? (
                <Typography variant="body2" color="text.secondary">
                  Aún no se han generado facturas para este tenant.
                </Typography>
              ) : (
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>Periodo</TableCell>
                      <TableCell>Total</TableCell>
                      <TableCell>Estado</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {invoices.map((invoice) => {
                      const status = buildStatus(invoice.status);
                      const isSelected = invoice.id === selectedId;
                      return (
                        <TableRow
                          key={invoice.id}
                          hover
                          selected={isSelected}
                          onClick={() => handleSelectInvoice(invoice.id)}
                          sx={{ cursor: 'pointer' }}
                        >
                          <TableCell>{periodLabel(invoice)}</TableCell>
                          <TableCell>{formatCurrency(invoice.total_cents)}</TableCell>
                          <TableCell>
                            <Chip label={status.label} color={status.color} size="small" />
                          </TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              )}
            </Stack>
          </Paper>

          <Paper variant="outlined" sx={{ flex: { lg: 1.2 }, p: 3, minHeight: 360 }}>
            {selectedId === null ? (
              <Typography variant="body2" color="text.secondary">
                Selecciona una factura para ver los detalles.
              </Typography>
            ) : isDetailLoading && !selectedInvoice ? (
              <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
                <CircularProgress size={32} />
              </Box>
            ) : detailError ? (
              <Alert severity="error">{extractApiErrorMessage(detailError, 'No pudimos cargar el detalle de la factura.')}</Alert>
            ) : selectedInvoice ? (
              <Stack spacing={3}>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }}>
                  <div>
                    <Typography variant="subtitle1" color="text.secondary">
                      Factura #{selectedInvoice.id}
                    </Typography>
                    <Typography variant="h5" component="h2" sx={{ mt: 0.5 }}>
                      {formatCurrency(selectedInvoice.total_cents)}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      Periodo {periodLabel(selectedInvoice)}
                    </Typography>
                  </div>
                  <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                    <Chip label={buildStatus(selectedInvoice.status).label} color={buildStatus(selectedInvoice.status).color} />
                    <Button
                      variant="contained"
                      startIcon={<PaymentIcon />}
                      disabled={selectedInvoice.status !== 'pending' || isPaying}
                      onClick={handlePayInvoice}
                    >
                      {isPaying ? 'Registrando…' : 'Registrar pago'}
                    </Button>
                    <Button
                      variant="outlined"
                      startIcon={<PictureAsPdfIcon />}
                      disabled={!canExportPdf || isDownloading}
                      onClick={handleDownloadPdf}
                    >
                      {isDownloading ? 'Descargando…' : 'Exportar PDF'}
                    </Button>
                  </Stack>
                </Stack>

                <Divider />

                <Stack direction={{ xs: 'column', md: 'row' }} spacing={3}>
                  <Box sx={{ flex: 1 }}>
                    <Typography variant="subtitle2" color="text.secondary">
                      Emisión
                    </Typography>
                    <Typography variant="body2">Emitida: {formatDate(selectedInvoice.issued_at)}</Typography>
                    <Typography variant="body2">Vence: {formatDate(selectedInvoice.due_at)}</Typography>
                    <Typography variant="body2">Pagada: {formatDate(selectedInvoice.paid_at)}</Typography>
                  </Box>
                  <Box sx={{ flex: 1 }}>
                    <Typography variant="subtitle2" color="text.secondary">
                      Totales
                    </Typography>
                    <Typography variant="body2">Subtotal: {formatCurrency(selectedInvoice.subtotal_cents)}</Typography>
                    <Typography variant="body2">Impuestos: {formatCurrency(selectedInvoice.tax_cents)}</Typography>
                    <Typography variant="body2" fontWeight={600}>
                      Total: {formatCurrency(selectedInvoice.total_cents)}
                    </Typography>
                  </Box>
                </Stack>

                <div>
                  <Typography variant="subtitle2" gutterBottom>
                    Conceptos facturados
                  </Typography>
                  {selectedInvoice.line_items.length === 0 ? (
                    <Typography variant="body2" color="text.secondary">
                      No hay line items asociados a esta factura.
                    </Typography>
                  ) : (
                    <Table size="small">
                      <TableHead>
                        <TableRow>
                          <TableCell>Descripción</TableCell>
                          <TableCell align="right">Cantidad</TableCell>
                          <TableCell align="right">Precio unitario</TableCell>
                          <TableCell align="right">Importe</TableCell>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {selectedInvoice.line_items.map((item, index) => (
                          <TableRow key={`${item.type}-${index}`}>
                            <TableCell>{item.description || item.type}</TableCell>
                            <TableCell align="right">{item.quantity}</TableCell>
                            <TableCell align="right">{formatCurrency(item.unit_price_cents)}</TableCell>
                            <TableCell align="right">{formatCurrency(item.amount_cents)}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  )}
                </div>

                <div>
                  <Typography variant="subtitle2" gutterBottom>
                    Pagos registrados
                  </Typography>
                  {outstandingPayments.length === 0 ? (
                    <Typography variant="body2" color="text.secondary">
                      No se registran pagos para esta factura.
                    </Typography>
                  ) : (
                    <Stack spacing={1}>
                      {outstandingPayments.map((payment) => (
                        <Paper key={payment.id} variant="outlined" sx={{ p: 1.5 }}>
                          <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" spacing={1}>
                            <Typography variant="subtitle2">{payment.provider.toUpperCase()}</Typography>
                            <Typography variant="body2" color="text.secondary">
                              {formatDate(payment.processed_at)}
                            </Typography>
                          </Stack>
                          <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" spacing={1}>
                            <Typography variant="body2">Cargo #{payment.provider_charge_id}</Typography>
                            <Typography variant="body2" fontWeight={600}>
                              {formatCurrency(payment.amount_cents)}
                            </Typography>
                          </Stack>
                        </Paper>
                      ))}
                    </Stack>
                  )}
                </div>
              </Stack>
            ) : (
              <Typography variant="body2" color="text.secondary">
                No encontramos información para la factura seleccionada.
              </Typography>
            )}
          </Paper>
        </Stack>
      </Stack>
    </Container>
  );
};

export default BillingView;
