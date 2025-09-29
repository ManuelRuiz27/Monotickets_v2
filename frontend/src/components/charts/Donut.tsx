import { Box, Stack, Typography } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';
import { useId, useMemo } from 'react';

export interface DonutDatum {
  label: string;
  value: number;
  color?: string;
}

export interface DonutProps {
  data: DonutDatum[];
  ariaLabel?: string;
}

const Donut = ({ data, ariaLabel }: DonutProps) => {
  const theme = useTheme();
  const chartId = useId();
  const titleId = `${chartId}-title`;
  const descId = `${chartId}-desc`;

  const segments = useMemo(() => {
    return data.filter((item) => item.value > 0);
  }, [data]);

  const total = useMemo(() => segments.reduce((sum, item) => sum + item.value, 0), [segments]);

  if (!segments.length || total === 0) {
    return (
      <Box py={4} display="flex" justifyContent="center">
        <Typography variant="body2" color="text.secondary">
          No hay datos disponibles para mostrar.
        </Typography>
      </Box>
    );
  }

  const radius = 90;
  const innerRadius = 55;
  const size = radius * 2 + 32;
  const circumference = 2 * Math.PI * radius;

  const palette = [
    theme.palette.primary.main,
    theme.palette.success.main,
    theme.palette.info.main,
    theme.palette.warning.main,
    theme.palette.secondary?.main ?? theme.palette.primary.dark,
    theme.palette.error.main,
  ];

  let offset = 0;
  const numberFormatter = new Intl.NumberFormat('es-MX');

  const circles = segments.map((segment, index) => {
    const fraction = segment.value / total;
    const length = circumference * fraction;
    const color = segment.color ?? palette[index % palette.length];
    const circle = (
      <circle
        key={`${segment.label}-${index}`}
        cx={radius + 16}
        cy={radius + 16}
        r={radius}
        fill="transparent"
        stroke={color}
        strokeWidth={radius - innerRadius}
        strokeDasharray={`${length} ${circumference - length}`}
        strokeDashoffset={-offset}
        strokeLinecap="round"
      >
        <title>{`${segment.label}: ${numberFormatter.format(segment.value)} (${Math.round(fraction * 100)}%)`}</title>
      </circle>
    );
    offset += length;
    return circle;
  });

  return (
    <Stack direction={{ xs: 'column', md: 'row' }} spacing={3} alignItems="center" justifyContent="center">
      <Box sx={{ width: size, height: size }}>
        <svg
          viewBox={`0 0 ${size} ${size}`}
          role="img"
          aria-labelledby={titleId}
          aria-describedby={descId}
          style={{ width: '100%', height: '100%' }}
        >
          <title id={titleId}>{ariaLabel ?? 'Distribución'}</title>
          <desc id={descId}>{`Distribución proporcional de ${segments.length} categorías.`}</desc>

          <circle
            cx={radius + 16}
            cy={radius + 16}
            r={radius}
            fill="transparent"
            stroke={alpha(theme.palette.text.primary, 0.08)}
            strokeWidth={radius - innerRadius}
          />
          {circles}
          <text
            x={radius + 16}
            y={radius + 12}
            textAnchor="middle"
            fontSize={16}
            fontWeight={600}
            fill={theme.palette.text.primary}
          >
            {numberFormatter.format(total)}
          </text>
          <text
            x={radius + 16}
            y={radius + 32}
            textAnchor="middle"
            fontSize={12}
            fill={theme.palette.text.secondary}
          >
            Total
          </text>
        </svg>
      </Box>
      <Stack spacing={1} component="ul" sx={{ listStyle: 'none', m: 0, p: 0 }}>
        {segments.map((segment, index) => {
          const fraction = segment.value / total;
          const color = segment.color ?? palette[index % palette.length];
          return (
            <Stack
              key={`${segment.label}-${index}`}
              direction="row"
              spacing={1.5}
              alignItems="center"
              component="li"
              role="listitem"
            >
              <Box sx={{ width: 12, height: 12, borderRadius: '50%', bgcolor: color }} aria-hidden />
              <Typography variant="body2">
                {segment.label}: {numberFormatter.format(segment.value)} ({Math.round(fraction * 100)}%)
              </Typography>
            </Stack>
          );
        })}
      </Stack>
    </Stack>
  );
};

export default Donut;
