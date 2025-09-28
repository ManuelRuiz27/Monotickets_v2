import { useMemo, useState, type MouseEvent } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  IconButton,
  Menu,
  MenuItem,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Tooltip,
  Typography,
  Divider,
} from '@mui/material';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import EventSeatIcon from '@mui/icons-material/EventSeat';
import BlockIcon from '@mui/icons-material/Block';
import DeleteIcon from '@mui/icons-material/Delete';
import QrCode2Icon from '@mui/icons-material/QrCode2';
import AutorenewIcon from '@mui/icons-material/Autorenew';
import type { GuestResource } from '../../hooks/useGuestsApi';
import {
  useDeleteTicket,
  useGuestTickets,
  useIssueTicket,
  useUpdateTicket,
  useRotateTicketQr,
  type TicketPayload,
  type TicketResource,
} from '../../hooks/useTicketsApi';
import { extractApiErrorMessage } from '../../utils/apiErrors';
import { useToast } from '../common/ToastProvider';
import IssueTicketDialog from './IssueTicketDialog';
import EditSeatDialog from './EditSeatDialog';
import TicketStatusChip from './TicketStatusChip';
import TicketQrDialog from './TicketQrDialog';

interface GuestTicketsTabProps {
  guest: GuestResource;
}

type ConfirmAction = 'revoke' | 'delete';

const formatPrice = (priceCents: number | null | undefined): string => {
  if (typeof priceCents !== 'number') {
    return '—';
  }

  const formatter = new Intl.NumberFormat('es-AR', {
    style: 'currency',
    currency: 'ARS',
    minimumFractionDigits: 2,
  });

  return formatter.format(priceCents / 100);
};

const formatSeat = (ticket: TicketResource): string => {
  if (ticket.seat_section && ticket.seat_row && ticket.seat_code) {
    return `${ticket.seat_section} / ${ticket.seat_row} / ${ticket.seat_code}`;
  }
  return '—';
};

const formatDateTime = (value: string | null | undefined): string => {
  if (!value) {
    return '—';
  }

  try {
    const date = new Date(value);
    return new Intl.DateTimeFormat('es-AR', {
      dateStyle: 'short',
      timeStyle: 'short',
    }).format(date);
  } catch {
    return value;
  }
};

