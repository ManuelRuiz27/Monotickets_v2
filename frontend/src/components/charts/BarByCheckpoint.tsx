import { Box, Stack, Typography } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';
import { useId, useMemo } from 'react';

export interface BarByCheckpointDatum {
  checkpoint: string;
  valid: number;
  duplicate: number;
}

export interface BarByCheckpointProps {
  data: BarByCheckpointDatum[];
  ariaLabel?: string;
}

const getNiceTicks = (max: number, count = 4) => {
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

const BarByCheckpoint = ({ data, ariaLabel }: BarByCheckpointProps) => {
  const theme = useTheme();
  const chartId = useId();
  const titleId = `${chartId}-title`;
  const descId = `${chartId}-desc`;

  const rows = useMemo(() => {
    return data
      .map((item) => ({
        checkpoint: item.checkpoint || 'Sin checkpoint',
        valid: item.valid,
        duplicate: item.duplicate,
      }))
      .filter((item) => item.valid > 0 || item.duplicate > 0);
  }, [data]);

  if (rows.length === 0) {
    return (
      <Box py={4} display="flex" justifyContent="center">
        <Typography variant="body2" color="text.secondary">
          No hay asistencias registradas para los checkpoints.
        </Typography>
      </Box>
    );
  }

  const width = 720;
  const barHeight = 28;
  const gap = 16;
  const margin = { top: 16, right: 32, bottom: 56, left: 200 };
  const innerHeight = margin.top + margin.bottom + rows.length * barHeight + (rows.length - 1) * gap;
  const chartWidth = width - margin.left - margin.right;

  const maxValue = Math.max(...rows.map((item) => item.valid + item.duplicate));
  const ticks = getNiceTicks(maxValue);
  const xScale = (value: number) => margin.left + (value / maxValue) * chartWidth;

  const validColor = theme.palette.success.main;
  const duplicateColor = theme.palette.warning.main;
  const axisColor = alpha(theme.palette.text.primary, 0.26);
  const gridColor = alpha(theme.palette.text.primary, 0.12);
  const numberFormatter = new Intl.NumberFormat('es-MX');

  return (
    <Stack spacing={2}>
      <Box sx={{ width: '100%', height: innerHeight }}>
        <svg
          viewBox={`0 0 ${width} ${innerHeight}`}
          role="img"
          aria-labelledby={titleId}
          aria-describedby={descId}
          style={{ width: '100%', height: '100%' }}
        >
          <title id={titleId}>{ariaLabel ?? 'Asistencias por checkpoint'}</title>
          <desc id={descId}>
            {`Comparativa de asistencias válidas y duplicadas por checkpoint.`}
          </desc>

          <line
            x1={margin.left}
            x2={margin.left + chartWidth}
            y1={innerHeight - margin.bottom}
            y2={innerHeight - margin.bottom}
            stroke={axisColor}
            strokeWidth={1}
          />

          {ticks.map((value) => {
            const x = xScale(value);
            return (
              <g key={`grid-${value}`}>
                <line
                  x1={x}
                  x2={x}
                  y1={margin.top}
                  y2={innerHeight - margin.bottom}
                  stroke={gridColor}
                  strokeWidth={1}
                  strokeDasharray="4 4"
                />
                <text x={x} y={innerHeight - margin.bottom + 24} fontSize={12} textAnchor="middle" fill={theme.palette.text.secondary}>
                  {numberFormatter.format(value)}
                </text>
              </g>
            );
          })}

          {rows.map((item, index) => {
            const y = margin.top + index * (barHeight + gap);
            const total = item.valid + item.duplicate;
            const validWidth = (item.valid / maxValue) * chartWidth;
            const duplicateWidth = (item.duplicate / maxValue) * chartWidth;
            const tooltip = `${item.checkpoint}: ${numberFormatter.format(item.valid)} válidos, ${numberFormatter.format(item.duplicate)} duplicados`;

            return (
              <g key={`${item.checkpoint}-${index}`}>
                <text
                  x={margin.left - 16}
                  y={y + barHeight / 2}
                  fontSize={13}
                  textAnchor="end"
                  alignmentBaseline="middle"
                  fill={theme.palette.text.primary}
                >
                  {item.checkpoint}
                </text>
                <rect
                  x={margin.left}
                  y={y}
                  width={validWidth}
                  height={barHeight}
                  fill={validColor}
                  rx={4}
                  ry={4}
                >
                  <title>{`Válidos - ${tooltip}`}</title>
                </rect>
                <rect
                  x={margin.left + validWidth}
                  y={y}
                  width={duplicateWidth}
                  height={barHeight}
                  fill={duplicateColor}
                  rx={4}
                  ry={4}
                >
                  <title>{`Duplicados - ${tooltip}`}</title>
                </rect>
                <text
                  x={margin.left + (total / maxValue) * chartWidth + 8}
                  y={y + barHeight / 2}
                  fontSize={12}
                  alignmentBaseline="middle"
                  fill={theme.palette.text.secondary}
                >
                  {numberFormatter.format(total)}
                </text>
              </g>
            );
          })}
        </svg>
      </Box>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems="center">
        <Stack direction="row" spacing={1} alignItems="center">
          <Box sx={{ width: 12, height: 12, borderRadius: 1, bgcolor: validColor }} aria-hidden />
          <Typography variant="body2">Válidos</Typography>
        </Stack>
        <Stack direction="row" spacing={1} alignItems="center">
          <Box sx={{ width: 12, height: 12, borderRadius: 1, bgcolor: duplicateColor }} aria-hidden />
          <Typography variant="body2">Duplicados</Typography>
        </Stack>
      </Stack>
    </Stack>
  );
};

export default BarByCheckpoint;
