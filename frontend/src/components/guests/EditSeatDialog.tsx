import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, TextField, Typography } from '@mui/material';
import type { TicketPayload, TicketResource } from '../../hooks/useTicketsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';

interface EditSeatDialogProps {
  ticket: TicketResource | null;
  open: boolean;
  onClose: () => void;
  onSubmit: (payload: TicketPayload) => Promise<void>;
  isSubmitting?: boolean;
}

interface FormState {
  seatSection: string;
  seatRow: string;
  seatCode: string;
}

interface FormErrors {
  seat?: string;
}

const INITIAL_STATE: FormState = {
  seatSection: '',
  seatRow: '',
  seatCode: '',
};

const EditSeatDialog = ({ ticket, open, onClose, onSubmit, isSubmitting = false }: EditSeatDialogProps) => {
  const [formState, setFormState] = useState<FormState>(INITIAL_STATE);
  const [formErrors, setFormErrors] = useState<FormErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [isInternalSubmitting, setIsInternalSubmitting] = useState(false);

  useEffect(() => {
    if (open && ticket) {
      setFormState({
        seatSection: ticket.seat_section ?? '',
        seatRow: ticket.seat_row ?? '',
        seatCode: ticket.seat_code ?? '',
      });
    }
    if (!open) {
      setFormErrors({});
      setFormError(null);
      setIsInternalSubmitting(false);
    }
  }, [open, ticket]);

  const isLoading = useMemo(() => isSubmitting || isInternalSubmitting, [isSubmitting, isInternalSubmitting]);

  const validate = (state: FormState): FormErrors => {
    const errors: FormErrors = {};
    const hasAnySeat = [state.seatSection, state.seatRow, state.seatCode].some((value) => value.trim() !== '');
    const hasAllSeat = [state.seatSection, state.seatRow, state.seatCode].every((value) => value.trim() !== '');

    if (hasAnySeat && !hasAllSeat) {
      errors.seat = 'Completa sección, fila y asiento o deja los tres campos vacíos.';
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

    const payload: TicketPayload = {
      seat_section: formState.seatSection.trim() !== '' ? formState.seatSection.trim() : null,
      seat_row: formState.seatRow.trim() !== '' ? formState.seatRow.trim() : null,
      seat_code: formState.seatCode.trim() !== '' ? formState.seatCode.trim() : null,
    };

    setIsInternalSubmitting(true);
    try {
      await onSubmit(payload);
    } catch (error) {
      setFormError(extractApiErrorMessage(error, 'No se pudo actualizar el asiento.'));
    } finally {
      setIsInternalSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle>Editar asiento</DialogTitle>
      <Box component="form" onSubmit={handleSubmit} noValidate>
        <DialogContent>
          <Stack spacing={2} mt={1}>
            <TextField
              label="Sección"
              value={formState.seatSection}
              onChange={(event) => setFormState((prev) => ({ ...prev, seatSection: event.target.value }))}
              disabled={isLoading}
            />
            <TextField
              label="Fila"
              value={formState.seatRow}
              onChange={(event) => setFormState((prev) => ({ ...prev, seatRow: event.target.value }))}
              disabled={isLoading}
            />
            <TextField
              label="Asiento"
              value={formState.seatCode}
              onChange={(event) => setFormState((prev) => ({ ...prev, seatCode: event.target.value }))}
              disabled={isLoading}
            />
            {formErrors.seat && (
              <Typography variant="caption" color="error">
                {formErrors.seat}
              </Typography>
            )}
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
            Guardar
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
};

export default EditSeatDialog;
