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
} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { DateTime } from 'luxon';
import { useNavigate } from 'react-router-dom';
import { CHECKIN_POLICY_LABELS, EVENT_STATUS_LABELS, extractApiErrorMessage, useEvent } from '../../hooks/useEventsApi';
import EventVenuesTab from './EventVenuesTab';

interface EventDetailProps {
  eventId: string;
}

type TabValue = 'summary' | 'venues';

const formatDateTime = (iso: string | null | undefined, timezone: string) => {
  if (!iso) return '—';
  try {
    return DateTime.fromISO(iso, { zone: timezone || undefined }).toFormat('dd/MM/yyyy HH:mm');
  } catch {
    return '—';
  }
};

const formatCapacity = (capacity: number | null | undefined) => {
  if (capacity === null || capacity === undefined) {
    return 'Sin límite definido';
  }
  return capacity.toLocaleString();
};

const DetailItem = ({ label, value }: { label: string; value: ReactNode }) => (
  <Box>
    <Typography variant="subtitle2" color="text.secondary">
      {label}
    </Typography>
    <Typography variant="body1">{value ?? '—'}</Typography>
  </Box>
);

const EventDetail = ({ eventId }: EventDetailProps) => {
  const navigate = useNavigate();
  const [tab, setTab] = useState<TabValue>('summary');
  const { data, isLoading, isError, error } = useEvent(eventId);

  const eventData = data?.data;

  const headerTitle = eventData?.name ?? 'Detalle del evento';
  const statusChip = useMemo(() => {
    if (!eventData) return null;
    const label = EVENT_STATUS_LABELS[eventData.status] ?? eventData.status;
    const color: 'default' | 'success' | 'warning' =
      eventData.status === 'published' ? 'success' : eventData.status === 'archived' ? 'warning' : 'default';
    return <Chip label={label} color={color} size="small" />;
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
            <DetailItem label="Capacidad" value={formatCapacity(eventData.capacity)} />
          </Grid>
          <Grid item xs={12} md={6}>
            <DetailItem
              label="Política de check-in"
              value={CHECKIN_POLICY_LABELS[eventData.checkin_policy] ?? eventData.checkin_policy}
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
              Consulta los detalles del evento y administra los venues asociados.
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
            <Tab label="Venues" value="venues" />
          </Tabs>
          <Box sx={{ p: { xs: 2, md: 3 } }}>
            {tab === 'summary' ? summaryContent() : <EventVenuesTab eventId={eventId} />}
          </Box>
        </Paper>
      </Stack>
    </Container>
  );
};

export default EventDetail;
