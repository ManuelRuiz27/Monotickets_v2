import { useCallback, useEffect, useMemo, useState, type ChangeEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Container,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  FormControl,
  InputLabel,
  IconButton,
  MenuItem,
  Paper,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TextField,
  Toolbar,
  Tooltip,
  Typography,
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material/Select';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/Delete';
import EditIcon from '@mui/icons-material/Edit';
import { apiFetch, ApiError } from '../../api/client';
import UserForm from './UserForm';
import type { UserResource, UsersListResponse } from './types';

const ROLE_FILTER_OPTIONS = [
  { value: '', label: 'Todos los roles' },
  { value: 'superadmin', label: 'Superadmin' },
  { value: 'organizer', label: 'Organizador' },
  { value: 'hostess', label: 'Hostess' },
];

type StatusFilter = 'all' | 'active' | 'inactive';

const STATUS_FILTER_OPTIONS: { value: StatusFilter; label: string }[] = [
  { value: 'all', label: 'Todos los estados' },
  { value: 'active', label: 'Activos' },
  { value: 'inactive', label: 'Inactivos' },
];

const emptyMeta: UsersListResponse['meta'] = {
  page: 1,
  per_page: 10,
  total: 0,
  total_pages: 0,
};

const UsersList = () => {
  const [users, setUsers] = useState<UserResource[]>([]);
  const [meta, setMeta] = useState<UsersListResponse['meta']>(emptyMeta);
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [formOpen, setFormOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<UserResource | undefined>(undefined);
  const [deleteCandidate, setDeleteCandidate] = useState<UserResource | null>(null);

  useEffect(() => {
    const handle = window.setTimeout(() => setDebouncedSearch(search), 400);
    return () => window.clearTimeout(handle);
  }, [search]);

  const fetchUsers = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      params.set('page', String(page + 1));
      params.set('per_page', String(rowsPerPage));
      if (debouncedSearch.trim()) {
        params.set('search', debouncedSearch.trim());
      }
      if (roleFilter) {
        params.set('role', roleFilter);
      }
      if (statusFilter !== 'all') {
        params.set('is_active', statusFilter === 'active' ? 'true' : 'false');
      }
      const response = await apiFetch<UsersListResponse>(`/users?${params.toString()}`);
      setUsers(response.data);
      setMeta(response.meta);
      setRowsPerPage((prev) => (prev === response.meta.per_page ? prev : response.meta.per_page));
      const nextPage = Math.max(0, (response.meta.page ?? 1) - 1);
      setPage((prev) => (prev === nextPage ? prev : nextPage));
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'No se pudieron cargar los usuarios.';
      setError(message);
      setUsers([]);
      setMeta({ ...emptyMeta, per_page: rowsPerPage });
    } finally {
      setLoading(false);
    }
  }, [page, rowsPerPage, debouncedSearch, roleFilter, statusFilter]);

  useEffect(() => {
    fetchUsers();
  }, [fetchUsers]);

  const handleChangePage = (_event: unknown, newPage: number) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  const handleRoleFilterChange = (event: SelectChangeEvent<string>) => {
    setRoleFilter(event.target.value);
    setPage(0);
  };

  const handleStatusFilterChange = (event: SelectChangeEvent<StatusFilter>) => {
    setStatusFilter(event.target.value as StatusFilter);
    setPage(0);
  };

  const handleSearchChange = (event: ChangeEvent<HTMLInputElement>) => {
    setSearch(event.target.value);
    setPage(0);
  };

  const handleOpenCreate = () => {
    setSelectedUser(undefined);
    setFormOpen(true);
  };

  const handleOpenEdit = (user: UserResource) => {
    setSelectedUser(user);
    setFormOpen(true);
  };

  const handleCloseForm = () => {
    setFormOpen(false);
    setSelectedUser(undefined);
  };

  const handleFormSuccess = (_user: UserResource) => {
    fetchUsers();
  };

  const handleRequestDelete = (user: UserResource) => {
    setDeleteCandidate(user);
  };

  const handleCloseDeleteDialog = () => {
    setDeleteCandidate(null);
  };

  const handleConfirmDelete = async () => {
    if (!deleteCandidate) return;
    try {
      await apiFetch(`/users/${deleteCandidate.id}`, { method: 'DELETE' });
      handleCloseDeleteDialog();
      await fetchUsers();
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'No se pudo eliminar el usuario.';
      setError(message);
      handleCloseDeleteDialog();
    }
  };

  const totalCount = useMemo(() => meta.total ?? 0, [meta.total]);

  return (
    <Container maxWidth="lg" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ xs: 'flex-start', sm: 'center' }} spacing={2}>
          <Box>
            <Typography variant="h4" component="h1">
              Usuarios
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Gestiona los accesos del equipo con filtros rápidos y acciones directas.
            </Typography>
          </Box>
          <Button variant="contained" startIcon={<AddIcon />} onClick={handleOpenCreate}>
            Nuevo usuario
          </Button>
        </Stack>
        <Paper elevation={0} variant="outlined">
          <Toolbar sx={{ display: 'flex', flexDirection: { xs: 'column', md: 'row' }, gap: 2 }}>
            <TextField
              label="Buscar"
              placeholder="Nombre o correo"
              value={search}
              onChange={handleSearchChange}
              fullWidth
            />
            <FormControl sx={{ minWidth: 160 }}>
              <InputLabel id="role-filter-label">Rol</InputLabel>
              <Select
                labelId="role-filter-label"
                label="Rol"
                value={roleFilter}
                onChange={handleRoleFilterChange}
              >
                {ROLE_FILTER_OPTIONS.map((option) => (
                  <MenuItem key={option.value} value={option.value}>
                    {option.label}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControl sx={{ minWidth: 160 }}>
              <InputLabel id="status-filter-label">Estado</InputLabel>
              <Select
                labelId="status-filter-label"
                label="Estado"
                value={statusFilter}
                onChange={handleStatusFilterChange}
              >
                {STATUS_FILTER_OPTIONS.map((option) => (
                  <MenuItem key={option.value} value={option.value}>
                    {option.label}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
          </Toolbar>
          {error && (
            <Box px={3} pb={2}>
              <Alert severity="error">{error}</Alert>
            </Box>
          )}
          {loading ? (
            <Box py={6} display="flex" justifyContent="center" alignItems="center">
              <CircularProgress />
            </Box>
          ) : users.length === 0 ? (
            <Box py={6} display="flex" justifyContent="center" alignItems="center">
              <Typography variant="body2" color="text.secondary">
                No se encontraron usuarios con los criterios seleccionados.
              </Typography>
            </Box>
          ) : (
            <>
              <TableContainer>
                <Table>
                  <TableHead>
                    <TableRow>
                      <TableCell>Nombre</TableCell>
                      <TableCell>Correo</TableCell>
                      <TableCell>Roles</TableCell>
                      <TableCell>Estado</TableCell>
                      <TableCell align="right">Acciones</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {users.map((user) => (
                      <TableRow key={user.id} hover>
                        <TableCell>
                          <Typography variant="subtitle2">{user.name}</Typography>
                          <Typography variant="body2" color="text.secondary">
                            ID: {user.id}
                          </Typography>
                        </TableCell>
                        <TableCell>{user.email}</TableCell>
                        <TableCell>
                          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                            {user.roles.map((role) => (
                              <Chip key={role.id ?? role.code} label={role.name ?? role.code} size="small" />
                            ))}
                          </Stack>
                        </TableCell>
                        <TableCell>
                          <Chip
                            label={user.is_active ? 'Activo' : 'Inactivo'}
                            color={user.is_active ? 'success' : 'default'}
                            size="small"
                          />
                        </TableCell>
                        <TableCell align="right">
                          <Tooltip title="Editar">
                            <IconButton aria-label="Editar" onClick={() => handleOpenEdit(user)}>
                              <EditIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                          <Tooltip title="Eliminar">
                            <IconButton aria-label="Eliminar" onClick={() => handleRequestDelete(user)}>
                              <DeleteIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
              <TablePagination
                component="div"
                count={totalCount}
                page={page}
                onPageChange={handleChangePage}
                rowsPerPage={rowsPerPage}
                onRowsPerPageChange={handleChangeRowsPerPage}
                rowsPerPageOptions={[5, 10, 25]}
              />
            </>
          )}
        </Paper>
      </Stack>
      <UserForm open={formOpen} onClose={handleCloseForm} user={selectedUser} onSuccess={handleFormSuccess} />
      <Dialog open={Boolean(deleteCandidate)} onClose={handleCloseDeleteDialog}>
        <DialogTitle>Eliminar usuario</DialogTitle>
        <DialogContent>
          <DialogContentText>
            ¿Deseas eliminar a {deleteCandidate?.name}? Esta acción puede revertirse reactivando el usuario desde la API.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDeleteDialog}>Cancelar</Button>
          <Button onClick={handleConfirmDelete} color="error" variant="contained">
            Eliminar
          </Button>
        </DialogActions>
      </Dialog>
    </Container>
  );
};

export default UsersList;
