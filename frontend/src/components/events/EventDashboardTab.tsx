import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  CardHeader,
  CircularProgress,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Skeleton,
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
import DownloadIcon from '@mui/icons-material/Download';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import { DateTime } from 'luxon';
import {
  memo,
  useCallback,
  useEffect,
  useMemo,
  useState,
  type FormEvent,
} from 'react';
import { useSearchParams } from 'react-router-dom';
import { saveAs } from 'file-saver';
import { extractApiErrorMessage } from '../../utils/apiErrors';
import { useToast } from '../common/ToastProvider';
import { resolveApiUrl } from '../../api/client';
import { useAuthStore } from '../../auth/store';
import { useEventCheckpoints } from '../../hooks/useCheckpointsApi';
import {
  type AttendanceByHourEntry,
  type CheckpointTotalsEntry,
  type DashboardOverviewResponse,
  type GuestsByListEntry,
  type RsvpFunnelResponse,
  useEventDashboardAttendanceByHour,
  useEventDashboardCheckpointTotals,
  useEventDashboardGuestsByList,
  useEventDashboardOverview,
  useEventDashboardRsvpFunnel,
} from '../../hooks/useEventDashboard';

interface EventDashboardTabProps {
  eventId: string;
}

interface DashboardFiltersState {
  from: string | null;
  to: string | null;
  checkpointId: string | null;
}

const KPIGrid = ({
  overview,
  validAttendances,
  duplicateAttendances,
}: {
  overview: DashboardOverviewResponse['data'] | undefined;
  validAttendances: number;
  duplicateAttendances: number;
}) => {
  const occupancyRate = overview?.occupancy_rate ?? null;
  const occupancyPercent = occupancyRate !== null ? `${Math.round(occupancyRate * 100)}%` : '—';

  const cards = [
    {
      title: 'Invitados',
      value: overview ? overview.invited.toLocaleString() : '—',
    },
    {
      title: 'Confirmados',
      value: overview ? overview.confirmed.toLocaleString() : '—',
    },
    {
      title: 'Asistencias válidas',
      value: overview ? validAttendances.toLocaleString() : '—',
    },
    {
      title: 'Duplicados',
      value: overview ? duplicateAttendances.toLocaleString() : '—',
    },
    {
      title: '% Ocupación',
      value: overview ? occupancyPercent : '—',
    },
  ];

  return (
    <Grid container spacing={2}>
      {cards.map((card) => (
        <Grid item xs={12} sm={6} md={4} lg={2} key={card.title}>
          <Card variant="outlined">
            <CardContent>
              <Typography variant="overline" color="text.secondary">
                {card.title}
              </Typography>
              <Typography variant="h5" component="div">
                {card.value}
              </Typography>
            </CardContent>
          </Card>
        </Grid>
      ))}
    </Grid>
  );
};

const LineChart = memo(({ data }: { data: AttendanceByHourEntry[] }) => {
  const chartData = useMemo(() => data.filter((item) => item.hour !== null), [data]);

  if (chartData.length === 0) {
    return (
      <Box py={4} display="flex" justifyContent="center">
        <Typography variant="body2" color="text.secondary">
          No hay datos suficientes para mostrar la gráfica.
        </Typography>
      </Box>
    );
  }

  const maxValue = Math.max(1, ...chartData.map((item) => item.valid));
  const points = chartData.map((item, index) => {
    const x = (index / (chartData.length - 1 || 1)) * 100;
    const y = 100 - (item.valid / maxValue) * 100;
    return `${x},${y}`;
  });

  const labels = chartData.map((item) => {
    const date = item.hour ? DateTime.fromISO(item.hour).toFormat('dd MMM HH:mm') : '—';
    return { label: date, value: item.valid };
  });

  return (
    <Stack spacing={2}>
      <Box position="relative" width="100%" sx={{ aspectRatio: '16 / 9' }}>
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" style={{ width: '100%', height: '100%' }}>
          <polyline fill="none" stroke="var(--mui-palette-primary-main)" strokeWidth={2} points={points.join(' ')} />
        </svg>
      </Box>
      <Stack direction="row" flexWrap="wrap" spacing={2} rowGap={1}>
        {labels.map((item) => (
          <Box key={item.label}>
            <Typography variant="caption" color="text.secondary">
              {item.label}
            </Typography>
            <Typography variant="body2">{item.value.toLocaleString()}</Typography>
          </Box>
        ))}
      </Stack>
    </Stack>
  );
});

