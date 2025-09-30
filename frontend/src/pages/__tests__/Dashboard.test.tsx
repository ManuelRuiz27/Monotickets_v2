import { screen } from '@testing-library/react';
import { vi } from 'vitest';
import Dashboard from '../Dashboard';
import { renderWithProviders } from '../../test-utils';
import type { EventResource } from '../../hooks/useEventsApi';

const useEventsListMock = vi.fn();
const useEventAnalyticsMock = vi.fn();

vi.mock('../../hooks/useEventsApi', () => ({
  useEventsList: useEventsListMock,
}));

vi.mock('../../hooks/useEventAnalytics', () => ({
  useEventAnalytics: useEventAnalyticsMock,
}));

describe('Dashboard', () => {
  beforeEach(() => {
    const events: EventResource[] = [
      {
        id: 'event-1',
        tenant_id: 'tenant-1',
        organizer_user_id: 'user-1',
        code: 'EVT-001',
        name: 'Conferencia General',
        description: null,
        start_at: null,
        end_at: null,
        timezone: 'UTC',
        status: 'published',
        capacity: 1500,
        checkin_policy: 'single',
        settings_json: null,
        attendances_count: 750,
        tickets_issued: 800,
        capacity_used: 700,
        occupancy_percent: 0.5,
      },
    ];

    useEventsListMock.mockReturnValue({
      data: { data: events, meta: { page: 1, per_page: 10, total: 1, total_pages: 1 } },
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
    });

    useEventAnalyticsMock.mockReturnValue({
      data: {
        data: {
          hourly: {
            data: [
              { hour: '2024-04-01T10:00:00.000Z', valid: 100, duplicate: 5, unique: 90 },
              { hour: '2024-04-01T11:00:00.000Z', valid: 120, duplicate: 3, unique: 110 },
            ],
            meta: { page: 1, per_page: 24, total: 2, total_pages: 1 },
          },
          checkpoints: {
            data: [
              { checkpoint_id: 'c-1', name: 'Acceso Principal', valid: 400, duplicate: 8, invalid: 2 },
            ],
            meta: { page: 1, per_page: 10, total: 1, total_pages: 1 },
            totals: { valid: 400, duplicate: 8, invalid: 2 },
          },
          duplicates: {
            data: [
              {
                ticket_id: 't-1',
                qr_code: 'QR-123',
                guest_name: 'Juan Pérez',
                occurrences: 3,
                last_scanned_at: '2024-04-01T11:05:00.000Z',
              },
            ],
            meta: { page: 1, per_page: 10, total: 1, total_pages: 1 },
          },
          errors: {
            data: [
              {
                ticket_id: 't-2',
                result: 'expired',
                qr_code: 'QR-456',
                guest_name: 'Ana López',
                occurrences: 1,
                last_scanned_at: '2024-04-01T10:45:00.000Z',
              },
            ],
            meta: { page: 1, per_page: 10, total: 1, total_pages: 1 },
          },
        },
      },
      isLoading: false,
      isError: false,
      error: null,
    });
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('renderiza métricas básicas del evento seleccionado', async () => {
    const { container } = renderWithProviders(<Dashboard />);

    expect(await screen.findByText('Capacidad: 1,500')).toBeInTheDocument();
    expect(screen.getByText('Asistencias: 750')).toBeInTheDocument();
    expect(screen.getByText('Ocupación: 50%')).toBeInTheDocument();
    expect(screen.getByText('Duplicados recurrentes')).toBeInTheDocument();

    expect(container.firstChild).toMatchSnapshot();
  });
});
