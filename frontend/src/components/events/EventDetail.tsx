import { useMemo, useState, type ReactNode } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Container,
  Grid,
  Paper,
  Stack,
  Tab,
  Tabs,
  Typography,
  LinearProgress,
} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { DateTime } from 'luxon';
import { useNavigate } from 'react-router-dom';
import { CHECKIN_POLICY_LABELS, type CheckinPolicy, type EventResource, useEvent } from '../../hooks/useEventsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';
import EventGuestsTab from './EventGuestsTab';
import EventVenuesTab from './EventVenuesTab';
import EventDashboardTab from './EventDashboardTab';
import EventStatusChip from './EventStatusChip';
import ScanSimulator from '../scans/ScanSimulator';

interface EventDetailProps {
  eventId: string;
}

type TabValue = 'summary' | 'venues' | 'guests' | 'dashboard';

const formatDateTime = (iso: string | null | undefined, timezone: string) => {
  if (!iso) return '—';
  try {
    return DateTime.fromISO(iso, { zone: timezone || undefined }).toFormat("dd/MM/yyyy HH:mm 'hrs' (z)");
  } catch {
    return '—';
  }
};

const calculateDurationHours = (startIso: string | null | undefined, endIso: string | null | undefined, timezone: string) => {
  if (!startIso || !endIso) {
    return null;
  }

  try {
    const start = DateTime.fromISO(startIso, { zone: timezone || undefined });
    const end = DateTime.fromISO(endIso, { zone: timezone || undefined });
    if (!start.isValid || !end.isValid || end <= start) {
      return null;
    }
    return end.diff(start, 'hours').hours;
  } catch {
    return null;
  }
};

const formatCapacity = (capacity: number | null | undefined) => {
  if (capacity === null || capacity === undefined) {
    return 'Sin límite definido';
  }
  return capacity.toLocaleString();
};

const resolveOccupancyRatio = (event: EventResource | null | undefined): number | null => {
  if (!event) {
    return null;
  }

  const ratio = typeof event.occupancy_percent === 'number'
    ? event.occupancy_percent
    : event.capacity && event.capacity > 0 && typeof event.capacity_used === 'number'
      ? event.capacity_used / event.capacity
      : null;

  if (ratio === null || Number.isNaN(ratio)) {
    return null;
  }

  return Math.max(0, ratio);
};

const resolveOccupancyUsage = (event: EventResource | null | undefined) => {
  const used = event?.capacity_used ?? event?.attendances_count ?? null;
  const capacity = event?.capacity ?? null;
  return { used, capacity };
};

const formatOccupancyLabel = (event: EventResource | null | undefined, percent: number | null) => {
  const { used, capacity } = resolveOccupancyUsage(event);

  if (percent === null) {
    if (used !== null) {
      return `${used.toLocaleString()} asistentes registrados`;
    }
    return 'Sin datos suficientes';
  }

  if (capacity && used !== null) {
    return `${percent}% (${used.toLocaleString()} de ${capacity.toLocaleString()} lugares)`;
  }

  return `${percent}% de ocupación estimada`;
};

const resolveOccupancyColor = (ratio: number | null): 'primary' | 'success' | 'warning' | 'error' => {
  if (ratio === null) {
    return 'primary';
  }

  if (ratio < 0.6) {
    return 'success';
  }

  if (ratio < 0.85) {
    return 'warning';
  }

  return 'error';
};

const DetailItem = ({ label, value }: { label: string; value: ReactNode }) => {
  const renderValue = () => {
    if (value === null || value === undefined) {
      return <Typography variant="body1">—</Typography>;
    }

    if (typeof value === 'string' || typeof value === 'number') {
      return <Typography variant="body1">{value}</Typography>;
    }

    return value;
  };

  return (
    <Box>
      <Typography variant="subtitle2" color="text.secondary">
        {label}
      </Typography>
      {renderValue()}
    </Box>
  );
};

const OccupancyIndicator = ({ event }: { event: EventResource }) => {
  const ratio = resolveOccupancyRatio(event);
  const percent = ratio !== null ? Math.min(100, Math.round(ratio * 100)) : null;
  const label = formatOccupancyLabel(event, percent);
  const { used, capacity } = resolveOccupancyUsage(event);
  const color = resolveOccupancyColor(ratio);

  if (percent === null) {
    return (
      <Typography variant="body1" color="text.secondary">
        {label}
      </Typography>
    );
  }

  return (
    <Stack spacing={1} aria-label={`Ocupación del evento ${event.name}`}>
      <LinearProgress
        variant="determinate"
        value={percent}
        color={color}
        sx={{ height: 10, borderRadius: 999, bgcolor: 'action.selected' }}
        aria-hidden
      />
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Stack direction="row" spacing={1} flexWrap="wrap">
        {used !== null && (
          <Chip size="small" color="secondary" label={`Asistencias: ${used.toLocaleString()}`} />
        )}
        {capacity !== null && (
          <Chip size="small" variant="outlined" label={`Capacidad: ${capacity.toLocaleString()}`} />
        )}
      </Stack>
    </Stack>
  );
};

