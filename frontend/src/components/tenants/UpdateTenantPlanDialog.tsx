import { FormEvent, type ChangeEvent, useEffect, useMemo, useState } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormControlLabel,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Switch,
  TextField,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import { DateTime } from 'luxon';
import type { AdminPlan } from '../../hooks/useAdminPlans';
import type { AdminTenantSummary, UpdateTenantPayload } from '../../hooks/useAdminTenants';

interface UpdateTenantPlanDialogProps {
  open: boolean;
  onClose: () => void;
  tenant: AdminTenantSummary | null;
  plans: AdminPlan[];
  onSubmit: (payload: UpdateTenantPayload) => Promise<void> | void;
  isSubmitting?: boolean;
}

interface FormState {
  planId: string;
  tenantStatus: string;
  subscriptionStatus: string;
  cancelAtPeriodEnd: boolean;
  trialEnd: string;
}

const STATUS_OPTIONS = [
  { value: 'trialing', label: 'En prueba' },
  { value: 'active', label: 'Activa' },
  { value: 'paused', label: 'Pausada' },
  { value: 'canceled', label: 'Cancelada' },
];

const UpdateTenantPlanDialog = ({ open, onClose, tenant, plans, onSubmit, isSubmitting = false }: UpdateTenantPlanDialogProps) => {
  const [formState, setFormState] = useState<FormState>({
    planId: '',
    tenantStatus: 'active',
    subscriptionStatus: 'active',
    cancelAtPeriodEnd: false,
    trialEnd: '',
  });
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!tenant) {
      return;
    }

    const trialEnd = tenant.subscription?.trial_end
      ? DateTime.fromISO(tenant.subscription.trial_end).toISODate() ?? ''
      : '';

    setFormState({
      planId: tenant.plan?.id ?? '',
      tenantStatus: tenant.status ?? 'active',
      subscriptionStatus: tenant.subscription?.status ?? (tenant.plan ? 'active' : ''),
      cancelAtPeriodEnd: tenant.subscription?.cancel_at_period_end ?? false,
      trialEnd,
    });
    setError(null);
  }, [tenant]);

  const handleClose = () => {
    if (isSubmitting) {
      return;
    }
    onClose();
  };

  const handlePlanChange = (event: SelectChangeEvent<string>) => {
    setFormState((prev) => ({ ...prev, planId: event.target.value }));
    setError(null);
  };

  const handleTenantStatusChange = (event: SelectChangeEvent<string>) => {
    setFormState((prev) => ({ ...prev, tenantStatus: event.target.value }));
    setError(null);
  };

  const handleSubscriptionStatusChange = (event: SelectChangeEvent<string>) => {
    setFormState((prev) => ({ ...prev, subscriptionStatus: event.target.value }));
    setError(null);
  };

  const handleTrialEndChange = (event: ChangeEvent<HTMLInputElement>) => {
    setFormState((prev) => ({ ...prev, trialEnd: event.target.value }));
    setError(null);
  };

  const handleCancelAtPeriodEndChange = (_event: unknown, checked: boolean) => {
    setFormState((prev) => ({ ...prev, cancelAtPeriodEnd: checked }));
    setError(null);
  };

  const planOptions = useMemo(() => plans, [plans]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!tenant) {
      return;
    }

    const payload: UpdateTenantPayload = {};

    if (formState.planId && formState.planId !== tenant.plan?.id) {
      payload.plan_id = formState.planId;
    }

    if (formState.tenantStatus !== tenant.status) {
      payload.status = formState.tenantStatus;
    }

    if (tenant.subscription) {
      if (formState.subscriptionStatus && formState.subscriptionStatus !== tenant.subscription.status) {
        payload.subscription_status = formState.subscriptionStatus;
      }

      if (formState.cancelAtPeriodEnd !== tenant.subscription.cancel_at_period_end) {
        payload.cancel_at_period_end = formState.cancelAtPeriodEnd;
      }

      const originalTrialEnd = tenant.subscription.trial_end
        ? DateTime.fromISO(tenant.subscription.trial_end).toISODate() ?? ''
        : '';

      if (formState.trialEnd !== originalTrialEnd) {
        payload.trial_end = formState.trialEnd ? formState.trialEnd : null;
      }
    } else {
      if (formState.subscriptionStatus) {
        payload.subscription_status = formState.subscriptionStatus;
      }
      payload.cancel_at_period_end = formState.cancelAtPeriodEnd;
      payload.trial_end = formState.trialEnd ? formState.trialEnd : null;
    }

    if (Object.keys(payload).length === 0) {
      setError('Realiza un cambio antes de guardar.');
      return;
    }

    await onSubmit(payload);
  };

  return (
    <Dialog open={open} onClose={handleClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={handleSubmit} sx={{ display: 'contents' }}>
        <DialogTitle>Actualizar plan y suscripción</DialogTitle>
        <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 2 }}>
        {tenant ? (
          <Stack spacing={2}>
            <Typography variant="subtitle2">{tenant.name ?? tenant.slug ?? tenant.id}</Typography>
            <FormControl fullWidth>
              <InputLabel id="plan-edit-select-label">Plan</InputLabel>
              <Select
                labelId="plan-edit-select-label"
                label="Plan"
                value={formState.planId}
                onChange={handlePlanChange}
                displayEmpty
              >
                <MenuItem value="">
                  {planOptions.length === 0 ? 'Sin planes disponibles' : 'Sin cambios'}
                </MenuItem>
                {planOptions.map((plan) => (
                  <MenuItem key={plan.id} value={plan.id}>
                    {plan.name} · {plan.billing_cycle === 'yearly' ? 'Anual' : 'Mensual'}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControl fullWidth>
              <InputLabel id="tenant-status-select-label">Estado del tenant</InputLabel>
              <Select
                labelId="tenant-status-select-label"
                label="Estado del tenant"
                value={formState.tenantStatus}
                onChange={handleTenantStatusChange}
              >
                <MenuItem value="active">Activo</MenuItem>
                <MenuItem value="inactive">Inactivo</MenuItem>
              </Select>
            </FormControl>
            <FormControl fullWidth>
              <InputLabel id="subscription-status-select-label">Estado de suscripción</InputLabel>
              <Select
                labelId="subscription-status-select-label"
                label="Estado de suscripción"
                value={formState.subscriptionStatus}
                onChange={handleSubscriptionStatusChange}
                displayEmpty
              >
                <MenuItem value="">
                  {tenant.subscription ? 'Sin cambios' : 'Selecciona un estado'}
                </MenuItem>
                {STATUS_OPTIONS.map((option) => (
                  <MenuItem key={option.value} value={option.value}>
                    {option.label}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControlLabel
              control={
                <Switch
                  checked={formState.cancelAtPeriodEnd}
                  onChange={handleCancelAtPeriodEndChange}
                  color="warning"
                />
              }
              label="Cancelar al finalizar el periodo"
            />
            <TextField
              label="Fin de prueba"
              type="date"
              value={formState.trialEnd}
              onChange={handleTrialEndChange}
              InputLabelProps={{ shrink: true }}
              helperText="Opcional. Define una fecha de fin de prueba en formato ISO."
            />
            {error && (
              <Typography variant="body2" color="error">
                {error}
              </Typography>
            )}
          </Stack>
        ) : (
          <Typography variant="body2" color="text.secondary">
            Selecciona un tenant para actualizar su plan.
          </Typography>
        )}
        </DialogContent>
        <DialogActions>
          <Button onClick={handleClose} disabled={isSubmitting}>
            Cancelar
          </Button>
          <Button type="submit" variant="contained" disabled={isSubmitting || !tenant}>
            Guardar cambios
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
};

export default UpdateTenantPlanDialog;
