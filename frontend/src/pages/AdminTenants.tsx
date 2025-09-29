import { type ChangeEvent, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Container,
  FormControl,
  IconButton,
  InputLabel,
  LinearProgress,
  Menu,
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
  Tooltip,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import { DateTime } from 'luxon';
import { useNavigate } from 'react-router-dom';
import type { SelectChangeEvent } from '@mui/material/Select';
import { useAdminPlans } from '../hooks/useAdminPlans';
import {
  useAdminTenants,
  useCreateTenant,
  useUpdateTenant,
  type AdminTenantSummary,
  type CreateTenantPayload,
  type UpdateTenantPayload,
} from '../hooks/useAdminTenants';
import CreateTenantDialog from '../components/tenants/CreateTenantDialog';
import UpdateTenantPlanDialog from '../components/tenants/UpdateTenantPlanDialog';
import LimitOverridesDialog from '../components/tenants/LimitOverridesDialog';
import { useToast } from '../components/common/ToastProvider';

const STATUS_FILTERS: { value: string; label: string }[] = [
  { value: 'all', label: 'Todos los estados' },
  { value: 'trialing', label: 'Prueba' },
  { value: 'active', label: 'Activos' },
  { value: 'paused', label: 'Pausados' },
  { value: 'canceled', label: 'Cancelados' },
  { value: 'none', label: 'Sin suscripción' },
];

const formatPeriod = (start: string | null | undefined, end: string | null | undefined) => {
  if (!start || !end) {
    return 'Periodo indefinido';
  }

  try {
    const from = DateTime.fromISO(start).toFormat('dd LLL');
    const to = DateTime.fromISO(end).toFormat('dd LLL yyyy');
    return `${from} – ${to}`;
  } catch {
    return 'Periodo indefinido';
  }
};

const formatPercent = (value: number) =>
  new Intl.NumberFormat('es-MX', { style: 'percent', maximumFractionDigits: 0 }).format(value);

const AdminTenants = () => {
  const navigate = useNavigate();
  const { showToast } = useToast();

  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [statusFilter, setStatusFilter] = useState('all');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  const [createOpen, setCreateOpen] = useState(false);
  const [planDialogTenant, setPlanDialogTenant] = useState<AdminTenantSummary | null>(null);
  const [limitsDialogTenant, setLimitsDialogTenant] = useState<AdminTenantSummary | null>(null);
  const [menuAnchor, setMenuAnchor] = useState<null | HTMLElement>(null);
  const [menuTenant, setMenuTenant] = useState<AdminTenantSummary | null>(null);

  useEffect(() => {
    const handle = window.setTimeout(() => setDebouncedSearch(search), 400);
    return () => window.clearTimeout(handle);
  }, [search]);

  const { data: plansResponse } = useAdminPlans();
  const plans = plansResponse?.data ?? [];

  const filters = useMemo(
    () => ({
      page: page + 1,
      perPage: rowsPerPage,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      search: debouncedSearch.trim() || undefined,
    }),
    [page, rowsPerPage, statusFilter, debouncedSearch],
  );

  const tenantsQuery = useAdminTenants(filters);
  const tenants = tenantsQuery.data?.data ?? [];
  const meta = tenantsQuery.data?.meta;

  useEffect(() => {
    if (!meta) {
      return;
    }

    const nextPage = Math.max(0, (meta.page ?? 1) - 1);
    if (nextPage !== page) {
      setPage(nextPage);
    }
    if (meta.per_page && meta.per_page !== rowsPerPage) {
      setRowsPerPage(meta.per_page);
    }
  }, [meta]);

  const createTenantMutation = useCreateTenant({
    onSuccess: (tenant) => {
      showToast({ message: `Tenant ${tenant.name ?? tenant.slug} creado correctamente`, severity: 'success' });
      setCreateOpen(false);
    },
  });

  const activeTenantId = planDialogTenant?.id ?? limitsDialogTenant?.id ?? menuTenant?.id ?? '';

  const updateTenantMutation = useUpdateTenant(activeTenantId, {
    onSuccess: (tenant) => {
      showToast({ message: `Tenant ${tenant.name ?? tenant.slug} actualizado`, severity: 'success' });
      setPlanDialogTenant(null);
      setLimitsDialogTenant(null);
      setMenuTenant(null);
      setMenuAnchor(null);
    },
  });

  const handleChangePage = (_event: unknown, newPage: number) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  const handleStatusChange = (event: SelectChangeEvent<string>) => {
    setStatusFilter(event.target.value);
    setPage(0);
  };

  const handleSearchChange = (event: ChangeEvent<HTMLInputElement>) => {
    setSearch(event.target.value);
    setPage(0);
  };

  const openMenu = (tenant: AdminTenantSummary, target: HTMLElement) => {
    setMenuTenant(tenant);
    setMenuAnchor(target);
  };

  const closeMenu = () => {
    setMenuTenant(null);
    setMenuAnchor(null);
  };

  const handleCreateTenant = async (payload: CreateTenantPayload) => {
    try {
      await createTenantMutation.mutateAsync(payload);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'No se pudo crear el tenant.';
      showToast({ message, severity: 'error' });
    }
  };

  const handleUpdateTenant = async (payload: UpdateTenantPayload) => {
    if (!activeTenantId) {
      return;
    }
    try {
      await updateTenantMutation.mutateAsync(payload);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'No se pudo actualizar el tenant.';
      showToast({ message, severity: 'error' });
    }
  };

  const renderConsumption = (tenant: AdminTenantSummary) => {
    const limits = (tenant.effective_limits ?? {}) as Record<string, unknown>;
    const eventLimit = Number(limits.max_events ?? 0) || null;
    const userLimit = Number(limits.max_users ?? 0) || null;
    const scanLimit = Number(limits.max_scans_per_event ?? 0) || null;

    const eventRatio = eventLimit ? Math.min(tenant.usage.event_count / eventLimit, 1) : null;
    const userRatio = userLimit ? Math.min(tenant.usage.user_count / userLimit, 1) : null;

    const perEventBase = tenant.usage.event_count > 0 ? tenant.usage.event_count : 1;
    const scanTotalLimit = scanLimit ? scanLimit * perEventBase : null;
    const scanRatio = scanTotalLimit ? Math.min(tenant.usage.scan_count / scanTotalLimit, 1) : null;

    return (
      <Stack spacing={0.5}>
        <Typography variant="body2" color="text.secondary">
          Eventos: {eventLimit ? `${tenant.usage.event_count}/${eventLimit} (${formatPercent(eventRatio ?? 0)})` : 'Sin límite'}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Usuarios: {userLimit ? `${tenant.usage.user_count}/${userLimit} (${formatPercent(userRatio ?? 0)})` : 'Sin límite'}
        </Typography>
        <Tooltip
          title={
            scanLimit
              ? 'Consumo estimado comparado contra el límite de escaneos por evento multiplicado por eventos activos.'
              : 'Sin límite configurado'
          }
        >
          <Typography variant="body2" color="text.secondary">
            Escaneos: {scanLimit ? `${tenant.usage.scan_count}/${scanTotalLimit} (${formatPercent(scanRatio ?? 0)})` : 'Sin límite'}
          </Typography>
        </Tooltip>
      </Stack>
    );
  };

  const handleOpenPlanDialog = (tenant: AdminTenantSummary) => {
    setPlanDialogTenant(tenant);
    setMenuTenant(tenant);
    closeMenu();
  };

  const handleOpenLimitsDialog = (tenant: AdminTenantSummary) => {
    setLimitsDialogTenant(tenant);
    setMenuTenant(tenant);
    closeMenu();
  };

  const handleNavigateToUsage = (tenant: AdminTenantSummary) => {
    navigate(`/admin/tenants/${tenant.id}/usage`);
    closeMenu();
  };

  return (
    <Container maxWidth="xl" sx={{ py: 4 }}>
      <Stack spacing={3}>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} justifyContent="space-between" alignItems={{ xs: 'flex-start', md: 'center' }}>
          <Box>
            <Typography variant="h4" component="h1" gutterBottom>
              Tenants
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Administra planes, límites y consumo de todos los tenants activos.
            </Typography>
          </Box>
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreateOpen(true)}>
            Crear tenant
          </Button>
        </Stack>

        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ xs: 'stretch', md: 'center' }}>
          <FormControl size="small" sx={{ minWidth: 200 }}>
            <InputLabel id="tenant-status-filter-label">Estado</InputLabel>
            <Select
              labelId="tenant-status-filter-label"
              label="Estado"
              value={statusFilter}
              onChange={handleStatusChange}
            >
              {STATUS_FILTERS.map((option) => (
                <MenuItem key={option.value} value={option.value}>
                  {option.label}
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          <TextField
            value={search}
            onChange={handleSearchChange}
            label="Buscar"
            placeholder="Nombre o slug"
            size="small"
            sx={{ flex: 1, minWidth: { xs: '100%', md: 280 } }}
          />
        </Stack>

        <Paper variant="outlined" sx={{ position: 'relative', overflow: 'hidden' }}>
          {tenantsQuery.isFetching && tenants.length > 0 && (
            <LinearProgress sx={{ position: 'absolute', top: 0, left: 0, right: 0 }} />
          )}
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Tenant</TableCell>
                  <TableCell>Plan</TableCell>
                  <TableCell>Estado</TableCell>
                  <TableCell align="right">Eventos</TableCell>
                  <TableCell align="right">Usuarios</TableCell>
                  <TableCell align="right">Escaneos totales</TableCell>
                  <TableCell>Consumo límites</TableCell>
                  <TableCell align="right">Acciones</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {tenants.map((tenant) => {
                  const subscriptionStatus = tenant.subscription?.status ?? 'sin suscripción';
                  return (
                    <TableRow key={tenant.id} hover>
                      <TableCell>
                        <Stack spacing={0.5}>
                          <Typography variant="subtitle2">{tenant.name ?? 'Sin nombre'}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {tenant.slug ?? tenant.id}
                          </Typography>
                        </Stack>
                      </TableCell>
                      <TableCell>
                        <Stack spacing={0.5}>
                          <Typography variant="body2">{tenant.plan?.name ?? 'Sin plan'}</Typography>
                          {tenant.plan && (
                            <Typography variant="caption" color="text.secondary">
                              {tenant.plan.billing_cycle === 'yearly' ? 'Anual' : 'Mensual'} ·
                              {' '}
                              {new Intl.NumberFormat('es-MX', {
                                style: 'currency',
                                currency: 'USD',
                              }).format((tenant.plan.price_cents ?? 0) / 100)}
                            </Typography>
                          )}
                        </Stack>
                      </TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={1} alignItems="center">
                          <Chip size="small" label={tenant.status === 'inactive' ? 'Inactivo' : 'Activo'} color={tenant.status === 'inactive' ? 'default' : 'success'} />
                          <Chip
                            size="small"
                            label={subscriptionStatus}
                            color={subscriptionStatus === 'active' ? 'success' : subscriptionStatus === 'trialing' ? 'info' : subscriptionStatus === 'paused' ? 'warning' : subscriptionStatus === 'canceled' ? 'error' : 'default'}
                          />
                        </Stack>
                      </TableCell>
                      <TableCell align="right">{tenant.usage.event_count.toLocaleString('es-MX')}</TableCell>
                      <TableCell align="right">{tenant.usage.user_count.toLocaleString('es-MX')}</TableCell>
                      <TableCell align="right">
                        <Stack spacing={0.5} alignItems="flex-end">
                          <Typography variant="body2">{tenant.usage.scan_count.toLocaleString('es-MX')}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {formatPeriod(
                              tenant.subscription?.current_period_start ?? tenant.created_at,
                              tenant.subscription?.current_period_end ?? tenant.updated_at,
                            )}
                          </Typography>
                        </Stack>
                      </TableCell>
                      <TableCell>{renderConsumption(tenant)}</TableCell>
                      <TableCell align="right">
                        <IconButton
                          aria-label="acciones"
                          onClick={(event) => openMenu(tenant, event.currentTarget)}
                          size="small"
                        >
                          <MoreVertIcon fontSize="small" />
                        </IconButton>
                      </TableCell>
                    </TableRow>
                  );
                })}
                {tenants.length === 0 && !tenantsQuery.isLoading && !tenantsQuery.isFetching && (
                  <TableRow>
                    <TableCell colSpan={8}>
                      <Box py={4} textAlign="center" color="text.secondary">
                        No se encontraron tenants para los filtros seleccionados.
                      </Box>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </TableContainer>
          {tenantsQuery.isLoading && (
            <Box display="flex" justifyContent="center" py={3}>
              <CircularProgress size={24} />
            </Box>
          )}
          <TablePagination
            component="div"
            count={meta?.total ?? 0}
            page={page}
            onPageChange={handleChangePage}
            rowsPerPage={rowsPerPage}
            onRowsPerPageChange={handleChangeRowsPerPage}
            rowsPerPageOptions={[10, 25, 50]}
          />
        </Paper>

        {tenantsQuery.isError && (
          <Alert severity="error">No se pudieron cargar los tenants. Intenta nuevamente.</Alert>
        )}
      </Stack>

      <CreateTenantDialog
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        plans={plans}
        onSubmit={handleCreateTenant}
        isSubmitting={createTenantMutation.isPending}
      />

      <UpdateTenantPlanDialog
        open={Boolean(planDialogTenant)}
        onClose={() => setPlanDialogTenant(null)}
        tenant={planDialogTenant}
        plans={plans}
        onSubmit={(payload) => {
          if (planDialogTenant) {
            void handleUpdateTenant(payload);
          }
        }}
        isSubmitting={updateTenantMutation.isPending}
      />

      <LimitOverridesDialog
        open={Boolean(limitsDialogTenant)}
        onClose={() => setLimitsDialogTenant(null)}
        tenant={limitsDialogTenant}
        onSubmit={(payload) => {
          if (limitsDialogTenant) {
            void handleUpdateTenant(payload);
          }
        }}
        isSubmitting={updateTenantMutation.isPending}
      />

      <Menu anchorEl={menuAnchor} open={Boolean(menuAnchor)} onClose={closeMenu}>
        <MenuItem onClick={() => menuTenant && handleOpenPlanDialog(menuTenant)}>Cambiar plan</MenuItem>
        <MenuItem onClick={() => menuTenant && handleOpenLimitsDialog(menuTenant)}>Modificar límites</MenuItem>
        <MenuItem onClick={() => menuTenant && handleNavigateToUsage(menuTenant)}>Ver uso</MenuItem>
      </Menu>
    </Container>
  );
};

export default AdminTenants;
