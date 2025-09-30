import { Box, Chip, Divider, List, Paper, Stack, Typography } from '@mui/material';
import { DateTime } from 'luxon';
import type { AttendanceCacheRecord } from '../../services/scanSync';
import { maskSensitiveText } from '../../utils/privacy';

interface ScanHistoryProps {
  history: AttendanceCacheRecord[];
  pendingCount: number;
}

function formatTimestamp(value: string | null): string {
  if (!value) {
    return 'Sin horario';
  }

  const parsed = DateTime.fromISO(value);
  if (!parsed.isValid) {
    return value;
  }

  return parsed.toFormat('dd/MM/yyyy HH:mm:ss');
}

const statusChipColor = (status: AttendanceCacheRecord['status']): 'success' | 'warning' =>
  status === 'pending' ? 'warning' : 'success';

const statusLabel = (status: AttendanceCacheRecord['status']): string =>
  status === 'pending' ? 'Pendiente' : 'Sincronizado';

const ScanHistory = ({ history, pendingCount }: ScanHistoryProps) => {
  return (
    <Paper elevation={0} variant="outlined" component="section" aria-live="polite">
      <Stack spacing={2} sx={{ p: 3 }}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} justifyContent="space-between" alignItems={{ xs: 'flex-start', sm: 'center' }}>
          <Typography variant="h6" component="h3">
            Historial local
          </Typography>
          <Chip
            label={`Pendientes: ${pendingCount}`}
            color={pendingCount > 0 ? 'warning' : 'success'}
            variant={pendingCount > 0 ? 'filled' : 'outlined'}
          />
        </Stack>

        {history.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Aún no hay registros almacenados en este dispositivo.
          </Typography>
        ) : (
          <List disablePadding sx={{ display: 'grid', gap: 1.5 }}>
            {history.map((item, index) => (
              <Paper key={item.id} variant="outlined" sx={{ p: 2 }}>
                <Stack spacing={1}>
                  <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', sm: 'center' }} spacing={1}>
                    <Box>
                      <Typography variant="subtitle2" component="span">
                        {maskSensitiveText(item.qr_code ?? '—')}
                      </Typography>
                      <Typography variant="caption" color="text.secondary" display="block">
                        {formatTimestamp(item.scanned_at)}
                      </Typography>
                    </Box>
                    <Chip
                      label={statusLabel(item.status)}
                      color={statusChipColor(item.status)}
                      size="small"
                    />
                  </Stack>

                  <Typography variant="body2" color="text.secondary">
                    {maskSensitiveText(item.message ?? `Resultado: ${item.result}`)}
                  </Typography>

                  <Stack direction="row" spacing={1} flexWrap="wrap">
                    {item.offline && <Chip size="small" variant="outlined" label="Registrado offline" />}
                    {item.conflict && <Chip size="small" color="warning" variant="outlined" label="Duplicado detectado" />}
                    {item.reason && (
                      <Chip
                        size="small"
                        variant="outlined"
                        label={maskSensitiveText(item.reason)}
                        sx={{ maxWidth: '100%' }}
                      />
                    )}
                  </Stack>
                </Stack>
                {index < history.length - 1 && <Divider sx={{ mt: 2 }} />}
              </Paper>
            ))}
          </List>
        )}
      </Stack>
    </Paper>
  );
};

export default ScanHistory;
