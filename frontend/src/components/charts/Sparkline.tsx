import { Box, Typography } from '@mui/material';
import { alpha, useTheme } from '@mui/material/styles';

interface SparklineProps {
  data: number[];
  width?: number;
  height?: number;
  color?: string;
  ariaLabel?: string;
}

const Sparkline = ({ data, width = 160, height = 60, color, ariaLabel }: SparklineProps) => {
  const theme = useTheme();
  const strokeColor = color ?? theme.palette.primary.main;
  const fillColor = alpha(strokeColor, 0.18);
  const padding = 4;

  if (data.length === 0) {
    return (
      <Box
        sx={{
          border: '1px dashed',
          borderColor: 'divider',
          borderRadius: 1,
          width,
          height,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: 'text.secondary',
        }}
      >
        <Typography variant="caption" color="inherit">
          Sin datos
        </Typography>
      </Box>
    );
  }

  const min = Math.min(...data);
  const max = Math.max(...data);
  const range = max - min || 1;

  const coordinates = data.map((value, index) => {
    const ratio = data.length === 1 ? 0.5 : index / (data.length - 1);
    const x = padding + ratio * (width - padding * 2);
    const normalized = range === 0 ? 0.5 : (value - min) / range;
    const y = height - padding - normalized * (height - padding * 2);
    return { x, y };
  });

  const linePath = coordinates
    .map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
    .join(' ');

  const lastPoint = coordinates[coordinates.length - 1];
  const areaPath = [
    `M${padding} ${height - padding}`,
    ...coordinates.map((point) => `L${point.x.toFixed(2)} ${point.y.toFixed(2)}`),
    `L${lastPoint.x.toFixed(2)} ${height - padding}`,
    'Z',
  ].join(' ');

  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      role="img"
      aria-label={ariaLabel}
      style={{ display: 'block' }}
    >
      <path d={areaPath} fill={fillColor} stroke="none" />
      <path
        d={linePath}
        fill="none"
        stroke={strokeColor}
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
};

export default Sparkline;
