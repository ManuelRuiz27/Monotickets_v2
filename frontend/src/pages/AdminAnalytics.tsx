import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardActions,
  CardContent,
  CircularProgress,
  Container,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import { DateTime } from 'luxon';
import Sparkline from '../components/charts/Sparkline';
import { useAdminAnalytics, type AdminAnalyticsFilters } from '../hooks/useAdminAnalytics';
import { extractApiErrorMessage } from '../utils/apiErrors';

const formatDateTime = (iso: string | null | undefined, timezone?: string | null) => {
  if (!iso) {
    return 'Sin fecha';
  }

  try {
    const dt = DateTime.fromISO(iso, { zone: timezone ?? undefined });
    return dt.toFormat("dd/MM/yyyy HH:mm 'hrs' (z)");
  } catch {
    return 'Sin fecha';
  }
};

const formatOccupancy = (value: number | null | undefined) => {
  if (value === null || value === undefined) {
    return '—';
  }

  return new Intl.NumberFormat('es-MX', {
    style: 'percent',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value);
};

const AdminAnalytics = () => {
  const [tenantFilter, setTenantFilter] = useState('');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');

  const filters: AdminAnalyticsFilters = useMemo(
    () => ({
      tenantId: tenantFilter || undefined,
      from: fromDate || undefined,
      to: toDate || undefined,
    }),
    [tenantFilter, fromDate, toDate],
  );

  const query = useAdminAnalytics(filters);
  const tenants = query.data?.meta.tenants ?? [];
  const cards = query.data?.data ?? [];

  const errorMessage = query.isError
    ? extractApiErrorMessage(query.error, 'No se pudo cargar la analítica global.')
    : null;

  const handleResetFilters = () => {
    setTenantFilter('');
    setFromDate('');
    setToDate('');
  };

  return (
    <Container maxWidth="xl" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Box>
          <Typography variant="h4" component="h1" gutterBottom>
            Analítica global
          </Typography>
          <Typography variant="body1" color="text.secondary">
            Revisa el rendimiento de tus eventos publicados y navega rápidamente a sus paneles de detalle.
          </Typography>
        </Box>

        <Card>
          <CardContent>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ xs: 'stretch', md: 'flex-end' }}>
              <FormControl sx={{ minWidth: { xs: '100%', md: 220 } }} size="small">
                <InputLabel id="tenant-filter-label">Tenant</InputLabel>
                <Select
                  labelId="tenant-filter-label"
                  label="Tenant"
                  value={tenantFilter}
                  onChange={(event) => setTenantFilter(event.target.value)}
                >
                  <MenuItem value="">
                    <em>Todos los tenants</em>
                  </MenuItem>
                  {tenants.map((tenant) => (
                    <MenuItem key={tenant.id} value={tenant.id}>
                      {tenant.name ?? tenant.slug ?? tenant.id}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>

              <TextField
                label="Desde"
                type="date"
                size="small"
                value={fromDate}
                onChange={(event) => setFromDate(event.target.value)}
                InputLabelProps={{ shrink: true }}
              />

              <TextField
                label="Hasta"
                type="date"
                size="small"
                value={toDate}
                onChange={(event) => setToDate(event.target.value)}
                InputLabelProps={{ shrink: true }}
              />

              <Box sx={{ flexGrow: 1 }} />

              <Button onClick={handleResetFilters}>Limpiar filtros</Button>
            </Stack>
          </CardContent>
        </Card>

        {query.isLoading && (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
            <CircularProgress />
          </Box>
        )}

        {errorMessage && <Alert severity="error">{errorMessage}</Alert>}

        {!query.isLoading && !errorMessage && cards.length === 0 && (
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                No hay eventos para mostrar
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Ajusta los filtros seleccionados o revisa que existan eventos publicados en el rango solicitado.
              </Typography>
            </CardContent>
          </Card>
        )}

        <Grid container spacing={3} alignItems="stretch">
          {cards.map((card) => {
            const attendanceSeries = card.attendance.map((entry) => entry.valid);
            const tenantLabel = tenants.find((tenant) => tenant.id === card.event.tenant_id);

            return (
              <Grid item xs={12} md={6} lg={4} key={card.event.id}>
                <Card sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
                  <CardContent sx={{ flexGrow: 1 }}>
                    <Stack spacing={2}>
                      <Stack direction="row" spacing={2} alignItems="flex-start" justifyContent="space-between">
                        <Box>
                          <Typography variant="overline" color="text.secondary">
                            {tenantLabel?.name ?? tenantLabel?.slug ?? card.event.tenant_id ?? 'Sin tenant'}
                          </Typography>
                          <Typography variant="h6" component="h2">
                            {card.event.name}
                          </Typography>
                          <Typography variant="body2" color="text.secondary">
                            {formatDateTime(card.event.start_at, card.event.timezone)}
                          </Typography>
                        </Box>
                        <Sparkline
                          data={attendanceSeries}
                          ariaLabel={`Serie de asistencias válidas por hora para ${card.event.name}`}
                        />
                      </Stack>

                      <Grid container spacing={2}>
                        <Grid item xs={6} sm={4}>
                          <Metric label="Invitados" value={card.overview.invited.toLocaleString()} />
                        </Grid>
                        <Grid item xs={6} sm={4}>
                          <Metric label="Confirmados" value={card.overview.confirmed.toLocaleString()} />
                        </Grid>
                        <Grid item xs={6} sm={4}>
                          <Metric label="Asistencias" value={card.overview.attendances.toLocaleString()} />
                        </Grid>
                        <Grid item xs={6} sm={4}>
                          <Metric label="Duplicados" value={card.overview.duplicates.toLocaleString()} />
                        </Grid>
                        <Grid item xs={6} sm={4}>
                          <Metric label="Únicos" value={card.overview.unique_attendees.toLocaleString()} />
                        </Grid>
                        <Grid item xs={6} sm={4}>
                          <Metric label="Ocupación" value={formatOccupancy(card.overview.occupancy_rate)} />
                        </Grid>
                      </Grid>
                    </Stack>
                  </CardContent>
                  <CardActions sx={{ justifyContent: 'space-between' }}>
                    <Typography variant="caption" color="text.secondary">
                      {card.attendance.length > 0
                        ? `${card.attendance[card.attendance.length - 1].valid.toLocaleString()} asistencias en la última hora registrada`
                        : 'Sin actividad registrada'}
                    </Typography>
                    <Button component={RouterLink} to={`/events/${card.event.id}`} size="small">
                      Ver detalle
                    </Button>
                  </CardActions>
                </Card>
              </Grid>
            );
          })}
        </Grid>
      </Stack>
    </Container>
  );
};

interface MetricProps {
  label: string;
  value: string | number;
}

const Metric = ({ label, value }: MetricProps) => {
  return (
    <Box>
      <Typography variant="overline" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="h6" component="p">
        {value}
      </Typography>
    </Box>
  );
};

export default AdminAnalytics;
