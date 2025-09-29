import { Card, CardContent, Typography } from '@mui/material';
import { useMemo } from 'react';

export interface KpiCardProps {
  label: string;
  value: string | number;
  subvalue?: string | number | null;
  ariaLabel?: string;
}

const KpiCard = ({ label, value, subvalue, ariaLabel }: KpiCardProps) => {
  const labelText = useMemo(() => {
    const main = typeof value === 'number' ? value.toLocaleString('es-MX') : value;
    if (subvalue === null || subvalue === undefined || subvalue === '') {
      return `${label}: ${main}`;
    }

    const secondary = typeof subvalue === 'number' ? subvalue.toLocaleString('es-MX') : subvalue;
    return `${label}: ${main} (${secondary})`;
  }, [label, subvalue, value]);

  return (
    <Card
      variant="outlined"
      role="group"
      aria-label={ariaLabel ?? labelText}
      title={labelText}
      sx={{ height: '100%' }}
    >
      <CardContent>
        <Typography variant="overline" color="text.secondary">
          {label}
        </Typography>
        <Typography variant="h5" component="div">
          {typeof value === 'number' ? value.toLocaleString('es-MX') : value}
        </Typography>
        {subvalue !== undefined && subvalue !== null && subvalue !== '' && (
          <Typography variant="body2" color="text.secondary">
            {typeof subvalue === 'number' ? subvalue.toLocaleString('es-MX') : subvalue}
          </Typography>
        )}
      </CardContent>
    </Card>
  );
};

export default KpiCard;
