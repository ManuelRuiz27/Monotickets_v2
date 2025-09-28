import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import {
  Alert,
  Box,
  Breadcrumbs,
  CircularProgress,
  Link,
  Paper,
  Stack,
  Tab,
  Tabs,
  Typography,
} from '@mui/material';
import type { SyntheticEvent } from 'react';
import { useEvent } from '../../hooks/useEventsApi';
import { useGuest } from '../../hooks/useGuestsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';
import { useToast } from '../common/ToastProvider';
import GuestTicketsTab from './GuestTicketsTab';

type TabValue = 'summary' | 'tickets';

interface GuestDetailProps {
  eventId: string;
  guestId: string;
}

const GuestDetail = ({ eventId, guestId }: GuestDetailProps) => {
  const [tab, setTab] = useState<TabValue>('summary');
  const { showToast } = useToast();

  const eventQuery = useEvent(eventId);
  const guestQuery = useGuest(guestId);

  const event = eventQuery.data?.data;
  const guest = guestQuery.data?.data;

  useEffect(() => {
    if (guestQuery.isError && guestQuery.error) {
      showToast({
        message: extractApiErrorMessage(guestQuery.error, 'No se pudo cargar la información del invitado.'),
        severity: 'error',
      });
    }
  }, [guestQuery.isError, guestQuery.error, showToast]);

  useEffect(() => {
    if (eventQuery.isError && eventQuery.error) {
      showToast({
        message: extractApiErrorMessage(eventQuery.error, 'No se pudo cargar la información del evento.'),
        severity: 'error',
      });
    }
  }, [eventQuery.isError, eventQuery.error, showToast]);

  useEffect(() => {
    if (guest) {
      setTab('tickets');
    }
  }, [guest]);

  const handleChangeTab = (_event: SyntheticEvent, value: TabValue) => {
    setTab(value);
  };

  const summaryItems = useMemo(() => {
    if (!guest) {
      return [] as Array<{ label: string; value: string }>;
    }

    return [
      { label: 'Nombre', value: guest.full_name },
      { label: 'Correo', value: guest.email ?? '—' },
      { label: 'Teléfono', value: guest.phone ?? '—' },
      { label: 'Organización', value: guest.organization ?? '—' },
      {
        label: 'Acompañantes permitidos',
        value: guest.allow_plus_ones ? String(guest.plus_ones_limit ?? 0) : 'No permitidos',
      },
    ];
  }, [guest]);

  const isLoading = guestQuery.isLoading || eventQuery.isLoading;

  return (
    <Box px={{ xs: 2, md: 3 }} py={3}>
      <Stack spacing={3}>
        <Stack spacing={1}>
          <Breadcrumbs aria-label="breadcrumb">
            <Link component={RouterLink} color="inherit" to="/events">
              Eventos
            </Link>
            {event && (
              <Link component={RouterLink} color="inherit" to={`/events/${event.id}`}>
                {event.name}
              </Link>
            )}
            <Typography color="text.primary">Invitado</Typography>
          </Breadcrumbs>
          <Stack spacing={0.5}>
            <Typography variant="h4">{guest?.full_name ?? 'Detalle de invitado'}</Typography>
            {guest?.organization && (
              <Typography variant="body2" color="text.secondary">
                {guest.organization}
              </Typography>
            )}
          </Stack>
        </Stack>

        {isLoading ? (
          <Box py={8} display="flex" justifyContent="center" alignItems="center">
            <CircularProgress />
          </Box>
        ) : guestQuery.isError ? (
          <Alert severity="error">
            {extractApiErrorMessage(guestQuery.error, 'No se encontró la información del invitado.')}
          </Alert>
        ) : !guest ? (
          <Alert severity="warning">No se encontró la información del invitado.</Alert>
        ) : (
          <Stack spacing={2}>
            <Paper elevation={0}>
              <Tabs value={tab} onChange={handleChangeTab} indicatorColor="primary" textColor="primary">
                <Tab label="Resumen" value="summary" />
                <Tab label="Tickets" value="tickets" />
              </Tabs>
            </Paper>

            {tab === 'summary' && (
              <Paper elevation={0} sx={{ p: 2 }}>
                <Stack spacing={1.5}>
                  {summaryItems.map((item) => (
                    <Stack key={item.label} direction="row" spacing={2}>
                      <Typography variant="subtitle2" sx={{ minWidth: 180 }}>
                        {item.label}
                      </Typography>
                      <Typography variant="body2" color="text.secondary">
                        {item.value}
                      </Typography>
                    </Stack>
                  ))}
                </Stack>
              </Paper>
            )}

            {tab === 'tickets' && <GuestTicketsTab guest={guest} />}
          </Stack>
        )}
      </Stack>
    </Box>
  );
};

export default GuestDetail;
