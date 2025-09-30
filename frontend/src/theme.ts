import { createTheme } from '@mui/material/styles';

const brandNavy = '#020617';
const brandDeep = '#0f172a';
const brandBlue = '#2563eb';
const brandSky = '#38bdf8';
const brandAmber = '#facc15';
const brandEmerald = '#22c55e';
const brandRose = '#ef4444';

const theme = createTheme({
  palette: {
    mode: 'dark',
    primary: {
      main: brandBlue,
      contrastText: '#f8fafc',
    },
    secondary: {
      main: brandSky,
    },
    background: {
      default: brandNavy,
      paper: brandDeep,
    },
    text: {
      primary: '#f8fafc',
      secondary: '#cbd5f5',
    },
    success: {
      main: brandEmerald,
    },
    warning: {
      main: brandAmber,
      contrastText: brandNavy,
    },
    error: {
      main: brandRose,
    },
    divider: 'rgba(148, 163, 184, 0.2)',
  },
  typography: {
    fontFamily: '"Inter", "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif',
    h1: { fontWeight: 600 },
    h2: { fontWeight: 600 },
    h3: { fontWeight: 600 },
    h4: { fontWeight: 600 },
    h5: { fontWeight: 600 },
    h6: { fontWeight: 600 },
    button: {
      textTransform: 'none',
      fontWeight: 600,
    },
  },
  shape: {
    borderRadius: 12,
  },
  components: {
    MuiPaper: {
      styleOverrides: {
        root: {
          backgroundImage: 'none',
        },
      },
    },
    MuiButton: {
      defaultProps: {
        disableElevation: true,
      },
      styleOverrides: {
        root: {
          borderRadius: 999,
        },
      },
    },
    MuiLink: {
      styleOverrides: {
        root: {
          textDecoration: 'none',
        },
      },
    },
  },
});

export default theme;
