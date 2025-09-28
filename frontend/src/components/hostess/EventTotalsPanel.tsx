import type { FC } from 'react';
import { useMemo } from 'react';
import { useEventTotalsStream } from '../../hostess/useEventTotalsStream';

interface EventTotalsPanelProps {
  eventId: string | null;
  eventName: string | null;
  checkpointId: string | null;
  checkpointName: string | null;
}

const formatNumber = (value: number | null | undefined): string => {
  if (value === null || value === undefined) {
    return '—';
  }
  return value.toLocaleString('es-MX');
};

const EventTotalsPanel: FC<EventTotalsPanelProps> = ({
  eventId,
  eventName,
  checkpointId,
  checkpointName,
}) => {
  const { connectionStatus, eventTotals, checkpointTotals, latencyAverageMs } = useEventTotalsStream(
    eventId,
    checkpointId,
  );

  const statusLabel = useMemo(() => {
    switch (connectionStatus) {
      case 'online':
        return 'Online';
      case 'offline':
        return 'Offline';
      case 'connecting':
        return 'Conectando';
      default:
        return 'Sin evento';
    }
  }, [connectionStatus]);

  const statusClassName = useMemo(() => {
    return `hostess-totals__status hostess-totals__status--${connectionStatus}`;
  }, [connectionStatus]);

  const latencyLabel = latencyAverageMs !== null ? `${Math.round(latencyAverageMs)} ms` : 'N/D';

  return (
    <aside className="hostess-totals">
      <h2>Resumen en tiempo real</h2>

      {!eventId ? (
        <p>Selecciona un evento para ver los totales en vivo.</p>
      ) : (
        <div className="hostess-totals__content">
          <div className="hostess-totals__meta">
            <div className={statusClassName}>{statusLabel}</div>
            <div className="hostess-totals__latency">
              <span>Latencia promedio (1 min)</span>
              <strong>{latencyLabel}</strong>
            </div>
          </div>

          <div className="hostess-totals__section">
            <h3>{eventName ?? 'Evento seleccionado'}</h3>
            <div className="hostess-totals__grid">
              <div className="hostess-totals__item hostess-totals__item--valid">
                <span>Válidos</span>
                <strong>{formatNumber(eventTotals?.valid)}</strong>
              </div>
              <div className="hostess-totals__item hostess-totals__item--duplicate">
                <span>Duplicados</span>
                <strong>{formatNumber(eventTotals?.duplicate)}</strong>
              </div>
              <div className="hostess-totals__item hostess-totals__item--invalid">
                <span>Inválidos</span>
                <strong>{formatNumber(eventTotals?.invalid)}</strong>
              </div>
            </div>
          </div>

          <div className="hostess-totals__section">
            <h3>{checkpointName ?? 'Punto de control actual'}</h3>
            {checkpointTotals ? (
              <div className="hostess-totals__grid">
                <div className="hostess-totals__item hostess-totals__item--valid">
                  <span>Válidos</span>
                  <strong>{formatNumber(checkpointTotals.valid)}</strong>
                </div>
                <div className="hostess-totals__item hostess-totals__item--duplicate">
                  <span>Duplicados</span>
                  <strong>{formatNumber(checkpointTotals.duplicate)}</strong>
                </div>
                <div className="hostess-totals__item hostess-totals__item--invalid">
                  <span>Inválidos</span>
                  <strong>{formatNumber(checkpointTotals.invalid)}</strong>
                </div>
              </div>
            ) : (
              <p className="hostess-totals__empty">Sin datos disponibles.</p>
            )}
          </div>
        </div>
      )}
    </aside>
  );
};

export default EventTotalsPanel;