const GuestTicketsTab = ({ guest }: GuestTicketsTabProps) => {
  const { showToast } = useToast();
  const ticketsQuery = useGuestTickets(guest.id);
  const tickets = ticketsQuery.data?.data ?? [];

  const [issueDialogOpen, setIssueDialogOpen] = useState(false);
  const [editSeatDialogOpen, setEditSeatDialogOpen] = useState(false);
  const [selectedTicket, setSelectedTicket] = useState<TicketResource | null>(null);
  const [menuAnchorEl, setMenuAnchorEl] = useState<HTMLElement | null>(null);
  const [confirmAction, setConfirmAction] = useState<ConfirmAction | null>(null);
  const [qrDialogOpen, setQrDialogOpen] = useState(false);

  const ticketLimit = useMemo(() => {
    const plusOnes = guest.allow_plus_ones ? guest.plus_ones_limit ?? 0 : 0;
    return 1 + plusOnes;
  }, [guest.allow_plus_ones, guest.plus_ones_limit]);

  const remainingTickets = Math.max(ticketLimit - tickets.length, 0);

  const issueMutation = useIssueTicket(guest.id, {
    onSuccess: () => {
      showToast({ message: 'Ticket emitido correctamente.', severity: 'success' });
      setIssueDialogOpen(false);
    },
    onError: (error) => {
      showToast({
        message: extractApiErrorMessage(error, 'No se pudo emitir el ticket.'),
        severity: 'error',
      });
    },
  });

  const updateMutation = useUpdateTicket(guest.id, {
    onSuccess: () => {
      showToast({ message: 'Ticket actualizado.', severity: 'success' });
      setEditSeatDialogOpen(false);
      setConfirmAction(null);
      setSelectedTicket(null);
    },
    onError: (error) => {
      showToast({
        message: extractApiErrorMessage(error, 'No se pudo actualizar el ticket.'),
        severity: 'error',
      });
    },
  });

  const deleteMutation = useDeleteTicket(guest.id, {
    onSuccess: () => {
      showToast({ message: 'Ticket eliminado.', severity: 'success' });
      setConfirmAction(null);
      setSelectedTicket(null);
    },
    onError: (error) => {
      showToast({
        message: extractApiErrorMessage(error, 'No se pudo eliminar el ticket.'),
        severity: 'error',
      });
    },
  });

  const rotateQrMutation = useRotateTicketQr(guest.id, {
    onSuccess: () => {
      showToast({ message: 'QR rotado correctamente.', severity: 'success' });
    },
    onError: (error) => {
      showToast({
        message: extractApiErrorMessage(error, 'No se pudo rotar el QR del ticket.'),
        severity: 'error',
      });
    },
  });

  const handleOpenMenu = (event: MouseEvent<HTMLElement>, ticket: TicketResource) => {
    setMenuAnchorEl(event.currentTarget);
    setSelectedTicket(ticket);
  };

  const handleCloseMenu = () => {
    setMenuAnchorEl(null);
  };

  const handleIssueSubmit = async (payload: TicketPayload) => {
    await issueMutation.mutateAsync(payload);
  };

  const handleSeatSubmit = async (payload: TicketPayload) => {
    if (!selectedTicket) {
      return;
    }
    await updateMutation.mutateAsync({ ticketId: selectedTicket.id, payload });
  };

  const handleRevoke = async () => {
    if (!selectedTicket) {
      return;
    }
    await updateMutation.mutateAsync({ ticketId: selectedTicket.id, payload: { status: 'revoked' } });
  };

  const handleDelete = async () => {
    if (!selectedTicket) {
      return;
    }
    await deleteMutation.mutateAsync({ ticketId: selectedTicket.id });
  };

  const renderRestrictionText = () => {
    if (!guest.allow_plus_ones) {
      return 'Este invitado puede tener un único ticket activo.';
    }
    const limit = guest.plus_ones_limit ?? 0;
    return `Este invitado puede tener hasta ${ticketLimit} tickets (${1} titular + ${limit} acompañantes).`;
  };

  return (
    <Paper elevation={0} sx={{ p: 2 }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} justifyContent="space-between" alignItems={{ xs: 'stretch', sm: 'center' }} spacing={2} mb={2}>
        <Box>
          <Typography variant="h6">Tickets emitidos</Typography>
          <Typography variant="body2" color="text.secondary">
            {renderRestrictionText()} Actualmente tiene {tickets.length} tickets. {remainingTickets === 0
              ? 'Se alcanzó el límite permitido.'
              : `Quedan ${remainingTickets} disponibles.`}
          </Typography>
        </Box>
        <Button
          variant="contained"
          onClick={() => setIssueDialogOpen(true)}
          disabled={remainingTickets <= 0 || issueMutation.isPending}
        >
          Emitir ticket
        </Button>
      </Stack>

      {ticketsQuery.isLoading ? (
        <Box py={6} display="flex" justifyContent="center" alignItems="center">
          <CircularProgress size={32} />
        </Box>
      ) : ticketsQuery.isError ? (
        <Alert severity="error">
          {extractApiErrorMessage(ticketsQuery.error, 'No se pudieron cargar los tickets del invitado.')}
        </Alert>
      ) : tickets.length === 0 ? (
        <Box py={6} display="flex" justifyContent="center" alignItems="center">
          <Typography variant="body2" color="text.secondary">
            Todavía no se emitieron tickets para este invitado.
          </Typography>
        </Box>
      ) : (
        <TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Tipo</TableCell>
                <TableCell>Precio</TableCell>
                <TableCell>Asiento</TableCell>
                <TableCell>Emitido</TableCell>
                <TableCell>Expira</TableCell>
                <TableCell>Estado</TableCell>
                <TableCell align="right">Acciones</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {tickets.map((ticket) => (
                <TableRow key={ticket.id} hover>
                  <TableCell sx={{ textTransform: 'uppercase' }}>{ticket.type}</TableCell>
                  <TableCell>{formatPrice(ticket.price_cents)}</TableCell>
                  <TableCell>{formatSeat(ticket)}</TableCell>
                  <TableCell>{formatDateTime(ticket.issued_at)}</TableCell>
                  <TableCell>{formatDateTime(ticket.expires_at)}</TableCell>
                  <TableCell>
                    <TicketStatusChip status={ticket.status} />
                  </TableCell>
                  <TableCell align="right">
                    <Tooltip title="Acciones">
                      <IconButton
                        aria-label="Acciones del ticket"
                        onClick={(event) => {
                          handleOpenMenu(event, ticket);
                        }}
                      >
                        <MoreVertIcon />
                      </IconButton>
                    </Tooltip>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <Menu anchorEl={menuAnchorEl} open={Boolean(menuAnchorEl)} onClose={handleCloseMenu}>
        <MenuItem
          onClick={() => {
            handleCloseMenu();
            setQrDialogOpen(true);
          }}
        >
          <Stack direction="row" spacing={1} alignItems="center">
            <QrCode2Icon fontSize="small" />
            <Typography variant="body2">Ver QR</Typography>
          </Stack>
        </MenuItem>
        <MenuItem
          disabled={selectedTicket?.status !== 'issued' || rotateQrMutation.isPending}
          onClick={async () => {
            if (!selectedTicket) {
              return;
            }
            handleCloseMenu();
            try {
              await rotateQrMutation.mutateAsync({ ticketId: selectedTicket.id });
            } catch {
              // handled in mutation
            }
          }}
        >
          <Stack direction="row" spacing={1} alignItems="center">
            <AutorenewIcon fontSize="small" />
            <Typography variant="body2">Rotar QR</Typography>
          </Stack>
        </MenuItem>
        <Divider sx={{ my: 0.5 }} />
        <MenuItem
          onClick={() => {
            handleCloseMenu();
            setEditSeatDialogOpen(true);
          }}
        >
          <Stack direction="row" spacing={1} alignItems="center">
            <EventSeatIcon fontSize="small" />
            <Typography variant="body2">Editar asiento</Typography>
          </Stack>
        </MenuItem>
        {selectedTicket?.status !== 'revoked' && (
          <MenuItem
            onClick={() => {
              handleCloseMenu();
              setConfirmAction('revoke');
            }}
          >
            <Stack direction="row" spacing={1} alignItems="center">
              <BlockIcon fontSize="small" />
              <Typography variant="body2">Revocar ticket</Typography>
            </Stack>
          </MenuItem>
        )}
        <MenuItem
          onClick={() => {
            handleCloseMenu();
            setConfirmAction('delete');
          }}
        >
          <Stack direction="row" spacing={1} alignItems="center">
            <DeleteIcon fontSize="small" />
            <Typography variant="body2">Eliminar ticket</Typography>
          </Stack>
        </MenuItem>
      </Menu>

      <IssueTicketDialog
        open={issueDialogOpen}
        onClose={() => setIssueDialogOpen(false)}
        onSubmit={handleIssueSubmit}
        isSubmitting={issueMutation.isPending}
      />

      <EditSeatDialog
        open={editSeatDialogOpen}
        onClose={() => {
          setEditSeatDialogOpen(false);
          setSelectedTicket(null);
        }}
        onSubmit={handleSeatSubmit}
        ticket={selectedTicket}
        isSubmitting={updateMutation.isPending}
      />

      <TicketQrDialog
        open={qrDialogOpen}
        ticket={qrDialogOpen ? selectedTicket : null}
        onClose={() => {
          setQrDialogOpen(false);
          setSelectedTicket(null);
        }}
      />

      <Dialog
        open={confirmAction !== null}
        onClose={() => {
          setConfirmAction(null);
          setSelectedTicket(null);
        }}
      >
        <DialogTitle>
          {confirmAction === 'revoke' ? 'Revocar ticket' : 'Eliminar ticket'}
        </DialogTitle>
        <DialogContent>
          <DialogContentText>
            {confirmAction === 'revoke'
              ? '¿Quieres revocar este ticket? El ticket quedará inhabilitado pero permanecerá en el historial.'
              : '¿Quieres eliminar este ticket? Se realizará una baja lógica y no aparecerá en los listados activos.'}
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button
            onClick={() => {
              setConfirmAction(null);
              setSelectedTicket(null);
            }}
            disabled={updateMutation.isPending || deleteMutation.isPending}
          >
            Cancelar
          </Button>
          <Button
            onClick={async () => {
              try {
                if (confirmAction === 'revoke') {
                  await handleRevoke();
                  setConfirmAction(null);
                  setSelectedTicket(null);
                } else if (confirmAction === 'delete') {
                  await handleDelete();
                  setConfirmAction(null);
                  setSelectedTicket(null);
                }
              } catch {
                // La gestión de errores se maneja en las mutaciones correspondientes.
              }
            }}
            color={confirmAction === 'delete' ? 'error' : 'primary'}
            disabled={updateMutation.isPending || deleteMutation.isPending}
          >
            {confirmAction === 'revoke' ? 'Revocar' : 'Eliminar'}
          </Button>
        </DialogActions>
      </Dialog>
    </Paper>
  );
};

export default GuestTicketsTab;
