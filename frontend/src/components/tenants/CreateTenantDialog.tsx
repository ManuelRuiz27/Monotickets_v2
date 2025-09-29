import { FormEvent, type ChangeEvent, useMemo, useState } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Stack,
  TextField,
  MenuItem,
  FormControl,
  InputLabel,
  Select,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import { type AdminPlan } from '../../hooks/useAdminPlans';
import type { CreateTenantPayload } from '../../hooks/useAdminTenants';

interface CreateTenantDialogProps {
  open: boolean;
  onClose: () => void;
  plans: AdminPlan[];
  onSubmit: (payload: CreateTenantPayload) => Promise<void> | void;
  isSubmitting?: boolean;
}

const defaultForm = {
  name: '',
  slug: '',
  planId: '',
  status: 'active',
  trialDays: '',
  maxEvents: '',
  maxUsers: '',
  maxScansPerEvent: '',
};

type FormState = typeof defaultForm;

type FormErrors = Partial<Record<keyof FormState, string>>;

const CreateTenantDialog = ({ open, onClose, plans, onSubmit, isSubmitting = false }: CreateTenantDialogProps) => {
  const [formState, setFormState] = useState<FormState>(defaultForm);
  const [errors, setErrors] = useState<FormErrors>({});

  const planOptions = useMemo(() => plans, [plans]);

  const resetForm = () => {
    setFormState(defaultForm);
    setErrors({});
  };

  const handleClose = () => {
    if (isSubmitting) {
      return;
    }
    resetForm();
    onClose();
  };

  const handleChange = (key: keyof FormState) => (event: ChangeEvent<HTMLInputElement>) => {
    setFormState((prev) => ({ ...prev, [key]: event.target.value }));
  };

  const handleStatusChange = (event: SelectChangeEvent<string>) => {
    setFormState((prev) => ({ ...prev, status: event.target.value }));
  };

  const validate = (): boolean => {
    const nextErrors: FormErrors = {};

    if (!formState.name.trim()) {
      nextErrors.name = 'El nombre es obligatorio.';
    }

    if (!formState.slug.trim()) {
      nextErrors.slug = 'El slug es obligatorio.';
    }

    if (!formState.planId) {
      nextErrors.planId = 'Selecciona un plan.';
    }

    if (formState.trialDays && Number.isNaN(Number(formState.trialDays))) {
      nextErrors.trialDays = 'Debe ser un número válido.';
    }

    (['maxEvents', 'maxUsers', 'maxScansPerEvent'] as const).forEach((key) => {
      const value = formState[key];
      if (value && Number.isNaN(Number(value))) {
        nextErrors[key] = 'Debe ser un número válido.';
      }
    });

    setErrors(nextErrors);
    return Object.keys(nextErrors).length === 0;
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!validate()) {
      return;
    }

    const overrides: Record<string, number | null> = {};
    if (formState.maxEvents) {
      overrides.max_events = Number(formState.maxEvents);
    }
    if (formState.maxUsers) {
      overrides.max_users = Number(formState.maxUsers);
    }
    if (formState.maxScansPerEvent) {
      overrides.max_scans_per_event = Number(formState.maxScansPerEvent);
    }

    const payload: CreateTenantPayload = {
      name: formState.name.trim(),
      slug: formState.slug.trim(),
      plan_id: formState.planId,
      status: formState.status,
    };

    if (formState.trialDays) {
      payload.trial_days = Number(formState.trialDays);
    }

    if (Object.keys(overrides).length > 0) {
      payload.limit_overrides = overrides;
    }

    await onSubmit(payload);
    resetForm();
  };

  return (
    <Dialog open={open} onClose={handleClose} fullWidth maxWidth="sm" component="form" onSubmit={handleSubmit}>
      <DialogTitle>Crear tenant</DialogTitle>
      <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 2 }}>
        <TextField
          label="Nombre"
          value={formState.name}
          onChange={handleChange('name')}
          error={Boolean(errors.name)}
          helperText={errors.name ?? 'Nombre visible del tenant.'}
          fullWidth
          required
        />
        <TextField
          label="Slug"
          value={formState.slug}
          onChange={handleChange('slug')}
          error={Boolean(errors.slug)}
          helperText={errors.slug ?? 'Identificador único usado para URLs.'}
          fullWidth
          required
        />
        <FormControl fullWidth error={Boolean(errors.planId)} disabled={planOptions.length === 0}>
          <InputLabel id="plan-select-label">Plan</InputLabel>
          <Select
            labelId="plan-select-label"
            label="Plan"
            value={formState.planId}
            onChange={(event) => setFormState((prev) => ({ ...prev, planId: event.target.value }))}
            required
          >
            {planOptions.length === 0 ? (
              <MenuItem value="" disabled>
                No hay planes disponibles
              </MenuItem>
            ) : (
              planOptions.map((plan) => (
                <MenuItem key={plan.id} value={plan.id}>
                  {plan.name} · {plan.billing_cycle === 'yearly' ? 'Anual' : 'Mensual'}
                </MenuItem>
              ))
            )}
          </Select>
          {errors.planId && (
            <Typography variant="caption" color="error" sx={{ mt: 0.5 }}>
              {errors.planId}
            </Typography>
          )}
          {planOptions.length === 0 && (
            <Typography variant="caption" color="text.secondary" sx={{ mt: 0.5 }}>
              Crea planes activos para asignarlos a nuevos tenants.
            </Typography>
          )}
        </FormControl>
        <FormControl fullWidth>
          <InputLabel id="status-select-label">Estado</InputLabel>
          <Select labelId="status-select-label" label="Estado" value={formState.status} onChange={handleStatusChange}>
            <MenuItem value="active">Activo</MenuItem>
            <MenuItem value="inactive">Inactivo</MenuItem>
          </Select>
        </FormControl>
        <TextField
          label="Días de prueba"
          value={formState.trialDays}
          onChange={handleChange('trialDays')}
          helperText={errors.trialDays ?? 'Opcional. Periodo de prueba inicial para la suscripción.'}
          error={Boolean(errors.trialDays)}
          type="number"
          inputProps={{ min: 0 }}
        />
        <Stack spacing={1.5}>
          <Typography variant="subtitle2">Límites opcionales</Typography>
          <TextField
            label="Máx. eventos"
            value={formState.maxEvents}
            onChange={handleChange('maxEvents')}
            error={Boolean(errors.maxEvents)}
            helperText={errors.maxEvents ?? 'Sobrescribe la cantidad máxima de eventos activos.'}
            type="number"
            inputProps={{ min: 0 }}
          />
          <TextField
            label="Máx. usuarios"
            value={formState.maxUsers}
            onChange={handleChange('maxUsers')}
            error={Boolean(errors.maxUsers)}
            helperText={errors.maxUsers ?? 'Sobrescribe la cantidad máxima de usuarios activos.'}
            type="number"
            inputProps={{ min: 0 }}
          />
          <TextField
            label="Máx. escaneos por evento"
            value={formState.maxScansPerEvent}
            onChange={handleChange('maxScansPerEvent')}
            error={Boolean(errors.maxScansPerEvent)}
            helperText={errors.maxScansPerEvent ?? 'Sobrescribe el tope de escaneos para cada evento.'}
            type="number"
            inputProps={{ min: 0 }}
          />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={handleClose} disabled={isSubmitting}>
          Cancelar
        </Button>
        <Button type="submit" variant="contained" disabled={isSubmitting || planOptions.length === 0}>
          Crear
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default CreateTenantDialog;