LineChart.displayName = 'LineChart';

const CheckpointStackedBars = memo(({ data }: { data: CheckpointTotalsEntry[] }) => {
  if (data.length === 0) {
    return (
      <Box py={4} display="flex" justifyContent="center">
        <Typography variant="body2" color="text.secondary">
          No hay asistencias registradas para los checkpoints.
        </Typography>
      </Box>
    );
  }

  const maxTotal = Math.max(1, ...data.map((item) => item.valid + item.duplicate));

  return (
    <Stack spacing={2}>
      {data.map((item) => {
        const total = item.valid + item.duplicate;
        const validWidth = (item.valid / maxTotal) * 100;
        const duplicateWidth = (item.duplicate / maxTotal) * 100;

        return (
          <Stack key={item.checkpoint_id ?? item.name ?? 'unknown'} spacing={1}>
            <Typography variant="subtitle2">{item.name ?? 'Sin checkpoint'}</Typography>
            <Box height={16} display="flex" width="100%" sx={{ borderRadius: 1, overflow: 'hidden', bgcolor: 'action.hover' }}>
              <Box sx={{ width: `${validWidth}%`, bgcolor: 'success.main' }} title={`Válidos: ${item.valid}`} />
              <Box sx={{ width: `${duplicateWidth}%`, bgcolor: 'warning.main' }} title={`Duplicados: ${item.duplicate}`} />
            </Box>
            <Typography variant="caption" color="text.secondary">
              {`Válidos: ${item.valid.toLocaleString()} • Duplicados: ${item.duplicate.toLocaleString()} • Total: ${total.toLocaleString()}`}
            </Typography>
          </Stack>
        );
      })}
    </Stack>
  );
});

CheckpointStackedBars.displayName = 'CheckpointStackedBars';

const RsvpDonut = memo(({ data }: { data: RsvpFunnelResponse['data'] | undefined }) => {
  const total = (data?.invited ?? 0) + (data?.confirmed ?? 0) + (data?.declined ?? 0);

  if (!data || total === 0) {
    return (
      <Box py={4} display="flex" justifyContent="center">
        <Typography variant="body2" color="text.secondary">
          No hay datos de RSVP registrados.
        </Typography>
      </Box>
    );
  }

  const radius = 45;
  const circumference = 2 * Math.PI * radius;
  const segments: Array<{ value: number; color: string; label: string }> = [
    { value: data.invited, color: 'var(--mui-palette-info-main)', label: 'Invitados' },
    { value: data.confirmed, color: 'var(--mui-palette-success-main)', label: 'Confirmados' },
    { value: data.declined, color: 'var(--mui-palette-error-main)', label: 'Rechazados' },
  ];

  let offset = 0;

  const circles = segments.map((segment) => {
    const fraction = segment.value / total;
    const length = circumference * fraction;
    const circle = (
      <circle
        key={segment.label}
        cx="50"
        cy="50"
        r={radius}
        fill="transparent"
        stroke={segment.color}
        strokeWidth={10}
        strokeDasharray={`${length} ${circumference - length}`}
        strokeDashoffset={offset}
      />
    );
    offset -= length;
    return circle;
  });

  return (
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={3} alignItems="center" justifyContent="center">
      <Box width={200} height={200}>
        <svg viewBox="0 0 100 100" width="100%" height="100%">
          <circle
            cx="50"
            cy="50"
            r={radius}
            fill="transparent"
            stroke="var(--mui-palette-action-disabledBackground)"
            strokeWidth={10}
          />
          {circles}
          <text x="50" y="52" textAnchor="middle" fontSize="12" fill="var(--mui-palette-text-primary)">
            {`${Math.round(((data.confirmed ?? 0) / total) * 100)}%`}
          </text>
        </svg>
      </Box>
      <Stack spacing={1}>
        {segments.map((segment) => (
          <Stack direction="row" spacing={1} alignItems="center" key={segment.label}>
            <Box sx={{ width: 12, height: 12, borderRadius: 1, bgcolor: segment.color }} />
            <Typography variant="body2">
              {segment.label}: {segment.value.toLocaleString()}
            </Typography>
          </Stack>
        ))}
      </Stack>
    </Stack>
  );
});

