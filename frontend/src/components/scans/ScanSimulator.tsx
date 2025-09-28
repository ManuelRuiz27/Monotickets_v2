import { useMemo, useState } from 'react';
import {
  Alert,
  AlertColor,
  Box,
  Button,
  CircularProgress,
  FormControl,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import { useMutation } from '@tanstack/react-query';
import { scanTicket, type ScanRequest, type ScanResponsePayload } from '../../api/scan';
import { useEventCheckpoints, type EventCheckpointResource } from '../../hooks/useCheckpointsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';

interface ScanSimulatorProps {
  eventId: string;
}

const RESULT_SEVERITY: Record<string, AlertColor> = {
  valid: 'success',
  duplicate: 'warning',
  expired: 'warning',
  invalid: 'error',
  revoked: 'error',
};

const ScanSimulator = ({ eventId }: ScanSimulatorProps) => {
  const [qrCode, setQrCode] = useState('');
  const [checkpointId, setCheckpointId] = useState('');
  const [result, setResult] = useState<ScanResponsePayload | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const checkpointsQuery = useEventCheckpoints(eventId);

  const scanMutation = useMutation({
    mutationFn: (payload: ScanRequest) => scanTicket(payload),
    onSuccess: (response) => {
      setResult(response.data);
      setFormError(null);
    },
    onError: (error) => {
      setResult(null);
      setFormError(extractApiErrorMessage(error, 'No se pudo procesar el escaneo.'));
    },
  });

  const checkpoints = useMemo<EventCheckpointResource[]>(() => checkpointsQuery.data ?? [], [
    checkpointsQuery.data,
  ]);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setResult(null);

    if (!qrCode.trim()) {
      setFormError('Debes ingresar un código QR.');
      return;
    }

    setFormError(null);

    const payload: ScanRequest = {
      qr_code: qrCode.trim(),
      checkpoint_id: checkpointId ? checkpointId : undefined,
      event_id: eventId,
      device_id: 'backoffice-simulator',
    };

    try {
      await scanMutation.mutateAsync(payload);
    } catch {
      // handled in onError
    }
  };

  const currentSeverity: AlertColor = result
    ? RESULT_SEVERITY[result.result] ?? 'info'
    : 'info';

  return (
    <Paper variant="outlined" sx={{ p: 3 }}>
      <Stack spacing={3} component="form" onSubmit={handleSubmit}>
        <Box>
          <Typography variant="h6">Simulador de escaneo</Typography>
          <Typography variant="body2" color="text.secondary">
            Prueba la validación de tickets ingresando el código QR y seleccionando un checkpoint.
          </Typography>
        </Box>

        {formError && (
          <Alert severity="error" onClose={() => setFormError(null)}>
            {formError}
          </Alert>
        )}

        <Stack spacing={2} direction={{ xs: 'column', md: 'row' }}>
          <TextField
            label="Código QR"
            value={qrCode}
            onChange={(event) => setQrCode(event.target.value)}
            placeholder="MT-XXXX-XXXX"
            fullWidth
          />
          <FormControl fullWidth>
            <InputLabel id="checkpoint-select-label">Checkpoint</InputLabel>
            <Select
              labelId="checkpoint-select-label"
              label="Checkpoint"
              value={checkpointId}
              onChange={(event) => setCheckpointId(event.target.value)}
              displayEmpty
            >
              <MenuItem value="">
                <em>Sin checkpoint</em>
              </MenuItem>
              {checkpointsQuery.isLoading ? (
                <MenuItem value="" disabled>
                  Cargando checkpoints...
                </MenuItem>
              ) : checkpointsQuery.isError ? (
                <MenuItem value="" disabled>
                  Error al cargar checkpoints
                </MenuItem>
              ) : (
                checkpoints.map((checkpoint) => (
                  <MenuItem key={checkpoint.id} value={checkpoint.id}>
                    {checkpoint.name}
                    {checkpoint.venue_name ? ` · ${checkpoint.venue_name}` : ''}
                  </MenuItem>
                ))
              )}
            </Select>
          </FormControl>
        </Stack>

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems={{ xs: 'stretch', sm: 'center' }}>
          <Button
            type="submit"
            variant="contained"
            disabled={scanMutation.isPending}
            startIcon={scanMutation.isPending ? <CircularProgress size={16} /> : undefined}
          >
            Simular escaneo
          </Button>
          {checkpointsQuery.isError && (
            <Typography variant="body2" color="error">
              {extractApiErrorMessage(
                checkpointsQuery.error,
                'No se pudieron obtener los checkpoints del evento.',
              )}
            </Typography>
          )}
        </Stack>

        {result && (
          <Alert severity={currentSeverity}>
            <Typography variant="subtitle1" component="p">
              Resultado: {result.result.toUpperCase()}
            </Typography>
            <Typography variant="body2">{result.message}</Typography>
            {result.reason && (
              <Typography variant="caption" display="block">
                Código interno: {result.reason}
              </Typography>
            )}
          </Alert>
        )}
      </Stack>
    </Paper>
  );
};

export default ScanSimulator;