const EventDetail = ({ eventId }: EventDetailProps) => {
  const navigate = useNavigate();
  const [tab, setTab] = useState<TabValue>('summary');
  const { data, isLoading, isError, error } = useEvent(eventId);

  const eventData = data?.data;

  const headerTitle = eventData?.name ?? 'Detalle del evento';
  const statusChip = useMemo(() => {
    if (!eventData) return null;
    return <EventStatusChip status={eventData.status} />;
  }, [eventData]);

  const formattedDuration = useMemo(() => {
    if (!eventData) return null;
    const durationHours = calculateDurationHours(eventData.start_at, eventData.end_at, eventData.timezone);
    if (durationHours === null) {
      return null;
    }
    return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2, minimumFractionDigits: 0 }).format(durationHours);
  }, [eventData]);

  const summaryContent = () => {
    if (isLoading) {
      return (
        <Box py={6} display="flex" alignItems="center" justifyContent="center">
          <CircularProgress />
        </Box>
      );
    }

    if (isError) {
      return (
        <Alert severity="error">
          {extractApiErrorMessage(error, 'No se pudo obtener la información del evento.')}
        </Alert>
      );
    }

    if (!eventData) {
      return (
        <Typography variant="body2" color="text.secondary">
          No se encontró el evento solicitado.
        </Typography>
      );
    }

    return (
      <Stack spacing={3}>
        <Box>
          <Typography variant="subtitle1" color="text.secondary">
            Descripción
          </Typography>
          <Typography variant="body1">
            {eventData.description?.trim() ? eventData.description : 'Este evento no tiene una descripción registrada.'}
          </Typography>
        </Box>
        <Grid container spacing={3}>
          <Grid item xs={12} md={6}>
            <DetailItem label="Código" value={eventData.code} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="ID del evento" value={eventData.id} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Inicio" value={formatDateTime(eventData.start_at, eventData.timezone)} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Fin" value={formatDateTime(eventData.end_at, eventData.timezone)} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Zona horaria" value={eventData.timezone} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem
              label="Duración total"
              value={formattedDuration ? `${formattedDuration} horas` : 'Se calculará cuando existan horarios válidos.'}
            />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Capacidad" value={formatCapacity(eventData.capacity)} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Ocupación" value={<OccupancyIndicator event={eventData} />} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem
              label="Política de check-in"
              value={
                CHECKIN_POLICY_LABELS[eventData.checkin_policy as CheckinPolicy] ?? eventData.checkin_policy
              }
            />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Organizador (User ID)" value={eventData.organizer_user_id} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Tenant" value={eventData.tenant_id ?? '—'} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem label="Última actualización" value={formatDateTime(eventData.updated_at ?? null, eventData.timezone)} />
          </Grid>
        </Grid>

        <ScanSimulator eventId={eventData.id} />
      </Stack>
    );
  };

  return (
    <Container maxWidth="lg" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }} spacing={2}>
          <Stack spacing={1}>
            <Box display="flex" alignItems="center" gap={2} flexWrap="wrap">
              <Typography variant="h4" component="h1">
                {headerTitle}
              </Typography>
              {statusChip}
            </Box>
            <Typography variant="body2" color="text.secondary">
              Consulta los detalles del evento y administra los invitados y venues asociados.
            </Typography>
          </Stack>
          <Button variant="text" startIcon={<ArrowBackIcon />} onClick={() => navigate('/events')}>
            Volver
          </Button>
        </Stack>
        <Paper variant="outlined">
          <Tabs
            value={tab}
            onChange={(_event, newValue: TabValue) => setTab(newValue)}
            variant="scrollable"
            allowScrollButtonsMobile
          >
            <Tab label="Resumen" value="summary" />
            <Tab label="Dashboard" value="dashboard" />
            <Tab label="Invitados" value="guests" />
            <Tab label="Venues" value="venues" />
          </Tabs>
          <Box sx={{ p: { xs: 2, md: 3 } }}>
            {tab === 'summary' && summaryContent()}
            {tab === 'dashboard' && <EventDashboardTab eventId={eventId} eventTimezone={eventData?.timezone} />}
            {tab === 'guests' && <EventGuestsTab eventId={eventId} />}
            {tab === 'venues' && <EventVenuesTab eventId={eventId} />}
          </Box>
        </Paper>
      </Stack>
    </Container>
  );
};

export default EventDetail;
