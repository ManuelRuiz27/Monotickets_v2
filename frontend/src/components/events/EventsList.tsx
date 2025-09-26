import { useEffect, useMemo, useState, type ChangeEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Container,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  FormControl,
  IconButton,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TableSortLabel,
  TextField,
  Toolbar,
  Tooltip,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import ArchiveIcon from '@mui/icons-material/Archive';
import DeleteIcon from '@mui/icons-material/Delete';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { DateTime } from 'luxon';
import { useNavigate } from 'react-router-dom';
import {
  EVENT_STATUS_LABELS,
  type EventResource,
  type EventStatus,
  useArchiveEvent,
  useDeleteEvent,
  useEventsList,
} from '../../hooks/useEventsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';
import EventStatusChip from './EventStatusChip';

type OrderDirection = 'asc' | 'desc';

interface OrderState {
  orderBy: 'start_at' | 'name' | 'status';
  direction: OrderDirection;
}

const STATUS_OPTIONS: { value: EventStatus; label: string }[] = (
  Object.entries(EVENT_STATUS_LABELS) as [EventStatus, string][]
).map(([value, label]) => ({ value, label }));

const formatDate = (iso: string | null | undefined, timezone?: string) => {
  if (!iso) return '—';
  try {
    return DateTime.fromISO(iso, { zone: timezone ?? undefined }).toFormat("dd/MM/yyyy HH:mm 'hrs' (z)");
  } catch {
    return '—';
  }
};

const formatCapacity = (capacity: number | null | undefined) => {
  if (capacity === null || capacity === undefined) {
    return '—';
  }
  return capacity.toLocaleString();
};

const formatOccupancy = (capacity: number | null | undefined) => {
  if (capacity === null || capacity === undefined) {
    return '—';
  }
  return '0%';
};

const orderEvents = (events: EventResource[], order: OrderState) => {
  const sorted = [...events];
  sorted.sort((a, b) => {
    const direction = order.direction === 'asc' ? 1 : -1;
    if (order.orderBy === 'start_at') {
      const left = a.start_at ? DateTime.fromISO(a.start_at).toMillis() : 0;
      const right = b.start_at ? DateTime.fromISO(b.start_at).toMillis() : 0;
      return (left - right) * direction;
    }

    if (order.orderBy === 'name') {
      return a.name.localeCompare(b.name) * direction;
    }

    return a.status.localeCompare(b.status) * direction;
  });
  return sorted;
};

const EventsList = () => {
  const navigate = useNavigate();
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [selectedStatuses, setSelectedStatuses] = useState<EventStatus[]>([]);
  const [fromDate, setFromDate] = useState<string | null>(null);
  const [toDate, setToDate] = useState<string | null>(null);
  const [order, setOrder] = useState<OrderState>({ orderBy: 'start_at', direction: 'asc' });
  const [archiveTarget, setArchiveTarget] = useState<EventResource | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<EventResource | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    const handle = window.setTimeout(() => setDebouncedSearch(search), 400);
    return () => window.clearTimeout(handle);
  }, [search]);

  const filters = useMemo(
    () => ({
      page,
      perPage: rowsPerPage,
      search: debouncedSearch,
      status: selectedStatuses,
      from: fromDate ?? undefined,
      to: toDate ?? undefined,
    }),
    [page, rowsPerPage, debouncedSearch, selectedStatuses, fromDate, toDate]
  );

  const { data, isLoading, isError, error } = useEventsList(filters);
  const archiveMutation = useArchiveEvent({
    onSuccess: () => setArchiveTarget(null),
    onError: (err: unknown) => setActionError(extractApiErrorMessage(err, 'No se pudo archivar el evento.')),
  });
  const deleteMutation = useDeleteEvent({
    onSuccess: () => setDeleteTarget(null),
    onError: (err: unknown) => setActionError(extractApiErrorMessage(err, 'No se pudo eliminar el evento.')),
  });

  const events = useMemo(() => {
    if (!data?.data) return [] as EventResource[];
    return orderEvents(data.data, order);
  }, [data?.data, order]);

  const totalCount = data?.meta.total ?? 0;

  const handleChangePage = (_event: unknown, newPage: number) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  const handleStatusFilterChange = (event: SelectChangeEvent<EventStatus[]>) => {
    const value = event.target.value;
    const nextValue = (Array.isArray(value) ? value : value.split(',')) as EventStatus[];
    setSelectedStatuses(nextValue);
    setPage(0);
  };

  const handleSort = (property: OrderState['orderBy']) => {
    setOrder((prev) => {
      if (prev.orderBy === property) {
        return { orderBy: property, direction: prev.direction === 'asc' ? 'desc' : 'asc' };
      }
      return { orderBy: property, direction: 'asc' };
    });
  };

  const handleArchiveConfirm = async () => {
    if (!archiveTarget) return;
    setActionError(null);
    try {
      await archiveMutation.mutateAsync({ eventId: archiveTarget.id });
    } catch {
      // el error se maneja en onError
    }
  };

  const handleDeleteConfirm = async () => {
    if (!deleteTarget) return;
    setActionError(null);
    try {
      await deleteMutation.mutateAsync({ eventId: deleteTarget.id });
    } catch {
      // el error se maneja en onError
    }
  };

  const generalError = isError ? extractApiErrorMessage(error, 'No se pudieron cargar los eventos.') : null;

  return (
    <Container maxWidth="lg" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Stack
          direction={{ xs: 'column', sm: 'row' }}
          justifyContent="space-between"
          alignItems={{ xs: 'flex-start', sm: 'center' }}
          spacing={2}
        >
          <Box>
            <Typography variant="h4" component="h1">
              Eventos
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Administra los eventos con filtros por estado, fechas y búsqueda avanzada.
            </Typography>
          </Box>
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => navigate('/events/new')}>
            Nuevo evento
          </Button>
        </Stack>
        <Paper elevation={0} variant="outlined">
          <Toolbar sx={{ display: 'flex', flexDirection: { xs: 'column', md: 'row' }, gap: 2 }}>
            <TextField
              label="Buscar"
              placeholder="Nombre, código o descripción"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value);
                setPage(0);
              }}
              fullWidth
            />
            <FormControl sx={{ minWidth: 180 }}>
              <InputLabel id="status-filter-label">Estado</InputLabel>
              <Select
                labelId="status-filter-label"
                label="Estado"
                value={selectedStatuses}
                onChange={handleStatusFilterChange}
                multiple
                renderValue={(selected) =>
                  selected.length === 0
                    ? 'Todos'
                    : (selected as EventStatus[])
                        .map((value) => EVENT_STATUS_LABELS[value] ?? value)
                        .join(', ')
                }
              >
                {STATUS_OPTIONS.map((option) => (
                  <MenuItem key={option.value} value={option.value}>
                    {option.label}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <TextField
              label="Desde"
              type="date"
              InputLabelProps={{ shrink: true }}
              value={fromDate ?? ''}
              onChange={(event) => {
                setFromDate(event.target.value ? event.target.value : null);
                setPage(0);
              }}
            />
            <TextField
              label="Hasta"
              type="date"
              InputLabelProps={{ shrink: true }}
              value={toDate ?? ''}
              onChange={(event) => {
                setToDate(event.target.value ? event.target.value : null);
                setPage(0);
              }}
            />
          </Toolbar>
          {(generalError || actionError) && (
            <Box px={3} pb={2}>
              <Alert
                severity="error"
                onClose={actionError ? () => setActionError(null) : undefined}
              >
                {actionError ?? generalError}
              </Alert>
            </Box>
          )}
          {isLoading ? (
            <Box py={6} display="flex" justifyContent="center" alignItems="center">
              <CircularProgress />
            </Box>
          ) : events.length === 0 ? (
            <Box py={6} display="flex" justifyContent="center" alignItems="center">
              <Typography variant="body2" color="text.secondary">
                No se encontraron eventos con los criterios seleccionados.
              </Typography>
            </Box>
          ) : (
            <>
              <TableContainer>
                <Table aria-label="Listado de eventos">
                  <TableHead>
                    <TableRow>
                      <TableCell>Código</TableCell>
                      <TableCell>
                        <TableSortLabel
                          active={order.orderBy === 'name'}
                          direction={order.orderBy === 'name' ? order.direction : 'asc'}
                          onClick={() => handleSort('name')}
                        >
                          Evento
                        </TableSortLabel>
                      </TableCell>
                      <TableCell>
                        <TableSortLabel
                          active={order.orderBy === 'start_at'}
                          direction={order.orderBy === 'start_at' ? order.direction : 'asc'}
                          onClick={() => handleSort('start_at')}
                        >
                          Inicio
                        </TableSortLabel>
                      </TableCell>
                      <TableCell>Fin</TableCell>
                      <TableCell>
                        <TableSortLabel
                          active={order.orderBy === 'status'}
                          direction={order.orderBy === 'status' ? order.direction : 'asc'}
                          onClick={() => handleSort('status')}
                        >
                          Estado
                        </TableSortLabel>
                      </TableCell>
                      <TableCell>Capacidad</TableCell>
                      <TableCell>Ocupación</TableCell>
                      <TableCell align="right">Acciones</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {events.map((event) => (
                      <TableRow key={event.id} hover>
                        <TableCell>
                          <Typography variant="subtitle2">{event.code}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            ID: {event.id}
                          </Typography>
                        </TableCell>
                        <TableCell>
                          <Typography variant="subtitle2">{event.name}</Typography>
                          <Typography variant="body2" color="text.secondary">
                            Zona: {event.timezone}
                          </Typography>
                        </TableCell>
                        <TableCell>{formatDate(event.start_at, event.timezone)}</TableCell>
                        <TableCell>{formatDate(event.end_at, event.timezone)}</TableCell>
                        <TableCell>
                          <EventStatusChip status={event.status} />
                        </TableCell>
                        <TableCell>{formatCapacity(event.capacity)}</TableCell>
                        <TableCell>{formatOccupancy(event.capacity)}</TableCell>
                        <TableCell align="right">
                          <Tooltip title="Ver detalle">
                            <span>
                              <IconButton aria-label="Ver detalle" size="small" onClick={() => navigate(`/events/${event.id}`)}>
                                <VisibilityIcon fontSize="small" />
                              </IconButton>
                            </span>
                          </Tooltip>
                          <Tooltip title="Editar">
                            <span>
                              <IconButton
                                aria-label="Editar"
                                size="small"
                                onClick={() => navigate(`/events/${event.id}/edit`)}
                              >
                                <EditIcon fontSize="small" />
                              </IconButton>
                            </span>
                          </Tooltip>
                          <Tooltip title="Archivar">
                            <span>
                              <IconButton
                                aria-label="Archivar"
                                size="small"
                                onClick={() => setArchiveTarget(event)}
                                disabled={event.status === 'archived'}
                              >
                                <ArchiveIcon fontSize="small" />
                              </IconButton>
                            </span>
                          </Tooltip>
                          <Tooltip title="Eliminar">
                            <span>
                              <IconButton
                                aria-label="Eliminar"
                                size="small"
                                color="error"
                                onClick={() => setDeleteTarget(event)}
                              >
                                <DeleteIcon fontSize="small" />
                              </IconButton>
                            </span>
                          </Tooltip>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
              <TablePagination
                component="div"
                count={totalCount}
                page={page}
                onPageChange={handleChangePage}
                rowsPerPage={rowsPerPage}
                onRowsPerPageChange={handleChangeRowsPerPage}
                rowsPerPageOptions={[5, 10, 25]}
              />
            </>
          )}
        </Paper>
      </Stack>
      <Dialog open={Boolean(archiveTarget)} onClose={() => setArchiveTarget(null)}>
        <DialogTitle>Archivar evento</DialogTitle>
        <DialogContent>
          <DialogContentText>
            ¿Deseas archivar "{archiveTarget?.name}"? El evento dejará de mostrarse para nuevas operaciones y
            permanecerá sólo como referencia histórica.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setArchiveTarget(null)}>Cancelar</Button>
          <Button
            onClick={handleArchiveConfirm}
            variant="contained"
            color="warning"
            disabled={archiveMutation.isPending}
            autoFocus
          >
            {archiveMutation.isPending ? 'Archivando…' : 'Archivar'}
          </Button>
        </DialogActions>
      </Dialog>
      <Dialog open={Boolean(deleteTarget)} onClose={() => setDeleteTarget(null)}>
        <DialogTitle>Eliminar evento</DialogTitle>
        <DialogContent>
          <DialogContentText>
            Esta acción deshabilitará el evento "{deleteTarget?.name}" para futuros registros. ¿Deseas continuar?
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteTarget(null)}>Cancelar</Button>
          <Button
            onClick={handleDeleteConfirm}
            variant="contained"
            color="error"
            disabled={deleteMutation.isPending}
            autoFocus
          >
            {deleteMutation.isPending ? 'Eliminando…' : 'Eliminar'}
          </Button>
        </DialogActions>
      </Dialog>
    </Container>
  );
};

export default EventsList;
