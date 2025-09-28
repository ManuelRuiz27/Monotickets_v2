import { useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  MenuItem,
  Stack,
  Switch,
  TextField,
} from '@mui/material';
import type { GuestListResource } from '../../hooks/useGuestListsApi';
import type { GuestPayload, GuestResource, RsvpStatus } from '../../hooks/useGuestsApi';

export type GuestFormMode = 'create' | 'edit';

interface GuestFormDialogProps {
  open: boolean;
  mode: GuestFormMode;
  guestLists: GuestListResource[];
  initialGuest: GuestResource | null;
  isSubmitting: boolean;
  error: string | null;
  onClose: () => void;
  onSubmit: (payload: GuestPayload) => Promise<void>;
}

interface FormState {
  fullName: string;
  email: string;
  phone: string;
  guestListId: string;
  rsvpStatus: RsvpStatus;
  allowPlusOnes: boolean;
  plusOnesLimit: string;
  customFields: string;
}

type FormErrors = Partial<Record<keyof FormState, string>>;

const DEFAULT_FORM_STATE: FormState = {
  fullName: '',
  email: '',
  phone: '',
  guestListId: '',
  rsvpStatus: 'none',
  allowPlusOnes: false,
  plusOnesLimit: '0',
  customFields: '',
};

const RSVP_OPTIONS: { value: RsvpStatus; label: string }[] = [
  { value: 'none', label: 'Sin respuesta' },
  { value: 'invited', label: 'Invitado' },
  { value: 'confirmed', label: 'Confirmado' },
  { value: 'declined', label: 'Rechazado' },
];

