import { Box, Stack, Typography } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';
import { DateTime } from 'luxon';
import { useId, useMemo } from 'react';

export interface TimeSeriesDatum {
  hour: string | null;
  valid: number;
  duplicate: number;
  unique: number;
}

export interface TimeSeriesProps {
  data: TimeSeriesDatum[];
  timezone?: string | null;
  ariaLabel?: string;
  height?: number;
}

const getNiceTicks = (max: number, count = 5) => {
  if (max <= 0) {
    return [0];
  }

  const rawStep = max / count;
  const magnitude = 10 ** Math.floor(Math.log10(rawStep));
  const residual = rawStep / magnitude;

  let niceResidual = 1;
  if (residual > 5) {
    niceResidual = 10;
  } else if (residual > 2) {
    niceResidual = 5;
  } else if (residual > 1) {
    niceResidual = 2;
  }

  const step = niceResidual * magnitude;
  const ticks: number[] = [0];
  for (let value = step; value <= max + step / 2; value += step) {
    ticks.push(value);
  }

  return ticks;
};

const TimeSeries = ({ data, timezone, ariaLabel, height = 320 }: TimeSeriesProps) => {
  const theme = useTheme();
  const chartId = useId();
  const titleId = `${chartId}-title`;
  const descId = `${chartId}-desc`;

  const parsedData = useMemo(() => {
    return data
      .filter((item) => item.hour)
      .map((item) => {
        const dt = DateTime.fromISO(item.hour as string, { setZone: true }).setZone(timezone ?? 'UTC');
        if (!dt.isValid) {
          return null;
        }

        return {
          ...item,
          time: dt,
          timestamp: dt.toMillis(),
          label: dt.toFormat("dd MMM HH:mm 'hrs' (z)", { locale: 'es' }),
        };
      })
      .filter((item): item is NonNullable<typeof item> => Boolean(item))
      .sort((a, b) => a.timestamp - b.timestamp);
  }, [data, timezone]);

  if (parsedData.length === 0) {
    return (
      <Box py={4} display="flex" justifyContent="center">
        <Typography variant="body2" color="text.secondary">
          No hay datos suficientes para mostrar la gráfica.
        </Typography>
      </Box>
    );
  }

  const width = 720;
  const innerHeight = height;
  const margin = { top: 16, right: 32, bottom: 56, left: 72 };
  const chartWidth = width - margin.left - margin.right;
  const chartHeight = innerHeight - margin.top - margin.bottom;

  const minTimestamp = parsedData[0].timestamp;
  const maxTimestamp = parsedData[parsedData.length - 1].timestamp;
  const timeSpan = Math.max(1, maxTimestamp - minTimestamp);

  const maxValue = Math.max(
    1,
    ...parsedData.map((item) => Math.max(item.valid, item.duplicate, item.unique)),
  );

  const ticks = getNiceTicks(maxValue);

  const xScale = (timestamp: number) => {
    return margin.left + ((timestamp - minTimestamp) / timeSpan) * chartWidth;
  };

  const yScale = (value: number) => {
    return margin.top + chartHeight - (value / maxValue) * chartHeight;
  };

  const buildPath = (key: 'valid' | 'duplicate' | 'unique') => {
    return parsedData
      .map((item, index) => {
        const x = xScale(item.timestamp);
        const y = yScale(item[key]);
        return `${index === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`;
      })
      .join(' ');
  };

  const validColor = theme.palette.success.main;
  const duplicateColor = theme.palette.warning.main;
  const uniqueColor = theme.palette.info.main;

  const gridColor = alpha(theme.palette.text.primary, 0.12);
  const axisColor = alpha(theme.palette.text.primary, 0.26);

  const xTickCount = Math.min(parsedData.length, 6);
  const tickStep = Math.max(1, Math.floor(parsedData.length / xTickCount));
  const xTicks = parsedData.filter((_, index) => index % tickStep === 0);

  const numberFormatter = new Intl.NumberFormat('es-MX');

  return (
    <Stack spacing={2}>
      <Box sx={{ width: '100%', height }}>
        <svg
          viewBox={`0 0 ${width} ${innerHeight}`}
          role="img"
          aria-labelledby={titleId}
          aria-describedby={descId}
          style={{ width: '100%', height: '100%' }}
        >
          <title id={titleId}>{ariaLabel ?? 'Serie temporal de asistencias por hora'}</title>
          <desc id={descId}>
            {`Se muestran registros válidos, duplicados y únicos por hora en la zona horaria ${timezone ?? 'UTC'}.`}
          </desc>

          <rect
            x={margin.left}
            y={margin.top}
            width={chartWidth}
            height={chartHeight}
            fill={alpha(theme.palette.background.paper, 0.02)}
            stroke={axisColor}
            strokeWidth={1}
          />

          {ticks.map((value) => {
            const y = yScale(value);
            return (
              <g key={`grid-${value}`}>
                <line
                  x1={margin.left}
                  x2={margin.left + chartWidth}
                  y1={y}
                  y2={y}
                  stroke={gridColor}
                  strokeWidth={1}
                  strokeDasharray="4 4"
                />
                <text
                  x={margin.left - 8}
                  y={y + 4}
                  fontSize={12}
                  textAnchor="end"
                  fill={theme.palette.text.secondary}
                >
                  {numberFormatter.format(value)}
                </text>
              </g>
            );
          })}

          <line
            x1={margin.left}
            x2={margin.left + chartWidth}
            y1={margin.top + chartHeight}
            y2={margin.top + chartHeight}
            stroke={axisColor}
            strokeWidth={1}
          />

          {xTicks.map((item) => {
            const x = xScale(item.timestamp);
            return (
              <g key={`tick-${item.timestamp}`}>
                <line
                  x1={x}
                  x2={x}
                  y1={margin.top + chartHeight}
                  y2={margin.top + chartHeight + 6}
                  stroke={axisColor}
                  strokeWidth={1}
                />
                <text
                  x={x}
                  y={margin.top + chartHeight + 20}
                  fontSize={12}
                  textAnchor="middle"
                  fill={theme.palette.text.secondary}
                >
                  {item.time.toFormat('dd MMM', { locale: 'es' })}
                </text>
                <text
                  x={x}
                  y={margin.top + chartHeight + 36}
                  fontSize={11}
                  textAnchor="middle"
                  fill={theme.palette.text.secondary}
                >
                  {item.time.toFormat('HH:mm', { locale: 'es' })}
                </text>
              </g>
            );
          })}

          <path
            d={buildPath('unique')}
            fill="none"
            stroke={uniqueColor}
            strokeWidth={2}
            strokeDasharray="6 4"
            strokeLinecap="round"
            strokeLinejoin="round"
          />

          <path
            d={buildPath('duplicate')}
            fill="none"
            stroke={duplicateColor}
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
          />

          <path
            d={buildPath('valid')}
            fill="none"
            stroke={validColor}
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
          />

          {parsedData.map((item) => {
            const x = xScale(item.timestamp);
            const validY = yScale(item.valid);
            const duplicateY = yScale(item.duplicate);
            const uniqueY = yScale(item.unique);
            const tooltip = `${item.label}: ${numberFormatter.format(item.valid)} válidos, ${numberFormatter.format(item.duplicate)} duplicados, ${numberFormatter.format(item.unique)} únicos`;

            return (
              <g key={`points-${item.timestamp}`}>
                <circle cx={x} cy={validY} r={4} fill={validColor} aria-hidden="true">
                  <title>{tooltip}</title>
                </circle>
                <circle cx={x} cy={duplicateY} r={4} fill={duplicateColor} aria-hidden="true">
                  <title>{tooltip}</title>
                </circle>
                <circle cx={x} cy={uniqueY} r={4} fill={uniqueColor} aria-hidden="true">
                  <title>{tooltip}</title>
                </circle>
              </g>
            );
          })}
        </svg>
      </Box>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} flexWrap="wrap" alignItems="center">
        <Stack direction="row" spacing={1} alignItems="center">
          <Box sx={{ width: 12, height: 12, borderRadius: 1, bgcolor: validColor }} aria-hidden />
          <Typography variant="body2">Asistencias válidas</Typography>
        </Stack>
        <Stack direction="row" spacing={1} alignItems="center">
          <Box sx={{ width: 12, height: 12, borderRadius: 1, bgcolor: duplicateColor }} aria-hidden />
          <Typography variant="body2">Duplicados</Typography>
        </Stack>
        <Stack direction="row" spacing={1} alignItems="center">
          <Box
            sx={{ width: 12, height: 12, borderRadius: 1, border: `2px dashed ${uniqueColor}` }}
            aria-hidden
          />
          <Typography variant="body2">Asistentes únicos</Typography>
        </Stack>
      </Stack>
    </Stack>
  );
};

export default TimeSeries;
