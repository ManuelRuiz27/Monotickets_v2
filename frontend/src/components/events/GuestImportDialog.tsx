import { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Link,
  Stack,
  Switch,
  TextField,
  Typography,
} from '@mui/material';
import { useCreateImport, useImport, type ImportResource, type ImportStatus } from '../../hooks/useImportsApi';
import { extractApiErrorCode, extractApiErrorMessage } from '../../utils/apiErrors';
import { useToast } from '../common/ToastProvider';

interface GuestImportDialogProps {
  eventId: string;
  open: boolean;
  onClose: () => void;
  onStatusChange?: (status: ImportStatus, resource: ImportResource) => void;
}

const DEFAULT_FILE_URL = 'https://files.example.com/guests.csv';

const DEFAULT_MAPPING: Record<string, string | null> = {
  full_name: 'nombre',
  email: 'email',
  phone: 'telefono',
  guest_list_id: 'lista',
  rsvp_status: 'rsvp',
  plus_ones_limit: 'acompanantes',
};

const STATUS_LABELS: Record<ImportStatus, string> = {
  uploaded: 'En cola',
  processing: 'Procesando',
  completed: 'Completado',
  failed: 'Fallido',
};

const POLLING_STATUSES: ImportStatus[] = ['uploaded', 'processing'];

const shouldShowToastForCode = (code: string | undefined) =>
  code === 'FORBIDDEN' || code === 'VALIDATION_ERROR';

const GuestImportDialog = ({ eventId, open, onClose, onStatusChange }: GuestImportDialogProps) => {
  const { showToast } = useToast();
  const [fileUrl, setFileUrl] = useState<string>(DEFAULT_FILE_URL);
  const [dedupe, setDedupe] = useState<boolean>(true);
  const [formError, setFormError] = useState<string | null>(null);
  const [queuedImport, setQueuedImport] = useState<ImportResource | null>(null);
  const [importId, setImportId] = useState<string | null>(null);
  const [lastStatus, setLastStatus] = useState<ImportStatus | null>(null);

  const createImportMutation = useCreateImport(eventId);

  const shouldPoll = (status: ImportStatus | null | undefined) =>
    status ? POLLING_STATUSES.includes(status) : false;

  const {
    data: importResponse,
    refetch,
    isFetching,
  } = useImport(importId ?? undefined, {
    enabled: open && Boolean(importId),
    refetchInterval: (query) => {
      const status = query.state.data?.data.status;
      return status && shouldPoll(status) ? 4000 : false;
    },
  });

  const importData = useMemo<ImportResource | null>(() => {
    if (importResponse?.data) {
      return importResponse.data;
    }
    return queuedImport;
  }, [importResponse?.data, queuedImport]);

  useEffect(() => {
    if (!open) {
      setFileUrl(DEFAULT_FILE_URL);
      setDedupe(true);
      setFormError(null);
      setQueuedImport(null);
      setImportId(null);
      setLastStatus(null);
    }
  }, [open]);

  useEffect(() => {
    if (!importData || importData.status === lastStatus) {
      return;
    }
    setLastStatus(importData.status);
    onStatusChange?.(importData.status, importData);

    if (importData.status === 'completed') {
      showToast({ message: 'Importación completada.', severity: 'success' });
    } else if (importData.status === 'failed') {
      showToast({ message: 'Importación finalizada con errores.', severity: 'warning' });
    } else if (importData.status === 'processing') {
      showToast({ message: 'Procesando importación…', severity: 'info', autoHideDuration: 4000 });
    }
  }, [importData, lastStatus, onStatusChange, showToast]);

  const handleSubmit = async () => {
    setFormError(null);
    if (!fileUrl.trim()) {
      setFormError('Debes proporcionar la URL del archivo a importar.');
      return;
    }

    try {
      const response = await createImportMutation.mutateAsync({
        source: 'csv',
        file_url: fileUrl.trim(),
        mapping: DEFAULT_MAPPING,
        options: {
          dedupe_by_email: dedupe,
        },
      });

      setQueuedImport(response.data);
      setImportId(response.data.id);
      setLastStatus(response.data.status);
      showToast({ message: 'Importación encolada. Seguiremos el progreso.', severity: 'success' });
    } catch (error) {
      const message = extractApiErrorMessage(error, 'No se pudo iniciar la importación.');
      setFormError(message);
      const code = extractApiErrorCode(error);
      if (shouldShowToastForCode(code)) {
        showToast({ message, severity: 'error' });
      }
    }
  };

  const handleManualRefresh = async () => {
    setFormError(null);
    try {
      await refetch();
    } catch (error) {
      const message = extractApiErrorMessage(error, 'No se pudo actualizar el estado de la importación.');
      setFormError(message);
      const code = extractApiErrorCode(error);
      if (shouldShowToastForCode(code)) {
        showToast({ message, severity: 'error' });
      }
    }
  };

  const isSubmitting = createImportMutation.isPending;
  const hasQueuedImport = Boolean(importData);

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Importar invitados</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          <Typography variant="body2" color="text.secondary">
            Proporciona la URL del archivo con tus invitados. Usaremos un mapeo de ejemplo para crear los
            registros.
          </Typography>
          {formError && <Alert severity="error">{formError}</Alert>}
          <TextField
            label="URL del archivo"
            value={fileUrl}
            onChange={(event) => setFileUrl(event.target.value)}
            fullWidth
            disabled={isSubmitting}
            helperText="Puede ser una URL temporal o simulada para efectos de demostración."
          />
          <FormControlLabel
            control={
              <Switch
                checked={dedupe}
                onChange={(event) => setDedupe(event.target.checked)}
                disabled={isSubmitting}
              />
            }
            label="Evitar duplicados por correo electrónico"
          />
          {hasQueuedImport && importData && (
            <Box>
              <Typography variant="subtitle2" gutterBottom>
                Estado de la importación
              </Typography>
              <Stack spacing={1}>
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography variant="body2">Estado:</Typography>
                  <Chip label={STATUS_LABELS[importData.status]} color={importData.status === 'completed' ? 'success' : importData.status === 'failed' ? 'error' : 'default'} />
                  {isFetching && <CircularProgress size={16} />}
                </Stack>
                {typeof importData.progress === 'number' && (
                  <Typography variant="body2">
                    Progreso: {Math.round(importData.progress * 100)}%
                  </Typography>
                )}
                <Typography variant="body2">Filas totales: {importData.rows_total}</Typography>
                <Typography variant="body2">Filas procesadas correctamente: {importData.rows_ok}</Typography>
                <Typography variant="body2">Filas con errores: {importData.rows_failed}</Typography>
                {importData.report_file_url && (
                  <Typography variant="body2">
                    Reporte de errores:{' '}
                    <Link href={importData.report_file_url} target="_blank" rel="noopener">
                      Ver reporte
                    </Link>
                  </Typography>
                )}
              </Stack>
            </Box>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        {hasQueuedImport && (
          <Button onClick={handleManualRefresh} disabled={isFetching} color="inherit">
            {isFetching ? 'Actualizando…' : 'Actualizar estado'}
          </Button>
        )}
        <Button onClick={onClose} color="inherit" disabled={isSubmitting}>
          Cerrar
        </Button>
        <Button onClick={handleSubmit} variant="contained" disabled={isSubmitting}>
          {isSubmitting ? 'Importando…' : 'Importar'}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default GuestImportDialog;
