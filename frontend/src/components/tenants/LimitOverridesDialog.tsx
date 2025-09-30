import { FormEvent, type ChangeEvent, useEffect, useMemo, useState } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Stack,
  Switch,
  TextField,
  Typography,
} from '@mui/material';
import type { AdminTenantSummary, UpdateTenantPayload } from '../../hooks/useAdminTenants';

interface LimitOverridesDialogProps {
  open: boolean;
  onClose: () => void;
  tenant: AdminTenantSummary | null;
  onSubmit: (payload: UpdateTenantPayload) => Promise<void> | void;
  isSubmitting?: boolean;
}

interface LimitFieldState {
  value: string;
  unlimited: boolean;
}

interface LimitFormState {
  maxEvents: LimitFieldState;
  maxUsers: LimitFieldState;
  maxScansPerEvent: LimitFieldState;
}

const defaultField = (): LimitFieldState => ({ value: '', unlimited: false });

const createInitialState = (): LimitFormState => ({
  maxEvents: defaultField(),
  maxUsers: defaultField(),
  maxScansPerEvent: defaultField(),
});

const LimitOverridesDialog = ({ open, onClose, tenant, onSubmit, isSubmitting = false }: LimitOverridesDialogProps) => {
  const [formState, setFormState] = useState<LimitFormState>(createInitialState);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!tenant) {
      setFormState(createInitialState());
      setError(null);
      return;
    }

    setFormState({
      maxEvents: tenant.limits_override?.max_events !== undefined
        ? tenant.limits_override.max_events === null
          ? { value: '', unlimited: true }
          : { value: String(tenant.limits_override.max_events), unlimited: false }
        : defaultField(),
      maxUsers: tenant.limits_override?.max_users !== undefined
        ? tenant.limits_override.max_users === null
          ? { value: '', unlimited: true }
          : { value: String(tenant.limits_override.max_users), unlimited: false }
        : defaultField(),
      maxScansPerEvent: tenant.limits_override?.max_scans_per_event !== undefined
        ? tenant.limits_override.max_scans_per_event === null
          ? { value: '', unlimited: true }
          : { value: String(tenant.limits_override.max_scans_per_event), unlimited: false }
        : defaultField(),
    });
    setError(null);
  }, [tenant]);

  const planLimits = useMemo(() => (tenant?.plan?.limits as Record<string, unknown>) ?? {}, [tenant]);
  const effectiveLimits = useMemo(() => tenant?.effective_limits ?? {}, [tenant]);

  const handleClose = () => {
    if (isSubmitting) {
      return;
    }
    onClose();
  };

  const handleValueChange = (field: keyof LimitFormState) => (event: ChangeEvent<HTMLInputElement>) => {
    const nextValue = event.target.value;
    setFormState((prev) => ({
      ...prev,
      [field]: {
        ...prev[field],
        value: nextValue,
      },
    }));
    setError(null);
  };

  const handleUnlimitedToggle = (field: keyof LimitFormState) => (_event: unknown, checked: boolean) => {
    setFormState((prev) => ({
      ...prev,
      [field]: {
        ...prev[field],
        unlimited: checked,
      },
    }));
    setError(null);
  };

  const handleReset = () => {
    setFormState(createInitialState());
    setError(null);
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!tenant) {
      return;
    }

    const overrides: Record<string, number | null> = {};
    let validationError: string | null = null;

    (['maxEvents', 'maxUsers', 'maxScansPerEvent'] as const).forEach((fieldKey) => {
      const apiKey =
        fieldKey === 'maxEvents'
          ? 'max_events'
          : fieldKey === 'maxUsers'
          ? 'max_users'
          : 'max_scans_per_event';
      const field = formState[fieldKey];

      if (field.unlimited) {
        overrides[apiKey] = null;
        return;
      }

      if (field.value.trim() === '') {
        return;
      }

      const numericValue = Number(field.value);
      if (Number.isNaN(numericValue) || numericValue < 0) {
        validationError = 'Los límites deben ser números enteros mayores o iguales a cero.';
        return;
      }
      overrides[apiKey] = numericValue;
    });

    if (validationError) {
      setError(validationError);
      return;
    }

    const payload: UpdateTenantPayload = {};

    if (Object.keys(overrides).length === 0) {
      payload.limit_overrides = null;
    } else {
      payload.limit_overrides = overrides;
    }

    await onSubmit(payload);
  };

  const renderHelper = (label: string, key: keyof LimitFormState, planKey: string) => {
    const planLimitValue = planLimits[planKey];
    const effectiveValue = effectiveLimits[planKey];
    const field = formState[key];

    return (
      <Stack spacing={1}>
        <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between">
          <Typography variant="subtitle2">{label}</Typography>
          <FormControlLabel
            control={<Switch checked={field.unlimited} onChange={handleUnlimitedToggle(key)} size="small" />}
            label="Sin límite"
          />
        </Stack>
        <TextField
          label="Override"
          value={field.value}
          onChange={handleValueChange(key)}
          disabled={field.unlimited}
          type="number"
          inputProps={{ min: 0 }}
          helperText={`Plan: ${planLimitValue ?? '—'} · Vigente: ${effectiveValue ?? '—'}`}
        />
      </Stack>
    );
  };

  return (
    <Dialog open={open} onClose={handleClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={handleSubmit} sx={{ display: 'contents' }}>
        <DialogTitle>Modificar límites del tenant</DialogTitle>
        <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 2 }}>
        {tenant ? (
          <Stack spacing={2}>
            <Typography variant="subtitle2">{tenant.name ?? tenant.slug ?? tenant.id}</Typography>
            {renderHelper('Eventos activos', 'maxEvents', 'max_events')}
            {renderHelper('Usuarios activos', 'maxUsers', 'max_users')}
            {renderHelper('Escaneos por evento', 'maxScansPerEvent', 'max_scans_per_event')}
            <Button variant="text" onClick={handleReset} disabled={isSubmitting} sx={{ alignSelf: 'flex-start' }}>
              Restablecer a límites del plan
            </Button>
            {error && (
              <Typography variant="body2" color="error">
                {error}
              </Typography>
            )}
          </Stack>
        ) : (
          <Typography variant="body2" color="text.secondary">
            Selecciona un tenant para modificar sus límites.
          </Typography>
        )}
        </DialogContent>
        <DialogActions>
          <Button onClick={handleClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" variant="contained" disabled={isSubmitting || !tenant}>
            Guardar
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
};

export default LimitOverridesDialog;
