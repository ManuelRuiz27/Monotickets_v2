import { Chip, type ChipProps } from '@mui/material';
import { EVENT_STATUS_LABELS, type EventStatus } from '../../hooks/useEventsApi';

interface EventStatusChipProps {
  status: EventStatus;
  size?: ChipProps['size'];
}

const STATUS_STYLES: Record<EventStatus, { color: ChipProps['color']; variant: ChipProps['variant'] }> = {
  draft: { color: 'info', variant: 'outlined' },
  published: { color: 'success', variant: 'filled' },
  archived: { color: 'warning', variant: 'filled' },
};

const EventStatusChip = ({ status, size = 'small' }: EventStatusChipProps) => {
  const style = STATUS_STYLES[status] ?? { color: 'default' as ChipProps['color'], variant: 'filled' as const };
  const label = EVENT_STATUS_LABELS[status] ?? status;

  return <Chip label={label} size={size} color={style.color} variant={style.variant} aria-label={`Estado: ${label}`} />;
};

export default EventStatusChip;
