import { useEffect, useMemo, useState, type ChangeEvent, type FormEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  Checkbox,
  CircularProgress,
  FormControl,
  IconButton,
  InputLabel,
  ListItemText,
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
  TextField,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import { useEventGuestLists } from '../../hooks/useGuestListsApi';
import {
  RSVP_STATUS_LABELS,
  type GuestPayload,
  type GuestResource,
  type RsvpStatus,
  useCreateGuest,
  useEventGuests,
  useUpdateGuest,
} from '../../hooks/useGuestsApi';
import type { ImportStatus } from '../../hooks/useImportsApi';
import { extractApiErrorCode, extractApiErrorMessage } from '../../utils/apiErrors';
import GuestForm, { type GuestFormMode } from './GuestForm';
import GuestImportDialog from './GuestImportDialog';
import { useToast } from '../common/ToastProvider';

interface EventGuestsTabProps {
  eventId: string;
}

interface GuestListOption {
  value: string;
  label: string;
}

const RSVP_FILTER_OPTIONS: { value: RsvpStatus; label: string }[] = [
  { value: 'none', label: RSVP_STATUS_LABELS.none },
  { value: 'invited', label: RSVP_STATUS_LABELS.invited },
  { value: 'confirmed', label: RSVP_STATUS_LABELS.confirmed },
  { value: 'declined', label: RSVP_STATUS_LABELS.declined },
];

const shouldShowToastForCode = (code: string | undefined) =>
  code === 'FORBIDDEN' || code === 'VALIDATION_ERROR';

const EventGuestsTab = ({ eventId }: EventGuestsTabProps) => {
  const { showToast } = useToast();
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [selectedStatuses, setSelectedStatuses] = useState<RsvpStatus[]>([]);
  const [selectedList, setSelectedList] = useState<string>('all');
  const [guestDialogOpen, setGuestDialogOpen] = useState(false);
  const [dialogMode, setDialogMode] = useState<GuestFormMode>('create');
  const [selectedGuest, setSelectedGuest] = useState<GuestResource | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [importOpen, setImportOpen] = useState(false);

  const guestFilters = useMemo(
    () => ({
      page,
      perPage: rowsPerPage,
      search: search.trim() !== '' ? search.trim() : undefined,
      rsvpStatus: selectedStatuses,
      guestListId:
        selectedList === 'all' ? undefined : selectedList === 'unassigned' ? null : selectedList,
    }),
    [page, rowsPerPage, search, selectedStatuses, selectedList],
  );

  const guestsQuery = useEventGuests(eventId, guestFilters);
  const guestListsQuery = useEventGuestLists(eventId, { page: 0, perPage: 100 });

  const guests = guestsQuery.data?.data ?? [];
  const totalCount = guestsQuery.data?.meta.total ?? 0;

  const guestLists = guestListsQuery.data?.data ?? [];

  const guestListOptions: GuestListOption[] = useMemo(() => {
    const options: GuestListOption[] = [
      { value: 'all', label: 'Todas las listas' },
      { value: 'unassigned', label: 'Sin lista' },
    ];
    guestLists.forEach((list) => {
      options.push({ value: list.id, label: list.name });
    });
    return options;
  }, [guestLists]);

  const guestListMap = useMemo(() => {
    const map = new Map<string, string>();
    guestLists.forEach((list) => {
      map.set(list.id, list.name);
    });
    return map;
  }, [guestLists]);

  const createGuestMutation = useCreateGuest(eventId);
  const updateGuestMutation = useUpdateGuest(eventId);

  const isSubmittingGuest = createGuestMutation.isPending || updateGuestMutation.isPending;

  useEffect(() => {
    if (guestsQuery.isError && guestsQuery.error) {
      const message = extractApiErrorMessage(guestsQuery.error, 'No se pudieron cargar los invitados.');
      const code = extractApiErrorCode(guestsQuery.error);
      if (shouldShowToastForCode(code)) {
        showToast({ message, severity: 'error' });
      }
    }
  }, [guestsQuery.isError, guestsQuery.error, showToast]);

  useEffect(() => {
    if (guestListsQuery.isError && guestListsQuery.error) {
      const message = extractApiErrorMessage(
        guestListsQuery.error,
        'No se pudieron cargar las listas de invitados.',
      );
      const code = extractApiErrorCode(guestListsQuery.error);
      if (shouldShowToastForCode(code)) {
        showToast({ message, severity: 'error' });
      }
    }
  }, [guestListsQuery.isError, guestListsQuery.error, showToast]);

  const handleSearchSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSearch(searchInput);
    setPage(0);
  };

  const handleClearSearch = () => {
    setSearch('');
    setSearchInput('');
    setPage(0);
  };

  const handleStatusesChange = (event: SelectChangeEvent<unknown>) => {
    const value = event.target.value;
    const parsed: RsvpStatus[] = Array.isArray(value)
      ? (value as RsvpStatus[])
      : value
        ? ((value as string).split(',').filter(Boolean) as RsvpStatus[])
        : [];
    setSelectedStatuses(parsed);
    setPage(0);
  };

  const handleListChange = (event: SelectChangeEvent<unknown>) => {
    setSelectedList(event.target.value as string);
    setPage(0);
  };

  const handleChangePage = (_event: unknown, newPage: number) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event: ChangeEvent<HTMLInputElement>) => {
    setRowsPerPage(Number(event.target.value));
    setPage(0);
  };

  const handleOpenCreate = () => {
    setDialogMode('create');
    setSelectedGuest(null);
    setFormError(null);
    setGuestDialogOpen(true);
  };

  const handleOpenEdit = (guest: GuestResource) => {
    setDialogMode('edit');
    setSelectedGuest(guest);
    setFormError(null);
    setGuestDialogOpen(true);
  };

  const handleCloseDialog = () => {
    if (isSubmittingGuest) {
      return;
    }
    setGuestDialogOpen(false);
    setSelectedGuest(null);
    setFormError(null);
  };

  const handleGuestSubmit = async (payload: GuestPayload) => {
    setFormError(null);
    try {
      if (dialogMode === 'edit' && selectedGuest) {
        await updateGuestMutation.mutateAsync({ guestId: selectedGuest.id, payload });
        showToast({ message: 'Invitado actualizado correctamente.', severity: 'success' });
      } else {
        await createGuestMutation.mutateAsync(payload);
        showToast({ message: 'Invitado creado correctamente.', severity: 'success' });
        if (page !== 0) {
          setPage(0);
        }
      }
      setGuestDialogOpen(false);
      setSelectedGuest(null);
    } catch (error) {
      const message = extractApiErrorMessage(error, 'No se pudo guardar el invitado.');
      setFormError(message);
      const code = extractApiErrorCode(error);
      if (shouldShowToastForCode(code)) {
        showToast({ message, severity: 'error' });
      }
    }
  };

  const handleImportStatusChange = (status: ImportStatus) => {
    if (status === 'completed' || status === 'failed') {
      void guestsQuery.refetch();
    }
  };

  const renderRsvp = (status: RsvpStatus | null) => {
    if (!status) {
      return RSVP_STATUS_LABELS.none;
    }
    return RSVP_STATUS_LABELS[status];
  };

  const renderGuestList = (guest: GuestResource) => {
    if (!guest.guest_list_id) {
      return 'Sin lista';
    }
    return guestListMap.get(guest.guest_list_id) ?? 'Lista desconocida';
  };

  const renderPlusOnes = (guest: GuestResource) => {
    if (!guest.allow_plus_ones) {
      return '0';
    }
    const value = guest.plus_ones_limit ?? 0;
    return value.toString();
  };

  return (
    <Stack spacing={3}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} justifyContent="space-between" alignItems={{ xs: 'stretch', md: 'center' }}>
        <Typography variant="h6">Invitados</Typography>
        <Stack direction="row" spacing={1} justifyContent={{ xs: 'flex-start', md: 'flex-end' }}>
          <Button variant="outlined" startIcon={<UploadFileIcon />} onClick={() => setImportOpen(true)}>
            Importar
          </Button>
          <Button variant="contained" startIcon={<AddIcon />} onClick={handleOpenCreate}>
            Nuevo invitado
          </Button>
        </Stack>
      </Stack>

      <Paper variant="outlined">
        <Box sx={{ p: 2 }}>
          <Stack direction={{ xs: 'column', lg: 'row' }} spacing={2} alignItems={{ xs: 'stretch', lg: 'center' }} justifyContent="space-between">
            <Box component="form" onSubmit={handleSearchSubmit} sx={{ display: 'flex', gap: 1, flexGrow: 1 }}>
              <TextField
                placeholder="Buscar por nombre, correo o teléfono"
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                fullWidth
              />
              <Button type="submit" variant="contained">
                Buscar
              </Button>
              {search && (
                <Button onClick={handleClearSearch} color="inherit">
                  Limpiar
                </Button>
              )}
            </Box>
            <Stack direction={{ xs: 'column', lg: 'row' }} spacing={2} sx={{ minWidth: { lg: 400 } }}>
              <FormControl fullWidth>
                <InputLabel id="rsvp-filter-label">RSVP</InputLabel>
                <Select
                  labelId="rsvp-filter-label"
                  multiple
                  value={selectedStatuses}
                  onChange={handleStatusesChange}
                  label="RSVP"
                  renderValue={(selected) =>
                    (selected as RsvpStatus[]).length === 0
                      ? 'Todos'
                      : (selected as RsvpStatus[])
                          .map((status) => RSVP_STATUS_LABELS[status])
                          .join(', ')
                  }
                >
                  {RSVP_FILTER_OPTIONS.map((option) => (
                    <MenuItem key={option.value} value={option.value}>
                      <Checkbox checked={selectedStatuses.includes(option.value)} />
                      <ListItemText primary={option.label} />
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
              <FormControl fullWidth>
                <InputLabel id="guest-list-filter-label">Lista</InputLabel>
                <Select
                  labelId="guest-list-filter-label"
                  value={selectedList}
                  onChange={handleListChange}
                  label="Lista"
                >
                  {guestListOptions.map((option) => (
                    <MenuItem key={option.value} value={option.value}>
                      {option.label}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Stack>
          </Stack>
        </Box>
        {guestsQuery.isError && (
          <Box px={2} pb={2}>
            <Alert severity="error">
              {extractApiErrorMessage(guestsQuery.error, 'No se pudieron cargar los invitados.')}
            </Alert>
          </Box>
        )}
        {guestsQuery.isLoading ? (
          <Box py={6} display="flex" justifyContent="center" alignItems="center">
            <CircularProgress />
          </Box>
        ) : (
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Nombre</TableCell>
                  <TableCell>Contacto</TableCell>
                  <TableCell>Lista</TableCell>
                  <TableCell>RSVP</TableCell>
                  <TableCell align="right">Acompañantes</TableCell>
                  <TableCell align="center">Acciones</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {guests.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} align="center">
                      <Typography variant="body2" color="text.secondary">
                        No se encontraron invitados con los filtros actuales.
                      </Typography>
                    </TableCell>
                  </TableRow>
                ) : (
                  guests.map((guest) => (
                    <TableRow key={guest.id} hover>
                      <TableCell>
                        <Typography variant="subtitle2">{guest.full_name}</Typography>
                        {guest.organization && (
                          <Typography variant="body2" color="text.secondary">
                            {guest.organization}
                          </Typography>
                        )}
                      </TableCell>
                      <TableCell>
                        <Stack spacing={0.5}>
                          {guest.email && <Typography variant="body2">{guest.email}</Typography>}
                          {guest.phone && (
                            <Typography variant="body2" color="text.secondary">
                              {guest.phone}
                            </Typography>
                          )}
                        </Stack>
                      </TableCell>
                      <TableCell>{renderGuestList(guest)}</TableCell>
                      <TableCell>{renderRsvp(guest.rsvp_status)}</TableCell>
                      <TableCell align="right">{renderPlusOnes(guest)}</TableCell>
                      <TableCell align="center">
                        <IconButton aria-label="Editar invitado" onClick={() => handleOpenEdit(guest)}>
                          <EditIcon />
                        </IconButton>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </TableContainer>
        )}
        <TablePagination
          component="div"
          count={totalCount}
          page={page}
          onPageChange={handleChangePage}
          rowsPerPage={rowsPerPage}
          onRowsPerPageChange={handleChangeRowsPerPage}
          rowsPerPageOptions={[5, 10, 25, 50]}
        />
      </Paper>

      <GuestForm
        open={guestDialogOpen}
        mode={dialogMode}
        guestLists={guestLists}
        initialGuest={selectedGuest}
        isSubmitting={isSubmittingGuest}
        error={formError}
        onClose={handleCloseDialog}
        onSubmit={async (payload) => {
          try {
            await handleGuestSubmit(payload);
          } catch {
            // El manejo de errores ya se realiza en handleGuestSubmit
          }
        }}
      />

      <GuestImportDialog
        eventId={eventId}
        open={importOpen}
        onClose={() => setImportOpen(false)}
        onStatusChange={(status) => handleImportStatusChange(status)}
      />
    </Stack>
  );
};

export default EventGuestsTab;
