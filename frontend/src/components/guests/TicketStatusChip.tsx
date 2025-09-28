import { Chip, type ChipProps } from '@mui/material';
import type { TicketStatus } from '../../hooks/useTicketsApi';

const STATUS_LABELS: Record<TicketStatus, string> = {
  issued: 'Emitido',
  used: 'Usado',
  revoked: 'Revocado',
  expired: 'Expirado',
};

const STATUS_COLORS: Record<TicketStatus, ChipProps['color']> = {
  issued: 'info',
  used: 'success',
  revoked: 'error',
  expired: 'warning',
};

interface TicketStatusChipProps {
  status: TicketStatus;
}

const TicketStatusChip = ({ status }: TicketStatusChipProps) => {
  return <Chip label={STATUS_LABELS[status] ?? status} color={STATUS_COLORS[status] ?? 'default'} size="small" />;
};

export default TicketStatusChip;
