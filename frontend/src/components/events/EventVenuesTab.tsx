import { useEffect, useMemo, useState, type ChangeEvent, type FormEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  IconButton,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import type { VenueResource, VenuePayload } from '../../hooks/useVenuesApi';
import { useCreateVenue, useDeleteVenue, useEventVenues, useUpdateVenue } from '../../hooks/useVenuesApi';
import { extractApiErrorMessage } from '../../hooks/useEventsApi';

interface EventVenuesTabProps {
  eventId: string;
}

interface VenueFormState {
  name: string;
  address: string;
  lat: string;
  lng: string;
}

const EMPTY_FORM_STATE: VenueFormState = {
  name: '',
  address: '',
  lat: '',
  lng: '',
};

const EventVenuesTab = ({ eventId }: EventVenuesTabProps) => {
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [dialogMode, setDialogMode] = useState<'create' | 'edit' | null>(null);
  const [formState, setFormState] = useState<VenueFormState>(EMPTY_FORM_STATE);
  const [formErrors, setFormErrors] = useState<Partial<VenueFormState>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [selectedVenue, setSelectedVenue] = useState<VenueResource | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<VenueResource | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const filters = useMemo(() => ({ page, perPage: rowsPerPage }), [page, rowsPerPage]);
  const { data, isLoading, isError, error } = useEventVenues(eventId, filters);

  const venues = data?.data ?? [];
  const totalCount = data?.meta.total ?? 0;

  const createMutation = useCreateVenue(eventId);
  const updateMutation = useUpdateVenue(eventId);
  const deleteMutation = useDeleteVenue(eventId);

  const isDialogOpen = dialogMode !== null;
  const isSubmitting = createMutation.isPending || updateMutation.isPending;

  useEffect(() => {
    if (dialogMode === 'edit' && selectedVenue) {
      setFormState({
        name: selectedVenue.name ?? '',
        address: selectedVenue.address ?? '',
        lat: selectedVenue.lat !== null && selectedVenue.lat !== undefined ? String(selectedVenue.lat) : '',
        lng: selectedVenue.lng !== null && selectedVenue.lng !== undefined ? String(selectedVenue.lng) : '',
      });
    } else if (!isDialogOpen) {
      setFormState(EMPTY_FORM_STATE);
      setFormErrors({});
      setFormError(null);
    }
  }, [dialogMode, isDialogOpen, selectedVenue]);

  const handleOpenCreate = () => {
    setDialogMode('create');
    setSelectedVenue(null);
  };

  const handleOpenEdit = (venue: VenueResource) => {
    setSelectedVenue(venue);
    setDialogMode('edit');
  };

  const handleCloseDialog = () => {
    setDialogMode(null);
    setSelectedVenue(null);
    setFormState(EMPTY_FORM_STATE);
    setFormErrors({});
    setFormError(null);
  };

  const handleChangePage = (_event: unknown, newPage: number) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event: ChangeEvent<HTMLInputElement>) => {
    setRowsPerPage(Number(event.target.value));
    setPage(0);
  };

  const validate = (state: VenueFormState) => {
    const errors: Partial<VenueFormState> = {};
    if (!state.name.trim()) {
      errors.name = 'El nombre es obligatorio.';
    }
    if (state.lat.trim()) {
      const value = Number(state.lat);
      if (Number.isNaN(value)) {
        errors.lat = 'La latitud debe ser numérica.';
      }
    }
    if (state.lng.trim()) {
      const value = Number(state.lng);
      if (Number.isNaN(value)) {
        errors.lng = 'La longitud debe ser numérica.';
      }
    }
    return errors;
  };

  const buildPayload = (state: VenueFormState): VenuePayload => ({
    name: state.name.trim(),
    address: state.address.trim() ? state.address.trim() : null,
    lat: state.lat.trim() ? Number(state.lat) : null,
    lng: state.lng.trim() ? Number(state.lng) : null,
  });

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);
    const errors = validate(formState);
    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      return;
    }
    setFormErrors({});

    const payload = buildPayload(formState);

    try {
      if (dialogMode === 'edit' && selectedVenue) {
        await updateMutation.mutateAsync({ venueId: selectedVenue.id, payload });
      } else {
        await createMutation.mutateAsync(payload);
        if (page !== 0) {
          setPage(0);
        }
      }
      handleCloseDialog();
    } catch (mutationError) {
      setFormError(extractApiErrorMessage(mutationError, 'No se pudo guardar el venue.'));
    }
  };

  const handleDeleteConfirm = async () => {
    if (!deleteTarget) {
      return;
    }
    setActionError(null);
    try {
      await deleteMutation.mutateAsync({ venueId: deleteTarget.id });
      setDeleteTarget(null);
      if (page !== 0 && venues.length === 1) {
        setPage((prev) => Math.max(prev - 1, 0));
      }
    } catch (mutationError) {
      setActionError(extractApiErrorMessage(mutationError, 'No se pudo eliminar el venue.'));
    }
  };

  return (
    <Stack spacing={3}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} justifyContent="space-between" alignItems={{ xs: 'stretch', sm: 'center' }}>
        <Typography variant="h6">Venues registrados</Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={handleOpenCreate}>
          Nuevo venue
        </Button>
      </Stack>
      {actionError && (
        <Alert severity="error" onClose={() => setActionError(null)}>
          {actionError}
        </Alert>
      )}
      <Paper variant="outlined">
        {isLoading ? (
          <Box py={6} display="flex" alignItems="center" justifyContent="center">
            <CircularProgress />
          </Box>
        ) : isError ? (
          <Box py={6} px={3}>
            <Alert severity="error">
              {extractApiErrorMessage(error, 'No se pudieron cargar los venues.')}
            </Alert>
          </Box>
        ) : venues.length === 0 ? (
          <Box py={6} display="flex" alignItems="center" justifyContent="center">
            <Typography variant="body2" color="text.secondary">
              No se han registrado venues para este evento.
            </Typography>
          </Box>
        ) : (
          <>
            <TableContainer>
              <Table>
                <TableHead>
                  <TableRow>
                    <TableCell>Nombre</TableCell>
                    <TableCell>Dirección</TableCell>
                    <TableCell>Latitud</TableCell>
                    <TableCell>Longitud</TableCell>
                    <TableCell align="right">Acciones</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {venues.map((venue) => (
                    <TableRow key={venue.id} hover>
                      <TableCell>
                        <Typography variant="subtitle2">{venue.name}</Typography>
                        <Typography variant="caption" color="text.secondary">
                          ID: {venue.id}
                        </Typography>
                      </TableCell>
                      <TableCell>{venue.address ?? '—'}</TableCell>
                      <TableCell>{venue.lat ?? '—'}</TableCell>
                      <TableCell>{venue.lng ?? '—'}</TableCell>
                      <TableCell align="right">
                        <Tooltip title="Editar">
                          <span>
                            <IconButton size="small" onClick={() => handleOpenEdit(venue)}>
                              <EditIcon fontSize="small" />
                            </IconButton>
                          </span>
                        </Tooltip>
                        <Tooltip title="Eliminar">
                          <span>
                            <IconButton size="small" color="error" onClick={() => setDeleteTarget(venue)}>
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

      <Dialog open={isDialogOpen} onClose={handleCloseDialog} fullWidth maxWidth="sm" component="form" onSubmit={handleSubmit}>
        <DialogTitle>{dialogMode === 'edit' ? 'Editar venue' : 'Nuevo venue'}</DialogTitle>
        <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 2 }}>
          {formError && (
            <Alert severity="error" onClose={() => setFormError(null)}>
              {formError}
            </Alert>
          )}
          <TextField
            label="Nombre"
            value={formState.name}
            onChange={(event) => setFormState((prev) => ({ ...prev, name: event.target.value }))}
            required
            error={Boolean(formErrors.name)}
            helperText={formErrors.name ?? 'Identifica el venue dentro del evento.'}
            fullWidth
          />
          <TextField
            label="Dirección"
            value={formState.address}
            onChange={(event) => setFormState((prev) => ({ ...prev, address: event.target.value }))}
            helperText="Opcional"
            fullWidth
          />
          <TextField
            label="Latitud"
            value={formState.lat}
            onChange={(event) => setFormState((prev) => ({ ...prev, lat: event.target.value }))}
            helperText={formErrors.lat ?? 'Utiliza formato decimal (ej. 25.6754).'}
            error={Boolean(formErrors.lat)}
            fullWidth
          />
          <TextField
            label="Longitud"
            value={formState.lng}
            onChange={(event) => setFormState((prev) => ({ ...prev, lng: event.target.value }))}
            helperText={formErrors.lng ?? 'Utiliza formato decimal (ej. -100.3098).'}
            error={Boolean(formErrors.lng)}
            fullWidth
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" variant="contained" disabled={isSubmitting}>
            {isSubmitting ? 'Guardando…' : 'Guardar'}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={Boolean(deleteTarget)} onClose={() => setDeleteTarget(null)}>
        <DialogTitle>Eliminar venue</DialogTitle>
        <DialogContent>
          <DialogContentText>
            Esta acción eliminará "{deleteTarget?.name}" del evento. Los checkpoints asociados también se deshabilitarán. ¿Deseas continuar?
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteTarget(null)} disabled={deleteMutation.isPending}>
            Cancelar
          </Button>
          <Button
            onClick={handleDeleteConfirm}
            variant="contained"
            color="error"
            disabled={deleteMutation.isPending}
          >
            {deleteMutation.isPending ? 'Eliminando…' : 'Eliminar'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
};

export default EventVenuesTab;