const GuestForm = ({
  open,
  mode,
  guestLists,
  initialGuest,
  isSubmitting,
  error,
  onClose,
  onSubmit,
}: GuestFormDialogProps) => {
  const [formState, setFormState] = useState<FormState>(DEFAULT_FORM_STATE);
  const [errors, setErrors] = useState<FormErrors>({});
  const [customFieldsError, setCustomFieldsError] = useState<string | null>(null);

  const title = mode === 'edit' ? 'Editar invitado' : 'Nuevo invitado';
  const submitLabel = mode === 'edit' ? 'Guardar cambios' : 'Crear invitado';

  useEffect(() => {
    if (!open) {
      setFormState(DEFAULT_FORM_STATE);
      setErrors({});
      setCustomFieldsError(null);
      return;
    }

    if (initialGuest) {
      setFormState({
        fullName: initialGuest.full_name ?? '',
        email: initialGuest.email ?? '',
        phone: initialGuest.phone ?? '',
        guestListId: initialGuest.guest_list_id ?? '',
        rsvpStatus: initialGuest.rsvp_status ?? 'none',
        allowPlusOnes: Boolean(initialGuest.allow_plus_ones ?? (initialGuest.plus_ones_limit ?? 0) > 0),
        plusOnesLimit: String(initialGuest.plus_ones_limit ?? 0),
        customFields: initialGuest.custom_fields_json
          ? JSON.stringify(initialGuest.custom_fields_json, null, 2)
          : '',
      });
      setErrors({});
      setCustomFieldsError(null);
      return;
    }

    setFormState(DEFAULT_FORM_STATE);
    setErrors({});
    setCustomFieldsError(null);
  }, [initialGuest, open]);

  const guestListOptions = useMemo(() => {
    return guestLists.map((list) => ({ value: list.id, label: list.name }));
  }, [guestLists]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setErrors({});
    setCustomFieldsError(null);

    const validationErrors: FormErrors = {};

    if (!formState.fullName.trim()) {
      validationErrors.fullName = 'El nombre es obligatorio.';
    }

    const parsedLimit = Number.parseInt(formState.plusOnesLimit, 10);
    if (formState.allowPlusOnes) {
      if (Number.isNaN(parsedLimit) || parsedLimit < 0) {
        validationErrors.plusOnesLimit = 'Ingresa un número mayor o igual a 0.';
      }
    }

    let parsedCustomFields: Record<string, unknown> | null = null;
    if (formState.customFields.trim() !== '') {
      try {
        parsedCustomFields = JSON.parse(formState.customFields) as Record<string, unknown>;
        if (typeof parsedCustomFields !== 'object' || parsedCustomFields === null || Array.isArray(parsedCustomFields)) {
          setCustomFieldsError('Debes ingresar un objeto JSON válido.');
          return;
        }
      } catch {
        setCustomFieldsError('Debes ingresar un objeto JSON válido.');
        return;
      }
    }

    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors);
      return;
    }

    const payload: GuestPayload = {
      full_name: formState.fullName.trim(),
      email: formState.email.trim() !== '' ? formState.email.trim() : null,
      phone: formState.phone.trim() !== '' ? formState.phone.trim() : null,
      rsvp_status: formState.rsvpStatus,
      allow_plus_ones: formState.allowPlusOnes,
      plus_ones_limit: formState.allowPlusOnes ? parsedLimit : 0,
      custom_fields_json: parsedCustomFields,
      guest_list_id: formState.guestListId.trim() !== '' ? formState.guestListId.trim() : null,
    };

    try {
      await onSubmit(payload);
    } catch {
      // Los errores se manejan desde el componente padre
    }
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>{title}</DialogTitle>
      <Box component="form" onSubmit={handleSubmit} noValidate>
        <DialogContent dividers>
          <Stack spacing={2}>
            {error && <Alert severity="error">{error}</Alert>}
            <TextField
              label="Nombre completo"
              value={formState.fullName}
              onChange={(event) => setFormState((prev) => ({ ...prev, fullName: event.target.value }))}
              required
              fullWidth
              disabled={isSubmitting}
              error={Boolean(errors.fullName)}
              helperText={errors.fullName}
            />
            <TextField
              label="Correo electrónico"
              type="email"
              value={formState.email}
              onChange={(event) => setFormState((prev) => ({ ...prev, email: event.target.value }))}
              fullWidth
              disabled={isSubmitting}
            />
            <TextField
              label="Teléfono"
              value={formState.phone}
              onChange={(event) => setFormState((prev) => ({ ...prev, phone: event.target.value }))}
              fullWidth
              disabled={isSubmitting}
            />
            <TextField
              select
              label="Lista"
              value={formState.guestListId}
              onChange={(event) => setFormState((prev) => ({ ...prev, guestListId: event.target.value }))}
              fullWidth
              disabled={isSubmitting}
              helperText="Selecciona la lista a la que pertenece el invitado (opcional)."
            >
              <MenuItem value="">Sin lista</MenuItem>
              {guestListOptions.map((option) => (
                <MenuItem key={option.value} value={option.value}>
                  {option.label}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label="RSVP"
              value={formState.rsvpStatus}
              onChange={(event) =>
                setFormState((prev) => ({ ...prev, rsvpStatus: event.target.value as RsvpStatus }))
              }
              fullWidth
              disabled={isSubmitting}
            >
              {RSVP_OPTIONS.map((option) => (
                <MenuItem key={option.value} value={option.value}>
                  {option.label}
                </MenuItem>
              ))}
            </TextField>
            <FormControlLabel
              control={
                <Switch
                  checked={formState.allowPlusOnes}
                  onChange={(event) =>
                    setFormState((prev) => ({
                      ...prev,
                      allowPlusOnes: event.target.checked,
                      plusOnesLimit: event.target.checked ? prev.plusOnesLimit || '1' : '0',
                    }))
                  }
                  disabled={isSubmitting}
                />
              }
              label="Permitir invitados adicionales"
            />
            {formState.allowPlusOnes && (
              <TextField
                label="Límite de invitados adicionales"
                type="number"
                inputProps={{ min: 0 }}
                value={formState.plusOnesLimit}
                onChange={(event) =>
                  setFormState((prev) => ({ ...prev, plusOnesLimit: event.target.value }))
                }
                fullWidth
                disabled={isSubmitting}
                error={Boolean(errors.plusOnesLimit)}
                helperText={errors.plusOnesLimit ?? 'Número máximo de acompañantes permitidos.'}
              />
            )}
            <TextField
              label="Campos personalizados (JSON)"
              value={formState.customFields}
              onChange={(event) => setFormState((prev) => ({ ...prev, customFields: event.target.value }))}
              fullWidth
              disabled={isSubmitting}
              multiline
              minRows={4}
              placeholder={`{
  "codigo": "VIP"
}`}
              error={Boolean(customFieldsError)}
              helperText={customFieldsError ?? 'Puedes definir atributos adicionales en formato JSON.'}
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={isSubmitting} color="inherit">
            Cancelar
          </Button>
          <Button type="submit" variant="contained" disabled={isSubmitting}>
            {submitLabel}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
};

export default GuestForm;
