import { useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import type { TicketPayload, TicketType } from '../../hooks/useTicketsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';

interface IssueTicketDialogProps {
  open: boolean;
  onClose: () => void;
  onSubmit: (payload: TicketPayload) => Promise<void>;
  isSubmitting?: boolean;
}

interface FormState {
  type: TicketType;
  price: string;
  seatSection: string;
  seatRow: string;
  seatCode: string;
  expiresAt: string;
}

interface FormErrors {
  type?: string;
  price?: string;
  seat?: string;
}

const INITIAL_STATE: FormState = {
  type: 'general',
  price: '',
  seatSection: '',
  seatRow: '',
  seatCode: '',
  expiresAt: '',
};

const IssueTicketDialog = ({ open, onClose, onSubmit, isSubmitting = false }: IssueTicketDialogProps) => {
  const [formState, setFormState] = useState<FormState>(INITIAL_STATE);
  const [formErrors, setFormErrors] = useState<FormErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [isInternalSubmitting, setIsInternalSubmitting] = useState(false);

  useEffect(() => {
    if (!open) {
      setFormState(INITIAL_STATE);
      setFormErrors({});
      setFormError(null);
      setIsInternalSubmitting(false);
    }
  }, [open]);

  const isLoading = useMemo(() => isSubmitting || isInternalSubmitting, [isSubmitting, isInternalSubmitting]);

  const handleTypeChange = (event: SelectChangeEvent<string>) => {
    const value = event.target.value as TicketType;
    setFormState((prev) => ({ ...prev, type: value }));
  };

  const validate = (state: FormState): FormErrors => {
    const errors: FormErrors = {};

    if (!state.type) {
      errors.type = 'El tipo de ticket es obligatorio.';
    }

    if (state.price.trim() !== '') {
      const numericPrice = Number.parseFloat(state.price.replace(',', '.'));
      if (Number.isNaN(numericPrice) || numericPrice < 0) {
        errors.price = 'Ingresa un precio válido.';
      }
    }

    const hasAnySeat = [state.seatSection, state.seatRow, state.seatCode].some((value) => value.trim() !== '');
    const hasAllSeat = [state.seatSection, state.seatRow, state.seatCode].every((value) => value.trim() !== '');

    if (hasAnySeat && !hasAllSeat) {
      errors.seat = 'Debes completar sección, fila y asiento o dejar los tres campos vacíos.';
    }

    return errors;
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setFormError(null);

    const errors = validate(formState);
    setFormErrors(errors);

    if (Object.keys(errors).length > 0) {
      return;
    }

    const normalizedPrice =
      formState.price.trim() === '' ? null : Number.parseFloat(formState.price.replace(',', '.'));

    const payload: TicketPayload = {
      type: formState.type,
      price_cents: normalizedPrice === null ? 0 : Math.round(normalizedPrice * 100),
      seat_section: formState.seatSection.trim() !== '' ? formState.seatSection.trim() : null,
      seat_row: formState.seatRow.trim() !== '' ? formState.seatRow.trim() : null,
      seat_code: formState.seatCode.trim() !== '' ? formState.seatCode.trim() : null,
      expires_at: formState.expiresAt.trim() !== '' ? new Date(formState.expiresAt).toISOString() : null,
    };

    setIsInternalSubmitting(true);
    try {
      await onSubmit(payload);
    } catch (error) {
      setFormError(extractApiErrorMessage(error, 'No se pudo emitir el ticket.'));
    } finally {
      setIsInternalSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Emitir ticket</DialogTitle>
      <Box component="form" onSubmit={handleSubmit} noValidate>
        <DialogContent>
          <Stack spacing={2} mt={1}>
            <FormControl fullWidth>
              <InputLabel id="ticket-type-label">Tipo</InputLabel>
              <Select
                labelId="ticket-type-label"
                label="Tipo"
                value={formState.type}
                onChange={handleTypeChange}
                disabled={isLoading}
                error={Boolean(formErrors.type)}
              >
                <MenuItem value="general">General</MenuItem>
                <MenuItem value="vip">VIP</MenuItem>
                <MenuItem value="staff">Staff</MenuItem>
              </Select>
              {formErrors.type && (
                <Typography variant="caption" color="error" mt={0.5}>
                  {formErrors.type}
                </Typography>
              )}
            </FormControl>

            <TextField
              label="Precio"
              type="number"
              inputProps={{ min: 0, step: '0.01' }}
              value={formState.price}
              onChange={(event) => setFormState((prev) => ({ ...prev, price: event.target.value }))}
              disabled={isLoading}
              error={Boolean(formErrors.price)}
              helperText={formErrors.price}
            />

            <Stack spacing={1}>
              <Typography variant="subtitle2">Asignación de asiento (opcional)</Typography>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                <TextField
                  label="Sección"
                  value={formState.seatSection}
                  onChange={(event) => setFormState((prev) => ({ ...prev, seatSection: event.target.value }))}
                  disabled={isLoading}
                  fullWidth
                />
                <TextField
                  label="Fila"
                  value={formState.seatRow}
                  onChange={(event) => setFormState((prev) => ({ ...prev, seatRow: event.target.value }))}
                  disabled={isLoading}
                  fullWidth
                />
                <TextField
                  label="Asiento"
                  value={formState.seatCode}
                  onChange={(event) => setFormState((prev) => ({ ...prev, seatCode: event.target.value }))}
                  disabled={isLoading}
                  fullWidth
                />
              </Stack>
              {formErrors.seat && (
                <Typography variant="caption" color="error">
                  {formErrors.seat}
                </Typography>
              )}
            </Stack>

            <TextField
              label="Expira"
              type="datetime-local"
              InputLabelProps={{ shrink: true }}
              value={formState.expiresAt}
              onChange={(event) => setFormState((prev) => ({ ...prev, expiresAt: event.target.value }))}
              disabled={isLoading}
            />

            {formError && (
              <Typography variant="body2" color="error">
                {formError}
              </Typography>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={isLoading}>
            Cancelar
          </Button>
          <Button type="submit" variant="contained" disabled={isLoading}>
            Emitir
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
};

export default IssueTicketDialog;