RsvpDonut.displayName = 'RsvpDonut';

const GuestsByListTable = ({ data, isLoading }: { data: GuestsByListEntry[]; isLoading: boolean }) => {
  if (isLoading) {
    return (
      <TableContainer>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Lista</TableCell>
              <TableCell align="right">Invitados</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {Array.from({ length: 5 }).map((_, index) => (
              <TableRow key={index}>
                <TableCell>
                  <Skeleton width="60%" />
                </TableCell>
                <TableCell align="right">
                  <Skeleton width="40%" />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    );
  }

  if (data.length === 0) {
    return (
      <Box py={4} display="flex" justifyContent="center">
        <Typography variant="body2" color="text.secondary">
          No hay listas de invitados con información para mostrar.
        </Typography>
      </Box>
    );
  }

  return (
    <TableContainer>
      <Table>
        <TableHead>
          <TableRow>
            <TableCell>Lista</TableCell>
            <TableCell align="right">Invitados</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {data.map((row, index) => (
            <TableRow key={row.list ?? `desconocida-${index}`}>
              <TableCell>{row.list ?? 'Sin lista'}</TableCell>
              <TableCell align="right">{row.count.toLocaleString()}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  );
};

const buildExportUrl = (eventId: string, filters: DashboardFiltersState, type: 'csv' | 'pdf') => {
  const params = new URLSearchParams();

  if (filters.from) {
    params.set('from', filters.from);
  }

  if (filters.to) {
    params.set('to', filters.to);
  }

  if (filters.checkpointId && type === 'csv') {
    params.set('checkpoint_id', filters.checkpointId);
  }

  const suffix = params.toString() ? `?${params.toString()}` : '';
  return type === 'csv'
    ? `/events/${eventId}/reports/attendance.csv${suffix}`
    : `/events/${eventId}/reports/summary.pdf${suffix}`;
};

const EventDashboardTab = ({ eventId }: EventDashboardTabProps) => {
  const { showToast } = useToast();
  const { token, tenantId } = useAuthStore((state) => ({ token: state.token, tenantId: state.tenantId }));
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState<DashboardFiltersState>(() => ({
    from: searchParams.get('from') ?? null,
    to: searchParams.get('to') ?? null,
    checkpointId: searchParams.get('checkpoint_id') ?? null,
  }));

  useEffect(() => {
    setFilters({
      from: searchParams.get('from') ?? null,
      to: searchParams.get('to') ?? null,
      checkpointId: searchParams.get('checkpoint_id') ?? null,
    });
  }, [searchParams]);

  const dashboardFilters = useMemo(
    () => ({
      from: filters.from,
      to: filters.to,
    }),
    [filters.from, filters.to],
  );

  const { data: overviewData, isLoading: isOverviewLoading, isError: isOverviewError, error: overviewError } =
    useEventDashboardOverview(eventId, dashboardFilters);
  const {
    data: attendanceData,
    isLoading: isAttendanceLoading,
    isError: isAttendanceError,
    error: attendanceError,
  } = useEventDashboardAttendanceByHour(eventId, dashboardFilters);
  const {
    data: checkpointTotalsData,
    isLoading: isCheckpointTotalsLoading,
    isError: isCheckpointTotalsError,
    error: checkpointTotalsError,
  } = useEventDashboardCheckpointTotals(eventId, dashboardFilters);
  const { data: rsvpData, isLoading: isRsvpLoading, isError: isRsvpError, error: rsvpError } =
    useEventDashboardRsvpFunnel(eventId);
  const {
    data: guestsByListData,
    isLoading: isGuestsByListLoading,
    isError: isGuestsByListError,
    error: guestsByListError,
  } = useEventDashboardGuestsByList(eventId);

  const { data: checkpoints, isLoading: isCheckpointsLoading } = useEventCheckpoints(eventId);

  useEffect(() => {
    const errors = [
      isOverviewError ? overviewError : null,
      isAttendanceError ? attendanceError : null,
      isCheckpointTotalsError ? checkpointTotalsError : null,
      isRsvpError ? rsvpError : null,
      isGuestsByListError ? guestsByListError : null,
    ].filter(Boolean);

    if (errors.length > 0) {
      showToast({
        message: extractApiErrorMessage(errors[0], 'Ocurrió un error al cargar el dashboard.'),
        severity: 'error',
      });
    }
  }, [
    isOverviewError,
    overviewError,
    isAttendanceError,
    attendanceError,
    isCheckpointTotalsError,
    checkpointTotalsError,
    isRsvpError,
    rsvpError,
    isGuestsByListError,
    guestsByListError,
    showToast,
  ]);

  const handleFilterChange = useCallback(
    (next: Partial<DashboardFiltersState>) => {
      setFilters((prev) => {
        const merged = { ...prev, ...next };
        const params = new URLSearchParams(searchParams);

        if (merged.from) {
          params.set('from', merged.from);
        } else {
          params.delete('from');
        }

        if (merged.to) {
          params.set('to', merged.to);
        } else {
          params.delete('to');
        }

        if (merged.checkpointId) {
          params.set('checkpoint_id', merged.checkpointId);
        } else {
          params.delete('checkpoint_id');
        }

        setSearchParams(params, { replace: true });
        return merged;
      });
    },
    [searchParams, setSearchParams],
  );

  const filteredCheckpointTotals = useMemo(() => {
    const entries = checkpointTotalsData?.data ?? [];

    if (!filters.checkpointId) {
      return entries;
    }

    return entries.filter((item) => item.checkpoint_id === filters.checkpointId);
  }, [checkpointTotalsData?.data, filters.checkpointId]);

  const aggregatedTotals = useMemo(() => {
    const entries = checkpointTotalsData?.data ?? [];
    const relevant = filters.checkpointId
      ? entries.filter((item) => item.checkpoint_id === filters.checkpointId)
      : entries;

    return relevant.reduce(
      (acc, item) => {
        acc.valid += item.valid;
        acc.duplicate += item.duplicate;
        return acc;
      },
      { valid: 0, duplicate: 0 },
    );
  }, [checkpointTotalsData?.data, filters.checkpointId]);

  const handleExport = useCallback(
    async (type: 'csv' | 'pdf') => {
      const url = buildExportUrl(eventId, filters, type);

      try {
        const headers = new Headers({
          Accept: type === 'csv' ? 'text/csv' : 'application/pdf',
        });

        if (token) {
          headers.set('Authorization', `Bearer ${token}`);
        }

        if (tenantId) {
          headers.set('X-Tenant-ID', tenantId);
        }

        const response = await fetch(resolveApiUrl(url), {
          headers,
        });

        if (!response.ok) {
          throw new Error('Error al descargar el archivo');
        }

        const blob = await response.blob();
        const extension = type === 'csv' ? 'csv' : 'pdf';
        const filename = type === 'csv' ? `asistencias-${eventId}.${extension}` : `resumen-${eventId}.${extension}`;
        saveAs(blob, filename);
      } catch (error) {
        showToast({
          message: extractApiErrorMessage(error, 'No fue posible descargar el archivo solicitado.'),
          severity: 'error',
        });
      }
    },
    [eventId, filters, showToast],
  );

  const handleFiltersSubmit = useCallback(
    (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
    },
    [],
  );

  return (
    <Stack spacing={3}>
      <Paper variant="outlined" component="form" onSubmit={handleFiltersSubmit} sx={{ p: 2 }}>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ xs: 'stretch', md: 'flex-end' }}>
          <TextField
            label="Desde"
            type="date"
            InputLabelProps={{ shrink: true }}
            value={filters.from ?? ''}
            onChange={(event) => handleFilterChange({ from: event.target.value || null })}
          />
          <TextField
            label="Hasta"
            type="date"
            InputLabelProps={{ shrink: true }}
            value={filters.to ?? ''}
            onChange={(event) => handleFilterChange({ to: event.target.value || null })}
          />
          <FormControl sx={{ minWidth: 200 }} disabled={isCheckpointsLoading}>
            <InputLabel id="checkpoint-filter-label">Checkpoint</InputLabel>
            <Select
              labelId="checkpoint-filter-label"
              label="Checkpoint"
              value={filters.checkpointId ?? ''}
              onChange={(event) => handleFilterChange({ checkpointId: event.target.value ? (event.target.value as string) : null })}
              displayEmpty
            >
              <MenuItem value="">
                <em>Todos</em>
              </MenuItem>
              {(checkpoints ?? []).map((checkpoint) => (
                <MenuItem value={checkpoint.id} key={checkpoint.id}>
                  {checkpoint.name}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
            <Button
              variant="outlined"
              startIcon={<DownloadIcon />}
              onClick={() => handleExport('csv')}
              type="button"
            >
              Exportar asistencias (CSV)
            </Button>
            <Button variant="outlined" startIcon={<PictureAsPdfIcon />} onClick={() => handleExport('pdf')} type="button">
              Exportar resumen (PDF)
            </Button>
          </Stack>
        </Stack>
      </Paper>

      <Stack spacing={3}>
        {isOverviewLoading || isCheckpointTotalsLoading ? (
          <Grid container spacing={2}>
            {Array.from({ length: 5 }).map((_, index) => (
              <Grid item xs={12} sm={6} md={4} lg={2} key={index}>
                <Card variant="outlined">
                  <CardContent>
                    <Skeleton width="50%" />
                    <Skeleton width="60%" height={36} />
                  </CardContent>
                </Card>
              </Grid>
            ))}
          </Grid>
        ) : overviewData ? (
          <KPIGrid
            overview={overviewData.data}
            validAttendances={filters.checkpointId ? aggregatedTotals.valid : overviewData.data.attendances}
            duplicateAttendances={filters.checkpointId ? aggregatedTotals.duplicate : overviewData.data.duplicates}
          />
        ) : (
          <Alert severity="info">No se pudieron cargar los indicadores principales.</Alert>
        )}

        <Grid container spacing={3}>
          <Grid item xs={12} md={6}>
            <Paper variant="outlined" sx={{ p: 2, height: '100%' }}>
              <CardHeader title="Asistencias por hora" subheader="Registros válidos por hora del evento" sx={{ p: 0, mb: 2 }} />
              {isAttendanceLoading ? (
                <Skeleton variant="rectangular" height={220} sx={{ borderRadius: 1 }} />
              ) : (
                <LineChart data={attendanceData?.data ?? []} />
              )}
            </Paper>
          </Grid>
          <Grid item xs={12} md={6}>
            <Paper variant="outlined" sx={{ p: 2, height: '100%' }}>
              <CardHeader title="Asistencias por checkpoint" subheader="Totales válidos y duplicados" sx={{ p: 0, mb: 2 }} />
              {isCheckpointTotalsLoading ? (
                <Stack spacing={1}>
                  {Array.from({ length: 3 }).map((_, index) => (
                    <Skeleton key={index} variant="rectangular" height={32} sx={{ borderRadius: 1 }} />
                  ))}
                </Stack>
              ) : (
                <CheckpointStackedBars data={filteredCheckpointTotals} />
              )}
            </Paper>
          </Grid>
        </Grid>

        <Grid container spacing={3}>
          <Grid item xs={12} md={6}>
            <Paper variant="outlined" sx={{ p: 2, height: '100%' }}>
              <CardHeader title="Embudo RSVP" subheader="Invitados vs confirmaciones" sx={{ p: 0, mb: 2 }} />
              {isRsvpLoading ? (
                <Box py={6} display="flex" justifyContent="center">
                  <CircularProgress />
                </Box>
              ) : (
                <RsvpDonut data={rsvpData?.data} />
              )}
            </Paper>
          </Grid>
          <Grid item xs={12} md={6}>
            <Paper variant="outlined" sx={{ p: 2, height: '100%' }}>
              <CardHeader title="Por lista de invitados" subheader="Totales por lista" sx={{ p: 0, mb: 2 }} />
              <GuestsByListTable data={guestsByListData?.data ?? []} isLoading={isGuestsByListLoading} />
            </Paper>
          </Grid>
        </Grid>
      </Stack>
    </Stack>
  );
};

export default EventDashboardTab;

