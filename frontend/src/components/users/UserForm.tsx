import { useEffect, useMemo, useState, type ChangeEvent, type FormEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormControlLabel,
  FormHelperText,
  InputLabel,
  MenuItem,
  OutlinedInput,
  Select,
  Switch,
  TextField,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import { apiFetch, ApiError } from '../../api/client';
import { useAuthStore } from '../../auth/store';
import {
  CreateUserPayload,
  UpdateUserPayload,
  UserResource,
  UserSingleResponse,
} from './types';

const ROLE_OPTIONS: { value: string; label: string }[] = [
  { value: 'superadmin', label: 'Superadministrador' },
  { value: 'organizer', label: 'Organizador' },
  { value: 'hostess', label: 'Hostess' },
];

type FormState = {
  name: string;
  email: string;
  phone: string;
  password: string;
  confirmPassword: string;
  roles: string[];
  isActive: boolean;
};

const defaultFormState: FormState = {
  name: '',
  email: '',
  phone: '',
  password: '',
  confirmPassword: '',
  roles: [],
  isActive: true,
};

interface UserFormProps {
  open: boolean;
  onClose: () => void;
  user?: UserResource;
  onSuccess: (user: UserResource) => void;
}

const UserForm = ({ open, onClose, user, onSuccess }: UserFormProps) => {
  const { tenantId, user: currentUser } = useAuthStore();
  const [formState, setFormState] = useState<FormState>(defaultFormState);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEditMode = Boolean(user);
  const isCurrentUserSuperadmin = currentUser?.role === 'superadmin';

  useEffect(() => {
    if (!open) {
      setFormState(defaultFormState);
      setError(null);
      return;
    }
    if (user) {
      setFormState({
        name: user.name ?? '',
        email: user.email ?? '',
        phone: user.phone ?? '',
        password: '',
        confirmPassword: '',
        roles: user.roles.map((role) => role.code),
        isActive: user.is_active,
      });
    } else {
      setFormState(defaultFormState);
    }
  }, [user, open]);

  const disabledRoles = useMemo(() => {
    if (isCurrentUserSuperadmin) {
      return new Set<string>();
    }
    return new Set<string>(['superadmin']);
  }, [isCurrentUserSuperadmin]);

  const handleChange = (key: keyof FormState) =>
    (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
      const value =
        key === 'isActive'
          ? (event.target as HTMLInputElement).checked
          : event.target.value;
      setFormState((prev) => ({ ...prev, [key]: value }));
    };

  const handleRolesChange = (event: SelectChangeEvent<string[]>) => {
    const value = event.target.value as string[];
    setFormState((prev) => ({ ...prev, roles: value }));
  };

  const validate = (): string | null => {
    if (!formState.name.trim()) {
      return 'El nombre es obligatorio.';
    }
    if (!formState.roles.length) {
      return 'Debes asignar al menos un rol.';
    }
    if (!isEditMode) {
      if (!formState.email.trim()) {
        return 'El correo es obligatorio.';
      }
      if (!formState.password.trim()) {
        return 'La contraseña es obligatoria.';
      }
      if (formState.password.length < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
      }
      if (formState.password !== formState.confirmPassword) {
        return 'Las contraseñas no coinciden.';
      }
    }
    return null;
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const validationError = validate();
    if (validationError) {
      setError(validationError);
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      let response: UserSingleResponse;
      if (isEditMode && user) {
        const payload: UpdateUserPayload = {
          name: formState.name.trim(),
          phone: formState.phone.trim() || null,
          is_active: formState.isActive,
          roles: formState.roles,
        };
        response = await apiFetch<UserSingleResponse>(`/users/${user.id}`, {
          method: 'PATCH',
          body: JSON.stringify(payload),
        });
      } else {
        const payload: CreateUserPayload = {
          name: formState.name.trim(),
          email: formState.email.trim(),
          phone: formState.phone.trim() || null,
          password: formState.password,
          roles: formState.roles,
          is_active: formState.isActive,
          tenant_id: tenantId ?? undefined,
        };
        response = await apiFetch<UserSingleResponse>('/users', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
      }
      onSuccess(response.data);
      onClose();
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'No se pudo guardar el usuario.';
      setError(message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>{isEditMode ? 'Editar usuario' : 'Crear usuario'}</DialogTitle>
      <Box component="form" onSubmit={handleSubmit} noValidate>
        <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {error && (
            <Alert severity="error" onClose={() => setError(null)}>
              {error}
            </Alert>
          )}
          <TextField
            label="Nombre"
            name="name"
            required
            value={formState.name}
            onChange={handleChange('name')}
          />
          <TextField
            label="Correo electrónico"
            name="email"
            type="email"
            required
            disabled={isEditMode}
            value={formState.email}
            onChange={handleChange('email')}
          />
          <TextField
            label="Teléfono"
            name="phone"
            value={formState.phone}
            onChange={handleChange('phone')}
          />
          {!isEditMode && (
            <>
              <TextField
                label="Contraseña"
                name="password"
                type="password"
                required
                value={formState.password}
                onChange={handleChange('password')}
              />
              <TextField
                label="Confirmar contraseña"
                name="confirmPassword"
                type="password"
                required
                value={formState.confirmPassword}
                onChange={handleChange('confirmPassword')}
              />
            </>
          )}
          <FormControl required>
            <InputLabel id="roles-label">Roles</InputLabel>
            <Select
              labelId="roles-label"
              multiple
              value={formState.roles}
              onChange={handleRolesChange}
              input={<OutlinedInput label="Roles" />}
              renderValue={(selected) =>
                selected
                  .map((value) => ROLE_OPTIONS.find((option) => option.value === value)?.label ?? value)
                  .join(', ')
              }
            >
              {ROLE_OPTIONS.map((option) => (
                <MenuItem key={option.value} value={option.value} disabled={disabledRoles.has(option.value)}>
                  {option.label}
                </MenuItem>
              ))}
            </Select>
            {formState.roles.length === 0 && <FormHelperText>Selecciona al menos un rol.</FormHelperText>}
          </FormControl>
          <FormControlLabel
            control={<Switch checked={formState.isActive} onChange={handleChange('isActive')} />}
            label="Usuario activo"
          />
          {!isEditMode && !isCurrentUserSuperadmin && (
            <Typography variant="body2" color="text.secondary">
              No puedes crear usuarios superadmin. Asigna roles de organizador u operador.
            </Typography>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} color="inherit" disabled={submitting}>
            Cancelar
          </Button>
          <Button type="submit" variant="contained" disabled={submitting}>
            {isEditMode ? 'Guardar cambios' : 'Crear usuario'}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
};

export default UserForm;
