import { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Container,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { DateTime } from 'luxon';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { useEventsList, type EventResource } from '../hooks/useEventsApi';
import { useEventAnalytics, type AnalyticsCheckpointEntry } from '../hooks/useEventAnalytics';
import { extractApiErrorMessage } from '../utils/apiErrors';

const formatHourLabel = (value: string | null) => {
  if (!value) {
    return 'Sin hora';
  }

  const dt = DateTime.fromISO(value);
  if (!dt.isValid) {
    return value;
  }

  return dt.toFormat('dd/MM HH:mm');
};

const formatDateLabel = (value: string | null) => {
  if (!value) {
    return 'N/D';
  }

  const dt = DateTime.fromISO(value);
  if (!dt.isValid) {
    return value;
  }

  return dt.toFormat('dd/MM/yyyy HH:mm');
};

const buildCheckpointName = (checkpoint: AnalyticsCheckpointEntry, index: number) => {
  if (checkpoint.name) {
    return checkpoint.name;
  }
  if (checkpoint.checkpoint_id) {
    return `Checkpoint ${checkpoint.checkpoint_id.slice(0, 6)}…`;
  }
  return `Checkpoint sin nombre ${index + 1}`;
};

const Dashboard = () => {
  const [selectedEventId, setSelectedEventId] = useState<string>('');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');

  const eventsQuery = useEventsList({ page: 0, perPage: 50 });
  const events = eventsQuery.data?.data ?? [];

  useEffect(() => {
    if (!selectedEventId && events.length > 0) {
      setSelectedEventId(events[0].id);
    }
  }, [events, selectedEventId]);

  const analyticsFilters = useMemo(
    () => ({
      from: fromDate || null,
      to: toDate || null,
      hourPerPage: 48,
      checkpointPerPage: 12,
    }),
    [fromDate, toDate],
  );

  const analyticsQuery = useEventAnalytics(selectedEventId || undefined, analyticsFilters);

  const hourlySeries = analyticsQuery.data?.data.hourly.data ?? [];
  const checkpointSeries = analyticsQuery.data?.data.checkpoints.data ?? [];
  const checkpointTotals = analyticsQuery.data?.data.checkpoints.totals;
  const duplicates = analyticsQuery.data?.data.duplicates.data ?? [];
  const errors = analyticsQuery.data?.data.errors.data ?? [];

  const analyticsError = analyticsQuery.isError
    ? extractApiErrorMessage(analyticsQuery.error, 'No se pudo cargar la analítica del evento seleccionado.')
    : null;

  const eventsError = eventsQuery.isError
    ? extractApiErrorMessage(eventsQuery.error, 'No se pudieron cargar los eventos disponibles.')
    : null;

  const selectedEvent: EventResource | undefined = events.find((event) => event.id === selectedEventId);

  const hourlyChartData = useMemo(
    () =>
      hourlySeries.map((entry) => ({
        ...entry,
        hourLabel: formatHourLabel(entry.hour),
      })),
    [hourlySeries],
  );

  const checkpointChartData = useMemo(
    () =>
      checkpointSeries.map((entry, index) => ({
        name: buildCheckpointName(entry, index),
        valid: entry.valid,
        duplicate: entry.duplicate,
        invalid: entry.invalid,
      })),
    [checkpointSeries],
  );

  const resetFilters = () => {
    setFromDate('');
    setToDate('');
  };

  return (
    <Container maxWidth="xl" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Box>
          <Typography variant="h4" component="h1" gutterBottom>
            Panel de control
          </Typography>
          <Typography variant="body1" color="text.secondary">
            Visualiza la evolución de los escaneos, identifica checkpoints críticos y revisa los registros con problemas.
          </Typography>
        </Box>

        <Card>
          <CardContent>
            <Stack
              direction={{ xs: 'column', md: 'row' }}
              spacing={2}
              alignItems={{ xs: 'stretch', md: 'flex-end' }}
            >
              <FormControl fullWidth size="small">
                <InputLabel id="dashboard-event-label">Evento</InputLabel>
                <Select
                  labelId="dashboard-event-label"
                  label="Evento"
                  value={selectedEventId}
                  onChange={(event) => setSelectedEventId(event.target.value)}
                  MenuProps={{ disablePortal: true }}
                >
                  {events.map((event) => (
                    <MenuItem key={event.id} value={event.id}>
                      {event.name}
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

              <Button variant="text" onClick={resetFilters} disabled={!fromDate && !toDate}>
                Limpiar filtros
              </Button>
            </Stack>

            {selectedEvent && (
              <Stack direction="row" spacing={1.5} mt={2} flexWrap="wrap" aria-live="polite">
                <Chip label={`Capacidad: ${formatCapacity(selectedEvent.capacity)}`} color="default" />
                {typeof selectedEvent.attendances_count === 'number' && (
                  <Chip label={`Asistencias: ${selectedEvent.attendances_count.toLocaleString()}`} color="secondary" />
                )}
                {typeof selectedEvent.occupancy_percent === 'number' && (
                  <Chip
                    label={`Ocupación: ${Math.round(selectedEvent.occupancy_percent * 100)}%`}
                    color="primary"
                  />
                )}
              </Stack>
            )}
          </CardContent>
        </Card>

        {eventsQuery.isLoading && (
          <Box display="flex" justifyContent="center" py={6}>
            <CircularProgress aria-label="Cargando eventos disponibles" />
          </Box>
        )}

        {eventsError && <Alert severity="error">{eventsError}</Alert>}

        {!eventsQuery.isLoading && events.length === 0 && !eventsError && (
          <Alert severity="info">No hay eventos disponibles para mostrar en el panel.</Alert>
        )}

        {selectedEventId && (
          <>
            {analyticsQuery.isLoading && (
              <Box display="flex" justifyContent="center" py={6}>
                <CircularProgress aria-label="Cargando analítica del evento" />
              </Box>
            )}

            {analyticsError && <Alert severity="error">{analyticsError}</Alert>}

            {!analyticsQuery.isLoading && !analyticsError && (
              <Grid container spacing={3} alignItems="stretch">
                <Grid item xs={12} lg={8}>
                  <Card sx={{ height: '100%' }}>
                    <CardContent sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
                      <Typography variant="h6" component="h2" gutterBottom>
                        Escaneos por hora
                      </Typography>
                      {hourlyChartData.length === 0 ? (
                        <Box py={4} display="flex" justifyContent="center">
                          <Typography variant="body2" color="text.secondary">
                            No hay actividad registrada con los filtros actuales.
                          </Typography>
                        </Box>
                      ) : (
                        <Box sx={{ flexGrow: 1, minHeight: 320 }}>
                          <ResponsiveContainer width="100%" height="100%" aria-label="Gráfico de escaneos por hora">
                            <LineChart data={hourlyChartData} margin={{ top: 16, right: 24, left: 0, bottom: 8 }}>
                              <CartesianGrid strokeDasharray="3 3" stroke="rgba(148, 163, 184, 0.2)" />
                              <XAxis dataKey="hourLabel" tick={{ fill: '#cbd5f5' }} angle={-25} textAnchor="end" height={60} />
                              <YAxis tick={{ fill: '#cbd5f5' }} allowDecimals={false} />
                              <Tooltip
                                formatter={(value: number) => value.toLocaleString()}
                                labelFormatter={(label) => `Hora: ${label}`}
                              />
                              <Legend />
                              <Line type="monotone" dataKey="valid" name="Válidos" stroke="#38bdf8" strokeWidth={2} dot={false} />
                              <Line type="monotone" dataKey="duplicate" name="Duplicados" stroke="#facc15" strokeWidth={2} dot={false} />
                              <Line type="monotone" dataKey="unique" name="Únicos" stroke="#22c55e" strokeWidth={2} dot={false} />
                            </LineChart>
                          </ResponsiveContainer>
                        </Box>
                      )}
                    </CardContent>
                  </Card>
                </Grid>

                <Grid item xs={12} lg={4}>
                  <Card sx={{ height: '100%' }}>
                    <CardContent sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
                      <Typography variant="h6" component="h2" gutterBottom>
                        Rendimiento por checkpoint
                      </Typography>
                      {checkpointChartData.length === 0 ? (
                        <Box py={4} display="flex" justifyContent="center">
                          <Typography variant="body2" color="text.secondary">
                            No hay checkpoints con escaneos registrados.
                          </Typography>
                        </Box>
                      ) : (
                        <Box sx={{ flexGrow: 1, minHeight: 320 }}>
                          <ResponsiveContainer width="100%" height="100%" aria-label="Gráfico de checkpoints">
                            <BarChart data={checkpointChartData} layout="vertical" margin={{ top: 16, right: 24, left: 16, bottom: 8 }}>
                              <CartesianGrid strokeDasharray="3 3" stroke="rgba(148, 163, 184, 0.2)" />
                              <XAxis type="number" tick={{ fill: '#cbd5f5' }} allowDecimals={false} />
                              <YAxis type="category" dataKey="name" width={150} tick={{ fill: '#cbd5f5' }} />
                              <Tooltip formatter={(value: number) => value.toLocaleString()} />
                              <Legend />
                              <Bar dataKey="valid" name="Válidos" fill="#22c55e" radius={[4, 4, 4, 4]} />
                              <Bar dataKey="duplicate" name="Duplicados" fill="#facc15" radius={[4, 4, 4, 4]} />
                              <Bar dataKey="invalid" name="Errores" fill="#ef4444" radius={[4, 4, 4, 4]} />
                            </BarChart>
                          </ResponsiveContainer>
                        </Box>
                      )}
                      <Stack direction="row" spacing={1} mt={2} flexWrap="wrap">
                        <Chip label={`Válidos: ${checkpointTotals.valid.toLocaleString()}`} color="success" />
                        <Chip label={`Duplicados: ${checkpointTotals.duplicate.toLocaleString()}`} color="warning" />
                        <Chip label={`Errores: ${checkpointTotals.invalid.toLocaleString()}`} color="error" />
                      </Stack>
                    </CardContent>
                  </Card>
                </Grid>

                <Grid item xs={12} md={6}>
                  <Card>
                    <CardContent>
                      <Typography variant="h6" component="h2" gutterBottom>
                        Duplicados recurrentes
                      </Typography>
                      {duplicates.length === 0 ? (
                        <Typography variant="body2" color="text.secondary">
                          No se registraron duplicados en el periodo seleccionado.
                        </Typography>
                      ) : (
                        <Table size="small" aria-label="Tabla de duplicados recurrentes">
                          <TableHead>
                            <TableRow>
                              <TableCell>Ticket</TableCell>
                              <TableCell>QR</TableCell>
                              <TableCell>Ocurrencias</TableCell>
                              <TableCell>Último registro</TableCell>
                            </TableRow>
                          </TableHead>
                          <TableBody>
                            {duplicates.map((row) => (
                              <TableRow key={`${row.ticket_id ?? 'ticket'}-${row.qr_code ?? 'codigo'}`}>
                                <TableCell>{row.ticket_id ?? 'Sin ID'}</TableCell>
                                <TableCell>{row.qr_code ?? '—'}</TableCell>
                                <TableCell>{row.occurrences.toLocaleString()}</TableCell>
                                <TableCell>{formatDateLabel(row.last_scanned_at)}</TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      )}
                    </CardContent>
                  </Card>
                </Grid>

                <Grid item xs={12} md={6}>
                  <Card>
                    <CardContent>
                      <Typography variant="h6" component="h2" gutterBottom>
                        Errores de acceso
                      </Typography>
                      {errors.length === 0 ? (
                        <Typography variant="body2" color="text.secondary">
                          No se detectaron errores de acceso en el periodo analizado.
                        </Typography>
                      ) : (
                        <Table size="small" aria-label="Tabla de errores de acceso">
                          <TableHead>
                            <TableRow>
                              <TableCell>Ticket</TableCell>
                              <TableCell>Motivo</TableCell>
                              <TableCell>Ocurrencias</TableCell>
                              <TableCell>Último registro</TableCell>
                            </TableRow>
                          </TableHead>
                          <TableBody>
                            {errors.map((row) => (
                              <TableRow key={`${row.ticket_id ?? 'ticket'}-${row.result}`}>
                                <TableCell>{row.ticket_id ?? 'Sin ID'}</TableCell>
                                <TableCell>{row.result}</TableCell>
                                <TableCell>{row.occurrences.toLocaleString()}</TableCell>
                                <TableCell>{formatDateLabel(row.last_scanned_at)}</TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      )}
                    </CardContent>
                  </Card>
                </Grid>
              </Grid>
            )}
          </>
        )}
      </Stack>
    </Container>
  );
};

function formatCapacity(capacity: number | null | undefined): string {
  if (capacity === null || capacity === undefined) {
    return 'Sin definir';
  }
  return capacity.toLocaleString();
}

export default Dashboard;
