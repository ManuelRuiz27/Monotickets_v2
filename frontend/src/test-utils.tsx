import { ReactElement } from 'react';
import { render, type RenderOptions } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CssBaseline, ThemeProvider } from '@mui/material';
import { MemoryRouter, type MemoryRouterProps } from 'react-router-dom';
import theme from './theme';

interface ProvidersOptions {
  route?: string;
  routerProps?: MemoryRouterProps;
  queryClient?: QueryClient;
}

export const createTestQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
    },
  });

export function renderWithProviders(
  ui: ReactElement,
  { route = '/', routerProps, queryClient }: ProvidersOptions = {},
  renderOptions?: Omit<RenderOptions, 'wrapper'>,
) {
  const client = queryClient ?? createTestQueryClient();

  function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={client}>
        <ThemeProvider theme={theme}>
          <CssBaseline />
          <MemoryRouter initialEntries={[route]} {...routerProps}>
            {children}
          </MemoryRouter>
        </ThemeProvider>
      </QueryClientProvider>
    );
  }

  return {
    queryClient: client,
    ...render(ui, { wrapper: Wrapper, ...renderOptions }),
  };
}
