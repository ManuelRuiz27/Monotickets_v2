import { useEffect, useMemo, useState, type ChangeEvent, type FormEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  Container,
  Grid,
  MenuItem,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import SaveIcon from '@mui/icons-material/Save';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { DateTime } from 'luxon';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../auth/store';
import {
  CHECKIN_POLICY_LABELS,
  EVENT_STATUS_LABELS,
  type CheckinPolicy,
  type CreateEventPayload,
  type EventResource,
  type EventStatus,
  type UpdateEventPayload,
  useCreateEvent,
  useEvent,
  useUpdateEvent,
} from '../../hooks/useEventsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';

const DEFAULT_TIMEZONE = 'America/Mexico_City';
const DATETIME_LOCAL_FORMAT = "yyyy-LL-dd'T'HH:mm";

const TIMEZONE_OPTIONS = [
  'America/Mexico_City',
  'America/Monterrey',
  'America/Chicago',
  'America/Bogota',
  'America/Lima',
  'America/Santiago',
  'America/Argentina/Buenos_Aires',
  'UTC',
];

type FormErrors = Partial<Record<keyof FormState, string>>;

interface FormState {
  tenantId: string;
  organizerUserId: string;
  code: string;
  name: string;
  description: string;
  startAt: string;
  endAt: string;
  timezone: string;
  status: EventStatus;
  capacity: string;
  checkinPolicy: CheckinPolicy;
  settingsJson: string;
}

const buildDefaultFormState = (): FormState => {
  const startAt = DateTime.now().setZone(DEFAULT_TIMEZONE).plus({ days: 1 }).startOf('hour');
  const endAt = startAt.plus({ hours: 4 });

  return {
    tenantId: '',
    organizerUserId: '',
    code: '',
    name: '',
    description: '',
    startAt: startAt.toFormat(DATETIME_LOCAL_FORMAT),
    endAt: endAt.toFormat(DATETIME_LOCAL_FORMAT),
    timezone: DEFAULT_TIMEZONE,
    status: 'draft',
    capacity: '',
    checkinPolicy: 'single',
    settingsJson: '',
  };
};

const toDateInputValue = (iso: string | null | undefined, timezone: string): string => {
  if (!iso) return '';
  try {
    return DateTime.fromISO(iso, { setZone: true })
      .setZone(timezone)
      .toFormat(DATETIME_LOCAL_FORMAT);
  } catch {
    return '';
  }
};

const toIsoString = (value: string, timezone: string): string => {
  return DateTime.fromFormat(value, DATETIME_LOCAL_FORMAT, { zone: timezone })
    .toISO({ suppressMilliseconds: true }) ?? new Date().toISOString();
};

const convertDateToTimezone = (value: string, fromTimezone: string, toTimezone: string): string | null => {
  if (!value) return null;

  try {
    const parsed = DateTime.fromFormat(value, DATETIME_LOCAL_FORMAT, { zone: fromTimezone || toTimezone });
    if (!parsed.isValid) {
      return null;
    }

    return parsed.setZone(toTimezone).toFormat(DATETIME_LOCAL_FORMAT);
  } catch {
    return null;
  }
};

interface EventFormProps {
  eventId?: string;
}

const EventForm = ({ eventId }: EventFormProps) => {
  const navigate = useNavigate();
  const { user, tenantId: tenantFromStore } = useAuthStore();
  const isEditing = Boolean(eventId);
  const [formState, setFormState] = useState<FormState>(buildDefaultFormState);
  const [errors, setErrors] = useState<FormErrors>({});
  const [globalError, setGlobalError] = useState<string | null>(null);
  const [timezoneNotice, setTimezoneNotice] = useState<string | null>(null);

  const { data: eventResponse, isLoading: isLoadingEvent, error: eventError } = useEvent(eventId, {
    enabled: isEditing,
  });

  const createMutation = useCreateEvent({
    onSuccess: () => {
      navigate('/events');
    },
    onError: (error: unknown) => {
      setGlobalError(extractApiErrorMessage(error, 'No se pudo crear el evento.'));
    },
  });

  const updateMutation = useUpdateEvent(eventId ?? '', {
    onSuccess: () => {
      navigate('/events');
    },
    onError: (error: unknown) => {
      setGlobalError(extractApiErrorMessage(error, 'No se pudo actualizar el evento.'));
    },
  });

  useEffect(() => {
    if (!isEditing || !eventResponse?.data) {
      return;
    }
    const event = eventResponse.data as EventResource;
    const timezone = event.timezone || DEFAULT_TIMEZONE;
    const defaults = buildDefaultFormState();
    setFormState({
      tenantId: event.tenant_id ?? '',
      organizerUserId: event.organizer_user_id ?? '',
      code: event.code ?? '',
      name: event.name ?? '',
      description: event.description ?? '',
      startAt: toDateInputValue(event.start_at, timezone) || defaults.startAt,
      endAt: toDateInputValue(event.end_at, timezone) || defaults.endAt,
      timezone,
      status: event.status,
      capacity: event.capacity ? String(event.capacity) : '',
      checkinPolicy: event.checkin_policy,
      settingsJson: event.settings_json ? JSON.stringify(event.settings_json, null, 2) : '',
    });
    setTimezoneNotice(null);
  }, [eventResponse?.data, isEditing]);

  useEffect(() => {
    if (!isEditing && tenantFromStore) {
      setFormState((prev) => ({ ...prev, tenantId: prev.tenantId || tenantFromStore }));
    }
  }, [isEditing, tenantFromStore]);

  const statusOptions = useMemo(() => Object.entries(EVENT_STATUS_LABELS) as [EventStatus, string][], []);
  const checkinOptions = useMemo(() => Object.entries(CHECKIN_POLICY_LABELS), []);
  const totalDurationHours = useMemo(() => {
    if (!formState.startAt || !formState.endAt || !formState.timezone) {
      return null;
    }

    try {
      const start = DateTime.fromFormat(formState.startAt, DATETIME_LOCAL_FORMAT, { zone: formState.timezone });
      const end = DateTime.fromFormat(formState.endAt, DATETIME_LOCAL_FORMAT, { zone: formState.timezone });
      if (!start.isValid || !end.isValid || end <= start) {
        return null;
      }
      const diff = end.diff(start, 'hours').hours;
      if (Number.isNaN(diff)) {
        return null;
      }
      return diff;
    } catch {
      return null;
    }
  }, [formState.endAt, formState.startAt, formState.timezone]);
  const formattedDuration = useMemo(() => {
    if (totalDurationHours === null) {
      return null;
    }

    return new Intl.NumberFormat('es-MX', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(totalDurationHours);
  }, [totalDurationHours]);

const handleChange = (key: keyof FormState) => (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
  setFormState((prev) => ({ ...prev, [key]: event.target.value }));
  setErrors((prev) => ({ ...prev, [key]: undefined }));
  if (key === 'startAt' || key === 'endAt') {
    setTimezoneNotice(null);
  }
};

const handleSelectChange = (key: keyof FormState) => (event: SelectChangeEvent<string>) => {
  setFormState((prev) => ({ ...prev, [key]: event.target.value }));
  setErrors((prev) => ({ ...prev, [key]: undefined }));
};

  const handleTimezoneChange = (event: SelectChangeEvent<string>) => {
    const newTimezone = event.target.value;
    const previousTimezone = formState.timezone || newTimezone;

    const convertedStart = convertDateToTimezone(formState.startAt, previousTimezone, newTimezone);
    const convertedEnd = convertDateToTimezone(formState.endAt, previousTimezone, newTimezone);

    const startDateTime = convertedStart
      ? DateTime.fromFormat(convertedStart, DATETIME_LOCAL_FORMAT, { zone: newTimezone })
      : null;
    const endDateTime = convertedEnd
      ? DateTime.fromFormat(convertedEnd, DATETIME_LOCAL_FORMAT, { zone: newTimezone })
      : null;

    setFormState((prev) => ({
      ...prev,
      timezone: newTimezone,
      startAt: convertedStart ?? prev.startAt,
      endAt: convertedEnd ?? prev.endAt,
    }));

    setErrors((prev) => {
      const next = { ...prev, timezone: undefined };

      if (convertedStart === null && formState.startAt) {
        next.startAt = 'No se pudo ajustar el horario de inicio automáticamente. Revisa el valor.';
      } else if (startDateTime) {
        next.startAt = undefined;
      }

      if (convertedEnd === null && formState.endAt) {
        next.endAt = 'No se pudo ajustar el horario de finalización automáticamente. Revisa el valor.';
      } else if (endDateTime) {
        next.endAt = undefined;
      }

      if (startDateTime && endDateTime && endDateTime <= startDateTime) {
        next.endAt = 'La hora de finalización debe ser posterior al inicio.';
      }

      return next;
    });

    let noticeMessage: string;
    if (startDateTime && endDateTime) {
      noticeMessage =
        endDateTime <= startDateTime
          ? 'Los horarios se ajustaron a la nueva zona horaria, pero el fin no es posterior al inicio. Ajusta las fechas.'
          : 'Los horarios se ajustaron a la nueva zona horaria. Verifica que los cambios sean correctos.';
    } else {
      noticeMessage = 'La zona horaria cambió. Verifica manualmente los horarios del evento.';
    }

    setTimezoneNotice(noticeMessage);
  };

  const validate = (): FormErrors => {
    const validationErrors: FormErrors = {};
    if (!formState.organizerUserId.trim()) {
      validationErrors.organizerUserId = 'El organizador es obligatorio.';
    }
    if (!formState.code.trim()) {
      validationErrors.code = 'El código es obligatorio.';
    }
    if (!formState.name.trim()) {
      validationErrors.name = 'El nombre es obligatorio.';
    }
    if (!formState.startAt) {
      validationErrors.startAt = 'La fecha de inicio es obligatoria.';
    }
    if (!formState.endAt) {
      validationErrors.endAt = 'La fecha de finalización es obligatoria.';
    }
    if (!formState.timezone) {
      validationErrors.timezone = 'Selecciona una zona horaria válida.';
    }
    if (!formState.checkinPolicy) {
      validationErrors.checkinPolicy = 'Selecciona una política de check-in.';
    }
    if (!formState.status) {
      validationErrors.status = 'Selecciona un estado.';
    }

    if (formState.startAt && formState.endAt && formState.timezone) {
      const start = DateTime.fromFormat(formState.startAt, DATETIME_LOCAL_FORMAT, {
        zone: formState.timezone,
      });
      const end = DateTime.fromFormat(formState.endAt, DATETIME_LOCAL_FORMAT, {
        zone: formState.timezone,
      });
      if (start >= end) {
        validationErrors.endAt = 'La hora de finalización debe ser posterior al inicio.';
      }
    }

    if (formState.capacity) {
      const numericCapacity = Number.parseInt(formState.capacity, 10);
      if (Number.isNaN(numericCapacity) || numericCapacity < 0) {
        validationErrors.capacity = 'La capacidad no puede ser negativa.';
      }
    }

    if (formState.settingsJson) {
      try {
        JSON.parse(formState.settingsJson);
      } catch (error) {
        validationErrors.settingsJson = 'El JSON de configuración no es válido.';
      }
    }

    if (formState.status === 'published' && !validationErrors.status) {
      const missingOrganizer = !formState.organizerUserId.trim();
      const missingName = !formState.name.trim();
      const missingStart = !formState.startAt;
      const missingEnd = !formState.endAt;

      if (missingOrganizer || missingName || missingStart || missingEnd) {
        validationErrors.status = 'Completa organizador, nombre y horarios antes de publicar.';
      }
    }

    if (!isEditing && user?.role === 'superadmin' && !formState.tenantId.trim()) {
      validationErrors.tenantId = 'El tenant es obligatorio para superadministradores.';
    }

    return validationErrors;
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setGlobalError(null);
    const validationErrors = validate();
    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors);
      return;
    }

    const payload: CreateEventPayload = {
      organizer_user_id: formState.organizerUserId.trim(),
      code: formState.code.trim(),
      name: formState.name.trim(),
      description: formState.description.trim() || null,
      start_at: toIsoString(formState.startAt, formState.timezone),
      end_at: toIsoString(formState.endAt, formState.timezone),
      timezone: formState.timezone,
      status: formState.status,
      capacity: formState.capacity ? Number.parseInt(formState.capacity, 10) : null,
      checkin_policy: formState.checkinPolicy,
      settings_json: formState.settingsJson ? JSON.parse(formState.settingsJson) : null,
    };

    if (!isEditing) {
      payload.tenant_id = formState.tenantId.trim() || tenantFromStore || null;
      try {
        await createMutation.mutateAsync(payload);
      } catch {
        // handled in onError
      }
      return;
    }

    if (isEditing && eventId) {
      const { tenant_id: _omitTenant, ...updatePayload } = payload;
      try {
        await updateMutation.mutateAsync(updatePayload as UpdateEventPayload);
      } catch {
        // handled in onError
      }
    }
  };

  const isSubmitting = createMutation.isPending || updateMutation.isPending;

  return (
    <Container maxWidth="md" sx={{ py: 4 }}>
      <Paper variant="outlined" sx={{ p: { xs: 2, md: 4 } }}>
        <form onSubmit={handleSubmit}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
            <Box display="flex" alignItems="center" justifyContent="space-between">
            <Stack spacing={1}>
              <Typography variant="h4" component="h1">
                {isEditing ? 'Editar evento' : 'Nuevo evento'}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Completa la información general, horarios y políticas de acceso del evento.
              </Typography>
            </Stack>
            <Button variant="text" startIcon={<ArrowBackIcon />} onClick={() => navigate('/events')}>
              Volver
            </Button>
          </Box>
          {globalError && (
            <Alert severity="error" onClose={() => setGlobalError(null)}>
              {globalError}
            </Alert>
          )}
          {eventError ? (
            <Alert severity="error">
              {extractApiErrorMessage(eventError, 'No se pudo cargar la información del evento.')}
            </Alert>
          ) : null}
          {timezoneNotice && (
            <Alert severity="info" onClose={() => setTimezoneNotice(null)}>
              {timezoneNotice}
            </Alert>
          )}
          {isLoadingEvent && isEditing ? (
            <Typography variant="body2" color="text.secondary">
              Cargando datos del evento…
            </Typography>
          ) : (
            <Grid container spacing={2}>
              {user?.role === 'superadmin' && (
                <Grid item xs={12} md={6}>
                  <TextField
                    label="Tenant ID"
                    value={formState.tenantId}
                    onChange={handleChange('tenantId')}
                    error={Boolean(errors.tenantId)}
                    helperText={errors.tenantId ?? 'Define el tenant donde se registrará el evento.'}
                    fullWidth
                  />
                </Grid>
              )}
              <Grid item xs={12} md={6}>
                <TextField
                  label="Organizador (User ID)"
                  value={formState.organizerUserId}
                  onChange={handleChange('organizerUserId')}
                  error={Boolean(errors.organizerUserId)}
                  helperText={errors.organizerUserId ?? 'Identificador del usuario organizador.'}
                  required
                  fullWidth
                />
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  label="Código"
                  value={formState.code}
                  onChange={handleChange('code')}
                  error={Boolean(errors.code)}
                  helperText={errors.code ?? 'Código único por tenant.'}
                  required
                  fullWidth
                />
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  label="Nombre"
                  value={formState.name}
                  onChange={handleChange('name')}
                  error={Boolean(errors.name)}
                  helperText={errors.name ?? 'Nombre público del evento.'}
                  required
                  fullWidth
                />
              </Grid>
              <Grid item xs={12}>
                <TextField
                  label="Descripción"
                  value={formState.description}
                  onChange={handleChange('description')}
                  multiline
                  minRows={3}
                  fullWidth
                />
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  label="Inicio (zona del evento)"
                  type="datetime-local"
                  value={formState.startAt}
                  onChange={handleChange('startAt')}
                  error={Boolean(errors.startAt)}
                  helperText={errors.startAt ?? 'Fecha y hora de apertura (zona seleccionada).'}
                  InputLabelProps={{ shrink: true }}
                  required
                  fullWidth
                />
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  label="Fin (zona del evento)"
                  type="datetime-local"
                  value={formState.endAt}
                  onChange={handleChange('endAt')}
                  error={Boolean(errors.endAt)}
                  helperText={errors.endAt ?? 'Debe ser posterior al inicio.'}
                  InputLabelProps={{ shrink: true }}
                  required
                  fullWidth
                />
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  select
                  label="Zona horaria"
                  value={formState.timezone}
                  error={Boolean(errors.timezone)}
                  helperText={
                    errors.timezone ?? 'Selecciona una zona horaria IANA. Se preselecciona America/Mexico_City.'
                  }
                  required
                  fullWidth
                  SelectProps={{
                    onChange: (event) => handleTimezoneChange(event as SelectChangeEvent<string>),
                  }}
                >
                  {TIMEZONE_OPTIONS.map((timezone) => (
                    <MenuItem key={timezone} value={timezone}>
                      {timezone}
                    </MenuItem>
                  ))}
                </TextField>
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  select
                  label="Estado"
                  value={formState.status}
                  error={Boolean(errors.status)}
                  helperText={errors.status ?? 'Controla la disponibilidad pública.'}
                  required
                  fullWidth
                  SelectProps={{
                    onChange: (event) => handleSelectChange('status')(event as SelectChangeEvent<string>),
                  }}
                >
                  {statusOptions.map(([value, label]) => (
                    <MenuItem key={value} value={value}>
                      {label}
                    </MenuItem>
                  ))}
                </TextField>
              </Grid>
              <Grid item xs={12}>
                <Paper variant="outlined" sx={{ p: 2 }} role="status" aria-live="polite">
                  <Typography variant="subtitle2" color="text.secondary">
                    Duración total
                  </Typography>
                  <Typography variant="body1">
                    {formattedDuration ? `${formattedDuration} horas` : 'Se calculará automáticamente.'}
                  </Typography>
                </Paper>
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  label="Capacidad"
                  type="number"
                  value={formState.capacity}
                  onChange={handleChange('capacity')}
                  error={Boolean(errors.capacity)}
                  helperText={errors.capacity ?? 'Déjalo vacío para ilimitado.'}
                  fullWidth
                />
              </Grid>
              <Grid item xs={12} md={6}>
                <TextField
                  select
                  label="Política de check-in"
                  value={formState.checkinPolicy}
                  error={Boolean(errors.checkinPolicy)}
                  helperText={errors.checkinPolicy ?? 'Define cómo se validan las entradas.'}
                  required
                  fullWidth
                  SelectProps={{
                    onChange: (event) => handleSelectChange('checkinPolicy')(event as SelectChangeEvent<string>),
                  }}
                >
                  {checkinOptions.map(([value, label]) => (
                    <MenuItem key={value} value={value}>
                      {label}
                    </MenuItem>
                  ))}
                </TextField>
              </Grid>
              <Grid item xs={12}>
                <TextField
                  label="Configuración adicional (JSON)"
                  value={formState.settingsJson}
                  onChange={handleChange('settingsJson')}
                  error={Boolean(errors.settingsJson)}
                  helperText={errors.settingsJson ?? 'Opcional. Puedes incluir banderas o ajustes personalizados.'}
                  multiline
                  minRows={3}
                  fullWidth
                />
              </Grid>
            </Grid>
          )}
            <Box display="flex" justifyContent="flex-end" gap={2}>
              <Button variant="text" onClick={() => navigate('/events')} disabled={isSubmitting}>
                Cancelar
              </Button>
              <Button type="submit" variant="contained" startIcon={<SaveIcon />} disabled={isSubmitting}>
                {isSubmitting ? 'Guardando…' : 'Guardar'}
              </Button>
            </Box>
          </div>
        </form>
      </Paper>
    </Container>
  );
};

export default EventForm;
