import { useMemo, useState } from 'react';
import {
  Alert,
  AlertTitle,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  Typography,
} from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import CheckIcon from '@mui/icons-material/Check';
import type { TicketResource } from '../../hooks/useTicketsApi';
import { useTicketQr } from '../../hooks/useTicketsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';

interface TicketQrDialogProps {
  ticket: TicketResource | null;
  open: boolean;
  onClose: () => void;
}

const formatTimestamp = (value: string | null | undefined) => {
  if (!value) {
    return '—';
  }
  try {
    const date = new Date(value);
    return new Intl.DateTimeFormat('es-AR', {
      dateStyle: 'short',
      timeStyle: 'short',
    }).format(date);
  } catch {
    return value;
  }
};

const TicketQrDialog = ({ ticket, open, onClose }: TicketQrDialogProps) => {
  const ticketId = ticket?.id;
  const { data, isLoading, isError, error, refetch, isFetching } = useTicketQr(ticketId, {
    enabled: open && Boolean(ticketId),
  });
  const [copied, setCopied] = useState(false);
  const canUseClipboard = typeof navigator !== 'undefined' && Boolean(navigator.clipboard);

  const qr = data?.data;
  const helperText = useMemo(() => {
    if (!qr) {
      return null;
    }
    const pieces = [] as string[];
    pieces.push(`Versión ${qr.version}`);
    pieces.push(`Última actualización: ${formatTimestamp(qr.updated_at ?? null)}`);
    return pieces.join(' · ');
  }, [qr]);

  const handleCopy = async () => {
    if (!qr?.code || !canUseClipboard) {
      return;
    }
    try {
      await navigator.clipboard.writeText(qr.code);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>QR del ticket</DialogTitle>
      <DialogContent dividers>
        {isLoading || isFetching ? (
          <Box py={4} display="flex" justifyContent="center">
            <CircularProgress size={32} />
          </Box>
        ) : isError ? (
          <Alert
            severity="error"
            action={
              <Button color="inherit" size="small" onClick={() => void refetch()}>
                Reintentar
              </Button>
            }
          >
            {extractApiErrorMessage(error, 'No se pudo obtener el QR del ticket.')}
          </Alert>
        ) : !qr ? (
          <Alert severity="info">
            <AlertTitle>QR no disponible</AlertTitle>
            Este ticket no tiene un código QR activo. Utiliza la opción «Rotar QR» para generar uno nuevo.
          </Alert>
        ) : (
          <Stack spacing={2} alignItems="center">
            <Box
              sx={{
                width: '100%',
                border: '1px dashed',
                borderColor: 'divider',
                borderRadius: 2,
                p: 2,
                textAlign: 'center',
                backgroundColor: 'background.paper',
              }}
            >
              <Typography variant="subtitle2" color="text.secondary">
                Código QR
              </Typography>
              <Typography
                variant="h5"
                component="p"
                sx={{ fontFamily: 'monospace', wordBreak: 'break-all', mt: 1 }}
              >
                {qr.code}
              </Typography>
            </Box>
            {helperText && (
              <Typography variant="body2" color="text.secondary" textAlign="center">
                {helperText}
              </Typography>
            )}
            <Button
              variant="outlined"
              startIcon={copied ? <CheckIcon /> : <ContentCopyIcon />}
              onClick={handleCopy}
              disabled={!canUseClipboard || !qr.code}
            >
              {copied ? 'Copiado' : 'Copiar código'}
            </Button>
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cerrar</Button>
      </DialogActions>
    </Dialog>
  );
};

export default TicketQrDialog;
