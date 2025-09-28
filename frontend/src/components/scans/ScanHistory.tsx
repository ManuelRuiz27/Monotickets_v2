import { DateTime } from 'luxon';
import type { AttendanceCacheRecord } from '../../services/scanSync';
import { maskSensitiveText } from '../../utils/privacy';

interface ScanHistoryProps {
  history: AttendanceCacheRecord[];
  pendingCount: number;
}

function formatTimestamp(value: string | null): string {
  if (!value) {
    return 'Sin horario';
  }

  const parsed = DateTime.fromISO(value);
  if (!parsed.isValid) {
    return value;
  }

  return parsed.toFormat('dd/MM/yyyy HH:mm:ss');
}

const ScanHistory = ({ history, pendingCount }: ScanHistoryProps) => {
  return (
    <div className="scan-history">
      <div className="scan-history__header">
        <h3>Historial local</h3>
        <span className="scan-history__pending">Pendientes: {pendingCount}</span>
      </div>

      {history.length === 0 ? (
        <p className="scan-history__empty">Aún no hay registros almacenados en este dispositivo.</p>
      ) : (
        <ul className="scan-history__list">
          {history.map((item) => (
            <li key={item.id} className="scan-history__item">
              <div className="scan-history__meta">
                <span className="scan-history__code">{item.qr_code}</span>
                <span className="scan-history__timestamp">{formatTimestamp(item.scanned_at)}</span>
              </div>
              <p className="scan-history__message">
                {maskSensitiveText(item.message ?? `Resultado: ${item.result}`)}
              </p>
              <div className="scan-history__badges">
                <span className={`scan-history__badge scan-history__badge--${item.status}`}>
                  {item.status === 'pending' ? 'Pendiente' : 'Sincronizado'}
                </span>
                {item.conflict && (
                  <span className="scan-history__badge scan-history__badge--warning">Duplicado detectado</span>
                )}
                {item.offline && (
                  <span className="scan-history__badge scan-history__badge--offline">Registrado offline</span>
                )}
                {item.reason && (
                  <span className="scan-history__reason">{maskSensitiveText(item.reason)}</span>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default ScanHistory;
