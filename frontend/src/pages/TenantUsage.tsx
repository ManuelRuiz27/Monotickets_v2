import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Container,
  Grid,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { DateTime } from 'luxon';
import { useNavigate, useParams } from 'react-router-dom';
import { useAdminTenantUsage } from '../hooks/useAdminTenantUsage';
import KpiCard from '../components/charts/KpiCard';
import Sparkline from '../components/charts/Sparkline';
import Donut from '../components/charts/Donut';

const TenantUsage = () => {
  const navigate = useNavigate();
  const { tenantId } = useParams<{ tenantId: string }>();

  const [fromInput, setFromInput] = useState('');
  const [toInput, setToInput] = useState('');
  const [appliedFilters, setAppliedFilters] = useState<{ from?: string; to?: string }>({});

  const usageQuery = useAdminTenantUsage(tenantId, appliedFilters);

  const tenant = usageQuery.data?.meta.tenant;
  const usageData = usageQuery.data?.data ?? [];
  const requestedPeriod = usageQuery.data?.meta.requested_period;

  const effectiveLimits = tenant?.effective_limits as Record<string, unknown> | undefined;

  const describeLimit = (key: string) => {
    const value = effectiveLimits?.[key];
    if (typeof value === 'number') {
      return value;
    }
    if (typeof value === 'string') {
      return value;
    }
    return '—';
  };

  const sparklineData = useMemo(() => usageData.map((entry) => entry.scan_total), [usageData]);
  const latestBreakdown = usageData[usageData.length - 1]?.scan_breakdown ?? [];

  const donutData = latestBreakdown.map((entry) => ({
    label: entry.event_name ?? entry.event_id,
    value: entry.value,
  }));

  const handleApplyFilters = () => {
    const nextFilters: { from?: string; to?: string } = {};
    if (fromInput) {
      const month = DateTime.fromISO(`${fromInput}-01`);
      if (month.isValid) {
        nextFilters.from = month.startOf('month').toISODate() ?? undefined;
      }
    }
    if (toInput) {
      const month = DateTime.fromISO(`${toInput}-01`);
      if (month.isValid) {
        nextFilters.to = month.endOf('month').toISODate() ?? undefined;
      }
    }
    setAppliedFilters(nextFilters);
  };

  const handleResetFilters = () => {
    setFromInput('');
    setToInput('');
    setAppliedFilters({});
  };

  const formatPeriod = (start: string, end: string) => {
    try {
      const from = DateTime.fromISO(start).toFormat('LLL yyyy');
      const to = DateTime.fromISO(end).toFormat('LLL yyyy');
      return from === to ? from : `${from} – ${to}`;
    } catch {
      return `${start} – ${end}`;
    }
  };

  return (
    <Container maxWidth="xl" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Stack direction="row" spacing={2} alignItems="center">
          <Button variant="text" startIcon={<ArrowBackIcon />} onClick={() => navigate('/admin/tenants')}>
            Volver
          </Button>
        </Stack>

        <Stack spacing={1}>
          <Typography variant="h4" component="h1">
            Uso de {tenant?.name ?? tenant?.slug ?? tenantId}
          </Typography>
          {tenant?.plan && (
            <Typography variant="body2" color="text.secondary">
              Plan: {tenant.plan.name} · {tenant.plan.billing_cycle === 'yearly' ? 'Anual' : 'Mensual'}
            </Typography>
          )}
          {requestedPeriod && (
            <Typography variant="body2" color="text.secondary">
              Periodo consultado: {formatPeriod(requestedPeriod.from, requestedPeriod.to)}
            </Typography>
          )}
        </Stack>

        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ xs: 'stretch', md: 'flex-end' }}>
            <TextField
              label="Desde"
              type="month"
              value={fromInput}
              onChange={(event) => setFromInput(event.target.value)}
              InputLabelProps={{ shrink: true }}
              sx={{ minWidth: { xs: '100%', md: 200 } }}
            />
            <TextField
              label="Hasta"
              type="month"
              value={toInput}
              onChange={(event) => setToInput(event.target.value)}
              InputLabelProps={{ shrink: true }}
              sx={{ minWidth: { xs: '100%', md: 200 } }}
            />
            <Box sx={{ flexGrow: 1 }} />
            <Button onClick={handleResetFilters}>Limpiar</Button>
            <Button variant="contained" onClick={handleApplyFilters}>
              Aplicar filtros
            </Button>
          </Stack>
        </Paper>

        {usageQuery.isLoading && (
          <Box display="flex" justifyContent="center" py={4}>
            <CircularProgress />
          </Box>
        )}

        {usageQuery.isError && <Alert severity="error">No se pudo cargar el uso del tenant.</Alert>}

        {!usageQuery.isLoading && !usageQuery.isError && (
          <Stack spacing={3}>
            <Grid container spacing={2}>
              <Grid item xs={12} md={4}>
              <KpiCard
                label="Eventos activos (mes actual)"
                value={tenant?.usage.event_count ?? 0}
                subvalue={`Límite: ${describeLimit('max_events')}`}
              />
            </Grid>
            <Grid item xs={12} md={4}>
              <KpiCard
                label="Usuarios activos (mes actual)"
                value={tenant?.usage.user_count ?? 0}
                subvalue={`Límite: ${describeLimit('max_users')}`}
              />
            </Grid>
              <Grid item xs={12} md={4}>
                <KpiCard
                  label="Escaneos totales (mes actual)"
                  value={tenant?.usage.scan_count ?? 0}
                  subvalue={`Eventos: ${tenant?.usage.event_count ?? 0}`}
                />
              </Grid>
            </Grid>

            <Paper variant="outlined" sx={{ p: 3 }}>
              <Typography variant="h6" gutterBottom>
                Evolución de escaneos
              </Typography>
              <Sparkline
                data={sparklineData}
                width={720}
                height={120}
                ariaLabel="Escaneos totales por periodo"
              />
            </Paper>

            <Paper variant="outlined" sx={{ p: 3 }}>
              <Typography variant="h6" gutterBottom>
                Distribución de escaneos del último periodo
              </Typography>
              <Donut data={donutData} ariaLabel="Distribución de escaneos por evento" />
            </Paper>

            <Paper variant="outlined">
              <TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>Periodo</TableCell>
                      <TableCell align="right">Eventos</TableCell>
                      <TableCell align="right">Usuarios</TableCell>
                      <TableCell align="right">Escaneos</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {usageData.map((entry) => (
                      <TableRow key={entry.period_start}>
                        <TableCell>{formatPeriod(entry.period_start, entry.period_end)}</TableCell>
                        <TableCell align="right">{entry.event_count.toLocaleString('es-MX')}</TableCell>
                        <TableCell align="right">{entry.user_count.toLocaleString('es-MX')}</TableCell>
                        <TableCell align="right">{entry.scan_total.toLocaleString('es-MX')}</TableCell>
                      </TableRow>
                    ))}
                    {usageData.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={4}>
                          <Box py={4} textAlign="center" color="text.secondary">
                            No hay registros en el rango seleccionado.
                          </Box>
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </TableContainer>
            </Paper>
          </Stack>
        )}
      </Stack>
    </Container>
  );
};

export default TenantUsage;
