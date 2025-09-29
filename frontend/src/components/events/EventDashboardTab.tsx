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
import { useTheme } from '@mui/material/styles';
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { saveAs } from 'file-saver';
import { extractApiErrorMessage } from '../../utils/apiErrors';
import { useToast } from '../common/ToastProvider';
import { resolveApiUrl } from '../../api/client';
import { useAuthStore } from '../../auth/store';
import { useEventCheckpoints } from '../../hooks/useCheckpointsApi';
import {
  type GuestsByListEntry,
  useEventDashboardAttendanceByHour,
  useEventDashboardCheckpointTotals,
  useEventDashboardGuestsByList,
  useEventDashboardOverview,
  useEventDashboardRsvpFunnel,
} from '../../hooks/useEventDashboard';
import KpiCard from '../charts/KpiCard';
import TimeSeries from '../charts/TimeSeries';
import BarByCheckpoint from '../charts/BarByCheckpoint';
import Donut from '../charts/Donut';

interface EventDashboardTabProps {
  eventId: string;
  eventTimezone?: string | null;
}

interface DashboardFiltersState {
  from: string | null;
  to: string | null;
  checkpointId: string | null;
}

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

const formatNumber = (value: number | null | undefined): string => {
  if (value === null || value === undefined) {
    return '—';
  }
  return value.toLocaleString('es-MX');
};

const formatPercent = (value: number | null | undefined): string => {
  if (value === null || value === undefined) {
    return '—';
  }

  return new Intl.NumberFormat('es-MX', {
    style: 'percent',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value);
};

const EventDashboardTab = ({ eventId, eventTimezone }: EventDashboardTabProps) => {
  const theme = useTheme();
  const infoColor = theme.palette.info.main;
  const successColor = theme.palette.success.main;
  const errorColor = theme.palette.error.main;
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

  const overviewMetrics = overviewData?.data ?? null;
  const validAttendancesValue = overviewMetrics
    ? filters.checkpointId
      ? aggregatedTotals.valid
      : overviewMetrics.attendances
    : null;
  const duplicateAttendancesValue = overviewMetrics
    ? filters.checkpointId
      ? aggregatedTotals.duplicate
      : overviewMetrics.duplicates
    : null;

  const kpiItems = useMemo(
    () => {
      if (!overviewMetrics) {
        return [] as Array<{ label: string; value: string; subvalue?: string }>;
      }

      const uniqueLabel = overviewMetrics.unique_attendees;
      const uniqueSubvalue =
        uniqueLabel === null || uniqueLabel === undefined
          ? undefined
          : `Únicos: ${formatNumber(uniqueLabel)}`;

      return [
        { label: 'Invitados', value: formatNumber(overviewMetrics.invited) },
        { label: 'Confirmados', value: formatNumber(overviewMetrics.confirmed) },
        {
          label: 'Asistencias válidas',
          value: formatNumber(validAttendancesValue),
          subvalue: uniqueSubvalue,
        },
        {
          label: 'Duplicados',
          value: formatNumber(duplicateAttendancesValue),
        },
        { label: '% Ocupación', value: formatPercent(overviewMetrics.occupancy_rate) },
      ];
    },
    [duplicateAttendancesValue, overviewMetrics, validAttendancesValue],
  );

  const checkpointChartData = useMemo(
    () =>
      filteredCheckpointTotals.map((item) => ({
        checkpoint: item.name ?? 'Sin checkpoint',
        valid: item.valid,
        duplicate: item.duplicate,
      })),
    [filteredCheckpointTotals],
  );

  const donutData = useMemo(() => {
    if (!rsvpData?.data) {
      return [];
    }

    const { invited, confirmed, declined } = rsvpData.data;

    return [
      { label: 'Invitados', value: invited, color: infoColor },
      { label: 'Confirmados', value: confirmed, color: successColor },
      { label: 'Rechazados', value: declined, color: errorColor },
    ];
  }, [errorColor, infoColor, rsvpData?.data, successColor]);

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
        ) : kpiItems.length > 0 ? (
          <Grid container spacing={2}>
            {kpiItems.map((item) => (
              <Grid item xs={12} sm={6} md={4} lg={2} key={item.label}>
                <KpiCard label={item.label} value={item.value} subvalue={item.subvalue} />
              </Grid>
            ))}
          </Grid>
        ) : (
          <Alert severity="info">No se pudieron cargar los indicadores principales.</Alert>
        )}

        <Grid container spacing={3}>
          <Grid item xs={12} md={6}>
            <Paper variant="outlined" sx={{ p: 2, height: '100%' }}>
              <CardHeader
                title="Asistencias por hora"
                subheader="Registros válidos, duplicados y únicos por hora"
                sx={{ p: 0, mb: 2 }}
              />
              {isAttendanceLoading ? (
                <Skeleton variant="rectangular" height={220} sx={{ borderRadius: 1 }} />
              ) : (
                <TimeSeries
                  data={attendanceData?.data ?? []}
                  timezone={eventTimezone}
                  ariaLabel="Serie temporal de asistencias"
                />
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
                <BarByCheckpoint
                  data={checkpointChartData}
                  ariaLabel="Asistencias por punto de control"
                />
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
                <Donut data={donutData} ariaLabel="Distribución del embudo RSVP" />
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

