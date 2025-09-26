import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import {
  Alert,
  Box,
  Breadcrumbs,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  IconButton,
  Link,
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
import type { ChangeEvent, FormEvent } from 'react';
import { useEvent } from '../../hooks/useEventsApi';
import { useVenue } from '../../hooks/useVenuesApi';
import {
  type CheckpointResource,
  type CheckpointPayload,
  useCreateCheckpoint,
  useDeleteCheckpoint,
  useUpdateCheckpoint,
  useVenueCheckpoints,
} from '../../hooks/useCheckpointsApi';
import { extractApiErrorCode, extractApiErrorMessage } from '../../utils/apiErrors';
import { useToast } from '../common/ToastProvider';

interface VenueDetailProps {
  eventId: string;
  venueId: string;
}

type DialogMode = 'create' | 'edit';

type FormState = {
  name: string;
  description: string;
};

type FormErrors = Partial<FormState>;

const EMPTY_FORM_STATE: FormState = {
  name: '',
  description: '',
};

const shouldShowToastForCode = (code: string | undefined) =>
  code === 'FORBIDDEN' || code === 'VALIDATION_ERROR';

const VenueDetail = ({ eventId, venueId }: VenueDetailProps) => {
  const { showToast } = useToast();
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [dialogMode, setDialogMode] = useState<DialogMode>('create');
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [formState, setFormState] = useState<FormState>(EMPTY_FORM_STATE);
  const [formErrors, setFormErrors] = useState<FormErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [selectedCheckpoint, setSelectedCheckpoint] = useState<CheckpointResource | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<CheckpointResource | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const filters = useMemo(() => ({ page, perPage: rowsPerPage }), [page, rowsPerPage]);

  const {
    data: eventData,
    isLoading: isEventLoading,
    isError: isEventError,
    error: eventError,
  } = useEvent(eventId);

  const {
    data: venueData,
    isLoading: isVenueLoading,
    isError: isVenueError,
    error: venueError,
  } = useVenue(eventId, venueId);

  const {
    data: checkpointsData,
    isLoading: isCheckpointsLoading,
    isError: isCheckpointsError,
    error: checkpointsError,
  } = useVenueCheckpoints(eventId, venueId, filters);

  const createMutation = useCreateCheckpoint(eventId, venueId);
  const updateMutation = useUpdateCheckpoint(eventId, venueId);
  const deleteMutation = useDeleteCheckpoint(eventId, venueId);

  const event = eventData?.data;
  const venue = venueData?.data;
  const checkpoints = checkpointsData?.data ?? [];
  const totalCount = checkpointsData?.meta.total ?? 0;

  useEffect(() => {
    if (isEventError && eventError) {
      const code = extractApiErrorCode(eventError);
      if (shouldShowToastForCode(code)) {
        showToast({
          message: extractApiErrorMessage(eventError, 'No se pudo cargar la información del evento.'),
          severity: 'error',
        });
      }
    }
  }, [isEventError, eventError, showToast]);

  useEffect(() => {
    if (isVenueError && venueError) {
      const code = extractApiErrorCode(venueError);
      if (shouldShowToastForCode(code)) {
        showToast({
          message: extractApiErrorMessage(venueError, 'No se pudo cargar la información del venue.'),
          severity: 'error',
        });
      }
    }
  }, [isVenueError, venueError, showToast]);

  useEffect(() => {
    if (isCheckpointsError && checkpointsError) {
      const code = extractApiErrorCode(checkpointsError);
      if (shouldShowToastForCode(code)) {
        showToast({
          message: extractApiErrorMessage(checkpointsError, 'No se pudieron cargar los checkpoints.'),
          severity: 'error',
        });
      }
    }
  }, [isCheckpointsError, checkpointsError, showToast]);

  useEffect(() => {
    if (dialogMode === 'edit' && isDialogOpen && selectedCheckpoint) {
      setFormState({
        name: selectedCheckpoint.name ?? '',
        description: selectedCheckpoint.description ?? '',
      });
    }
    if (!isDialogOpen) {
      setFormState(EMPTY_FORM_STATE);
      setFormErrors({});
      setFormError(null);
      setIsSubmitting(false);
    }
  }, [dialogMode, isDialogOpen, selectedCheckpoint]);

  const handleChangePage = (_event: unknown, newPage: number) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event: ChangeEvent<HTMLInputElement>) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  const handleOpenCreate = () => {
    setDialogMode('create');
    setSelectedCheckpoint(null);
    setIsDialogOpen(true);
  };

  const handleOpenEdit = (checkpoint: CheckpointResource) => {
    setDialogMode('edit');
    setSelectedCheckpoint(checkpoint);
    setIsDialogOpen(true);
  };

  const handleCloseDialog = () => {
    setIsDialogOpen(false);
    setSelectedCheckpoint(null);
  };

  const validate = useCallback((state: FormState): FormErrors => {
    const errors: FormErrors = {};
    if (!state.name.trim()) {
      errors.name = 'El nombre es obligatorio.';
    }
    return errors;
  }, []);

  const buildPayload = (state: FormState): CheckpointPayload => ({
    name: state.name.trim(),
    description: state.description.trim() === '' ? null : state.description.trim(),
    event_id: eventId,
    venue_id: venueId,
  });

  const handleApiError = useCallback(
    (error: unknown, fallback: string) => {
      const message = extractApiErrorMessage(error, fallback);
      const code = extractApiErrorCode(error);
      if (shouldShowToastForCode(code)) {
        showToast({ message, severity: 'error' });
      }
      return message;
    },
    [showToast],
  );

  const handleSubmit = async (event: FormEvent<HTMLDivElement>) => {
    event.preventDefault();
    setFormError(null);
    setFormErrors({});

    const validationErrors = validate(formState);
    if (Object.keys(validationErrors).length > 0) {
      setFormErrors(validationErrors);
      return;
    }

    const payload = buildPayload(formState);

    setIsSubmitting(true);
    try {
      if (dialogMode === 'edit' && selectedCheckpoint) {
        await updateMutation.mutateAsync({ checkpointId: selectedCheckpoint.id, payload });
      } else {
        await createMutation.mutateAsync(payload);
        if (page !== 0) {
          setPage(0);
        }
      }
      handleCloseDialog();
    } catch (error) {
      setFormError(handleApiError(error, 'No se pudo guardar el checkpoint.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDeleteConfirm = async () => {
    if (!deleteTarget) {
      return;
    }
    setActionError(null);
    try {
      await deleteMutation.mutateAsync({ checkpointId: deleteTarget.id });
      setDeleteTarget(null);
      if (page !== 0 && checkpoints.length === 1) {
        setPage((prev) => Math.max(prev - 1, 0));
      }
    } catch (error) {
      setActionError(handleApiError(error, 'No se pudo eliminar el checkpoint.'));
    }
  };

  if (isEventLoading || isVenueLoading) {
    return (
      <Box py={6} display="flex" alignItems="center" justifyContent="center">
        <CircularProgress />
      </Box>
    );
  }

  if (isEventError || isVenueError) {
    return (
      <Box py={6} px={3} display="flex" justifyContent="center">
        <Alert severity="error">
          {isEventError && eventError
            ? extractApiErrorMessage(eventError, 'No se pudo cargar la información del evento.')
            : extractApiErrorMessage(venueError, 'No se pudo cargar la información del venue.')}
        </Alert>
      </Box>
    );
  }

  if (!event || !venue) {
    return (
      <Box py={6} px={3} display="flex" justifyContent="center">
        <Alert severity="warning">No se encontró la información solicitada.</Alert>
      </Box>
    );
  }

  return (
    <Stack spacing={3} sx={{ px: { xs: 2, md: 4 }, py: { xs: 2, md: 3 } }}>
      <Breadcrumbs aria-label="breadcrumbs">
        <Link component={RouterLink} to={`/events/${eventId}`} underline="hover" color="inherit">
          {event.name || 'Evento'}
        </Link>
        <Typography color="text.primary">{venue.name || 'Venue'}</Typography>
        <Typography color="text.primary">Checkpoints</Typography>
      </Breadcrumbs>

      <Stack direction={{ xs: 'column', sm: 'row' }} alignItems={{ xs: 'flex-start', sm: 'center' }} spacing={2}>
        <Box sx={{ flexGrow: 1 }}>
          <Typography variant="h4">Checkpoints</Typography>
          <Typography variant="body2" color="text.secondary">
            Administra los checkpoints asociados al venue.
          </Typography>
        </Box>
        <Button variant="contained" startIcon={<AddIcon />} onClick={handleOpenCreate}>
          Nuevo checkpoint
        </Button>
      </Stack>

      {actionError && (
        <Alert severity="error" onClose={() => setActionError(null)}>
          {actionError}
        </Alert>
      )}

      <Paper variant="outlined">
        {isCheckpointsLoading ? (
          <Box py={6} display="flex" alignItems="center" justifyContent="center">
            <CircularProgress />
          </Box>
        ) : isCheckpointsError ? (
          <Box py={6} px={3}>
            <Alert severity="error">
              {extractApiErrorMessage(checkpointsError, 'No se pudieron cargar los checkpoints.')}
            </Alert>
          </Box>
        ) : checkpoints.length === 0 ? (
          <Box py={6} display="flex" alignItems="center" justifyContent="center">
            <Typography variant="body2" color="text.secondary">
              No se han registrado checkpoints para este venue.
            </Typography>
          </Box>
        ) : (
          <>
            <TableContainer>
              <Table>
                <TableHead>
                  <TableRow>
                    <TableCell>Nombre</TableCell>
                    <TableCell>Descripción</TableCell>
                    <TableCell align="right">Acciones</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {checkpoints.map((checkpoint: CheckpointResource) => (
                    <TableRow key={checkpoint.id} hover>
                      <TableCell>
                        <Typography variant="subtitle2">{checkpoint.name}</Typography>
                        <Typography variant="caption" color="text.secondary">
                          ID: {checkpoint.id}
                        </Typography>
                      </TableCell>
                      <TableCell>{checkpoint.description ?? '—'}</TableCell>
                      <TableCell align="right">
                        <Tooltip title="Editar">
                          <span>
                            <IconButton size="small" onClick={() => handleOpenEdit(checkpoint)}>
                              <EditIcon fontSize="small" />
                            </IconButton>
                          </span>
                        </Tooltip>
                        <Tooltip title="Eliminar">
                          <span>
                            <IconButton
                              size="small"
                              color="error"
                              onClick={() => setDeleteTarget(checkpoint)}
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

      <Dialog
        open={isDialogOpen}
        onClose={handleCloseDialog}
        fullWidth
        maxWidth="sm"
        component="form"
        onSubmit={handleSubmit}
      >
        <DialogTitle>{dialogMode === 'edit' ? 'Editar checkpoint' : 'Nuevo checkpoint'}</DialogTitle>
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
            helperText={formErrors.name ?? 'Identifica el checkpoint.'}
            fullWidth
          />
          <TextField
            label="Descripción"
            value={formState.description}
            onChange={(event) => setFormState((prev) => ({ ...prev, description: event.target.value }))}
            helperText="Opcional"
            multiline
            minRows={3}
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
        <DialogTitle>Eliminar checkpoint</DialogTitle>
        <DialogContent>
          <DialogContentText>
            Esta acción deshabilitará "{deleteTarget?.name}". ¿Deseas continuar?
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

export default VenueDetail;
