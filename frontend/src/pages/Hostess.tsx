import { ChangeEvent, FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { fetchHostessAssignments, registerHostessDevice } from '../api/hostess';
import EventTotalsPanel from '../components/hostess/EventTotalsPanel';
import QrScanner from '../components/scans/QrScanner';
import ScanHistory from '../components/scans/ScanHistory';
import { useHostessStore } from '../hostess/store';
import type { HostessCheckpoint, HostessEvent, HostessVenue } from '../hostess/types';
import { extractApiErrorMessage } from '../utils/apiErrors';
import {
  generateEncryptedFingerprint,
  resolveDeviceName,
  resolveDevicePlatform,
} from '../utils/fingerprint';
import {
  subscribeToSyncEvents,
  useAttendanceHistory,
  usePendingQueueCount,
  useScanSync,
} from '../services/scanSync';
import { sha256HexFromString } from '../utils/crypto';
import { maskSensitiveText } from '../utils/privacy';

const LAST_CHECKPOINT_KEY = 'monotickets:lastCheckpoint';
const PIN_STORAGE_KEY = 'monotickets:hostessPin';
const PIN_LENGTH = 4;

interface PersistedSelection {
  eventId: string | null;
  venueId: string | null;
  checkpointId: string | null;
}

const readPersistedSelection = (): PersistedSelection | null => {
  if (typeof window === 'undefined') {
    return null;
  }

  try {
    const stored = window.localStorage.getItem(LAST_CHECKPOINT_KEY);
    if (!stored) {
      return null;
    }
    const parsed = JSON.parse(stored) as PersistedSelection;
    return {
      eventId: parsed.eventId ?? null,
      venueId: parsed.venueId ?? null,
      checkpointId: parsed.checkpointId ?? null,
    };
  } catch (error) {
    console.warn('No se pudo leer la selección guardada del checkpoint.', error);
    return null;
  }
};

const Hostess = () => {
  const [assignmentsLoading, setAssignmentsLoading] = useState(false);
  const [assignmentsError, setAssignmentsError] = useState<string | null>(null);
  const [deviceLoading, setDeviceLoading] = useState(false);
  const [deviceError, setDeviceError] = useState<string | null>(null);
  const [duplicateBanner, setDuplicateBanner] = useState<string | null>(null);
  const [pinHash, setPinHash] = useState<string | null>(null);
  const [isLocked, setIsLocked] = useState(false);
  const [pinModalMode, setPinModalMode] = useState<'set' | 'update' | null>(null);
  const [pinValue, setPinValue] = useState('');
  const [pinConfirmation, setPinConfirmation] = useState('');
  const [currentPin, setCurrentPin] = useState('');
  const [pinError, setPinError] = useState<string | null>(null);
  const [unlockPin, setUnlockPin] = useState('');
  const [unlockError, setUnlockError] = useState<string | null>(null);

  const assignments = useHostessStore((state) => state.assignments);
  const currentEvent = useHostessStore((state) => state.currentEvent);
  const currentVenue = useHostessStore((state) => state.currentVenue);
  const currentCheckpoint = useHostessStore((state) => state.currentCheckpoint);
  const device = useHostessStore((state) => state.device);
  const setAssignments = useHostessStore((state) => state.setAssignments);
  const selectEvent = useHostessStore((state) => state.selectEvent);
  const selectVenue = useHostessStore((state) => state.selectVenue);
  const selectCheckpoint = useHostessStore((state) => state.selectCheckpoint);
  const setDevice = useHostessStore((state) => state.setDevice);

  const attendanceHistory = useAttendanceHistory(currentEvent?.id ?? null, 25);
  const pendingQueueCount = usePendingQueueCount();

  const updateStoredPin = useCallback((value: string | null) => {
    setPinHash(value);
    if (typeof window === 'undefined') {
      return;
    }
    if (value) {
      window.localStorage.setItem(PIN_STORAGE_KEY, value);
    } else {
      window.localStorage.removeItem(PIN_STORAGE_KEY);
    }
  }, []);

  const lockInterface = useCallback(() => {
    setIsLocked(true);
    setUnlockPin('');
    setUnlockError(null);
  }, []);

  const unlockInterface = useCallback(() => {
    setIsLocked(false);
    setUnlockPin('');
    setUnlockError(null);
  }, []);

  useScanSync(currentEvent?.id ?? null);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }
    const storedPin = window.localStorage.getItem(PIN_STORAGE_KEY);
    if (storedPin) {
      setPinHash(storedPin);
      lockInterface();
    }
  }, [lockInterface]);

  useEffect(() => {
    if (!pinHash) {
      return;
    }
    if (typeof window === 'undefined' || typeof document === 'undefined') {
      return;
    }

    const handleWindowBlur = () => {
      lockInterface();
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'hidden') {
        lockInterface();
      }
    };

    window.addEventListener('blur', handleWindowBlur);
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      window.removeEventListener('blur', handleWindowBlur);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [lockInterface, pinHash]);

  const checkpointDisplayName = currentCheckpoint
    ? currentCheckpoint.name
    : currentEvent
      ? 'Todos los checkpoints'
      : null;

  useEffect(() => {
    const unsubscribe = subscribeToSyncEvents((event) => {
      if (event.type === 'duplicate') {
        const rawMessage = event.record.message
          ? `${event.record.message} (${event.record.qr_code})`
          : `Se detectó un duplicado para ${event.record.qr_code}.`;
        setDuplicateBanner(maskSensitiveText(rawMessage));
      }
    });

    return () => unsubscribe();
  }, []);

  const dismissDuplicateBanner = () => setDuplicateBanner(null);

  const events = useMemo((): HostessEvent[] => {
    const map = new Map<string, HostessEvent>();
    assignments.forEach((assignment) => {
      const event = assignment.event;
      const eventId = event?.id ?? assignment.event_id;
      if (!eventId || map.has(eventId)) {
        return;
      }
      map.set(
        eventId,
        event ?? {
          id: eventId,
          name: 'Evento sin nombre',
          start_at: null,
          end_at: null,
        }
      );
    });
    return Array.from(map.values());
  }, [assignments]);

  const venues = useMemo((): HostessVenue[] => {
    if (!currentEvent) {
      return [];
    }
    const map = new Map<string, HostessVenue>();
    assignments
      .filter((assignment) => (assignment.event?.id ?? assignment.event_id) === currentEvent.id)
      .forEach((assignment) => {
        if (assignment.venue) {
          map.set(assignment.venue.id, assignment.venue);
        }
      });
    return Array.from(map.values());
  }, [assignments, currentEvent]);

  const checkpoints = useMemo((): HostessCheckpoint[] => {
    if (!currentEvent) {
      return [];
    }
    const map = new Map<string, HostessCheckpoint>();
    assignments
      .filter((assignment) => (assignment.event?.id ?? assignment.event_id) === currentEvent.id)
      .filter((assignment) => {
        if (!currentVenue) {
          return true;
        }
        return assignment.venue?.id === currentVenue.id || assignment.venue_id === currentVenue.id;
      })
      .forEach((assignment) => {
        if (assignment.checkpoint) {
          map.set(assignment.checkpoint.id, assignment.checkpoint);
        }
      });
    return Array.from(map.values());
  }, [assignments, currentEvent, currentVenue]);

  const loadAssignments = useCallback(async () => {
    setAssignmentsLoading(true);
    setAssignmentsError(null);
    try {
      const data = await fetchHostessAssignments();
      setAssignments(data);
      if (data.length === 0) {
        selectEvent(null);
        return;
      }

      const persisted = readPersistedSelection();
      if (persisted?.eventId) {
        const eventExists = data.some(
          (assignment) => (assignment.event?.id ?? assignment.event_id) === persisted.eventId
        );

        if (eventExists) {
          selectEvent(persisted.eventId);
          selectVenue(persisted.venueId ?? null);
          selectCheckpoint(persisted.checkpointId ?? null);
          return;
        }
      }

      const desiredEventId = currentEvent?.id ?? data[0].event?.id ?? data[0].event_id;
      selectEvent(desiredEventId ?? null);
    } catch (error) {
      setAssignmentsError(extractApiErrorMessage(error, 'No fue posible cargar tus asignaciones.'));
    } finally {
      setAssignmentsLoading(false);
    }
  }, [currentEvent?.id, selectCheckpoint, selectEvent, selectVenue, setAssignments]);

  const handleDeviceRegistration = useCallback(async () => {
    setDeviceLoading(true);
    setDeviceError(null);
    try {
      const fingerprint = await generateEncryptedFingerprint();
      const registeredDevice = await registerHostessDevice({
        fingerprint,
        name: resolveDeviceName(),
        platform: resolveDevicePlatform(),
      });
      setDevice(registeredDevice);
    } catch (error) {
      setDeviceError(extractApiErrorMessage(error, 'No fue posible registrar este dispositivo.'));
    } finally {
      setDeviceLoading(false);
    }
  }, [setDevice]);

  useEffect(() => {
    void loadAssignments();
  }, [loadAssignments]);

  useEffect(() => {
    if (!device && !deviceLoading) {
      void handleDeviceRegistration();
    }
  }, [device, deviceLoading, handleDeviceRegistration]);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    if (!currentEvent) {
      window.localStorage.removeItem(LAST_CHECKPOINT_KEY);
      return;
    }

    const payload: PersistedSelection = {
      eventId: currentEvent.id,
      venueId: currentVenue?.id ?? null,
      checkpointId: currentCheckpoint?.id ?? null,
    };

    window.localStorage.setItem(LAST_CHECKPOINT_KEY, JSON.stringify(payload));
  }, [currentCheckpoint, currentEvent, currentVenue]);

  const handleEventChange = (event: ChangeEvent<HTMLSelectElement>) => {
    const value = event.target.value || null;
    selectEvent(value);
  };

  const handleVenueChange = (event: ChangeEvent<HTMLSelectElement>) => {
    const value = event.target.value || null;
    selectVenue(value);
  };

  const handleCheckpointChange = (event: ChangeEvent<HTMLSelectElement>) => {
    const value = event.target.value || null;
    selectCheckpoint(value);
  };

  const sanitizePinInput = (value: string) => value.replace(/\D/g, '').slice(0, PIN_LENGTH);

  const openPinModal = (mode: 'set' | 'update') => {
    setPinModalMode(mode);
    setPinError(null);
    setPinValue('');
    setPinConfirmation('');
    setCurrentPin('');
  };

  const closePinModal = () => {
    setPinModalMode(null);
    setPinError(null);
    setPinValue('');
    setPinConfirmation('');
    setCurrentPin('');
  };

  const handleRemovePin = () => {
    if (!pinHash) {
      return;
    }
    if (typeof window !== 'undefined') {
      const shouldRemove = window.confirm('¿Eliminar el PIN de bloqueo de este dispositivo?');
      if (!shouldRemove) {
        return;
      }
    }
    updateStoredPin(null);
    closePinModal();
    unlockInterface();
  };

  const handlePinSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!pinModalMode) {
      return;
    }

    const normalizedPin = sanitizePinInput(pinValue);
    const normalizedConfirmation = sanitizePinInput(pinConfirmation);
    setPinValue(normalizedPin);
    setPinConfirmation(normalizedConfirmation);

    if (normalizedPin.length !== PIN_LENGTH || normalizedConfirmation.length !== PIN_LENGTH) {
      setPinError('El PIN debe tener 4 dígitos.');
      return;
    }

    if (normalizedPin !== normalizedConfirmation) {
      setPinError('Los PIN no coinciden.');
      return;
    }

    try {
      if (pinModalMode === 'update') {
        if (!pinHash) {
          setPinError('No hay un PIN configurado para actualizar.');
          return;
        }
        const normalizedCurrent = sanitizePinInput(currentPin);
        setCurrentPin(normalizedCurrent);
        if (normalizedCurrent.length !== PIN_LENGTH) {
          setPinError('Debes ingresar el PIN actual de 4 dígitos.');
          return;
        }
        const currentHash = await sha256HexFromString(normalizedCurrent);
        if (currentHash !== pinHash) {
          setPinError('El PIN actual no es correcto.');
          setCurrentPin('');
          return;
        }
      }

      const hashed = await sha256HexFromString(normalizedPin);
      updateStoredPin(hashed);
      closePinModal();
      lockInterface();
    } catch (error) {
      console.error('No se pudo configurar el PIN de bloqueo.', error);
      setPinError('No se pudo guardar el PIN. Intenta nuevamente.');
    }
  };

  const handleUnlockSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!pinHash) {
      unlockInterface();
      return;
    }

    const normalized = sanitizePinInput(unlockPin);
    setUnlockPin(normalized);

    if (normalized.length !== PIN_LENGTH) {
      setUnlockError('Ingresa un PIN de 4 dígitos.');
      return;
    }

    try {
      const hashed = await sha256HexFromString(normalized);
      if (hashed !== pinHash) {
        setUnlockError('PIN incorrecto.');
        setUnlockPin('');
        return;
      }
      unlockInterface();
    } catch (error) {
      console.error('No se pudo validar el PIN de desbloqueo.', error);
      setUnlockError('No se pudo validar el PIN. Intenta de nuevo.');
    }
  };

  const handleUnlockChange = (value: string) => {
    setUnlockPin(sanitizePinInput(value));
    setUnlockError(null);
  };

  const handlePinValueChange = (value: string) => {
    setPinValue(sanitizePinInput(value));
    setPinError(null);
  };

  const handlePinConfirmationChange = (value: string) => {
    setPinConfirmation(sanitizePinInput(value));
    setPinError(null);
  };

  const handleCurrentPinChange = (value: string) => {
    setCurrentPin(sanitizePinInput(value));
    setPinError(null);
  };

  const hasAssignments = assignments.length > 0;

  return (
    <div className="hostess-page">
      {isLocked && pinHash && (
        <div className="hostess-lock-overlay">
          <div className="hostess-lock-card">
            <h2>Sesión bloqueada</h2>
            <p>Ingresa el PIN de 4 dígitos para continuar escaneando tickets.</p>
            <form className="hostess-lock-card__form" onSubmit={handleUnlockSubmit}>
              <label htmlFor="hostess-lock-pin">PIN</label>
              <input
                id="hostess-lock-pin"
                type="password"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={PIN_LENGTH}
                value={unlockPin}
                onChange={(event) => handleUnlockChange(event.target.value)}
                autoFocus
              />
              {unlockError && <p className="hostess-lock-card__error">{unlockError}</p>}
              <button type="submit" className="hostess-lock-card__primary">
                Desbloquear
              </button>
            </form>
          </div>
        </div>
      )}

      {pinModalMode && (
        <div className="hostess-lock-overlay hostess-lock-overlay--light">
          <div className="hostess-lock-card">
            <h2>{pinModalMode === 'set' ? 'Configurar PIN de bloqueo' : 'Actualizar PIN de bloqueo'}</h2>
            <p>Define un PIN de 4 dígitos para proteger la aplicación cuando se comparte el dispositivo.</p>
            <form className="hostess-lock-card__form" onSubmit={handlePinSubmit}>
              {pinModalMode === 'update' && (
                <>
                  <label htmlFor="hostess-current-pin">PIN actual</label>
                  <input
                    id="hostess-current-pin"
                    type="password"
                    inputMode="numeric"
                    pattern="[0-9]*"
                    maxLength={PIN_LENGTH}
                    value={currentPin}
                    onChange={(event) => handleCurrentPinChange(event.target.value)}
                    autoFocus
                  />
                </>
              )}
              <label htmlFor="hostess-new-pin">Nuevo PIN</label>
              <input
                id="hostess-new-pin"
                type="password"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={PIN_LENGTH}
                value={pinValue}
                onChange={(event) => handlePinValueChange(event.target.value)}
                autoFocus={pinModalMode === 'set'}
              />
              <label htmlFor="hostess-confirm-pin">Confirmar PIN</label>
              <input
                id="hostess-confirm-pin"
                type="password"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={PIN_LENGTH}
                value={pinConfirmation}
                onChange={(event) => handlePinConfirmationChange(event.target.value)}
              />
              {pinError && <p className="hostess-lock-card__error">{pinError}</p>}
              <div className="hostess-lock-card__actions">
                <button type="submit" className="hostess-lock-card__primary">
                  Guardar PIN
                </button>
                <button type="button" className="hostess-lock-card__secondary" onClick={closePinModal}>
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <div className={`hostess-content${isLocked ? ' hostess-content--blurred' : ''}`}>
        <header>
          <h1>Panel de hostess</h1>
          <p>Selecciona el evento, venue y punto de control donde estarás trabajando.</p>
        </header>

        <section className="device-section">
          <h2>Registro de dispositivo</h2>
          {deviceLoading && <p>Registrando dispositivo...</p>}
          {device && !deviceLoading && (
            <div className="device-card">
              <p>
                <strong>Nombre:</strong> {device.name}
              </p>
              <p>
                <strong>Plataforma:</strong> {device.platform}
              </p>
              <p>
                <strong>Última actividad:</strong> {device.last_seen_at ?? 'N/D'}
              </p>
            </div>
          )}
          {deviceError && <p className="error">{deviceError}</p>}
          <button type="button" onClick={() => handleDeviceRegistration()} disabled={deviceLoading}>
            {device ? 'Actualizar registro' : 'Registrar dispositivo'}
          </button>

          <div className="lock-controls">
            <h3>Bloqueo con PIN</h3>
            {pinHash ? (
              <>
                <p>El bloqueo por PIN está activo para este dispositivo compartido.</p>
                <div className="lock-controls__actions">
                  <button type="button" onClick={lockInterface}>
                    Bloquear ahora
                  </button>
                  <button type="button" onClick={() => openPinModal('update')}>
                    Actualizar PIN
                  </button>
                  <button type="button" onClick={handleRemovePin}>
                    Eliminar PIN
                  </button>
                </div>
              </>
            ) : (
              <>
                <p>Configura un PIN de 4 dígitos para proteger los datos cuando compartes el dispositivo.</p>
                <button type="button" onClick={() => openPinModal('set')}>
                  Configurar PIN
                </button>
              </>
            )}
          </div>
        </section>

        <section className="assignments-section">
        <h2>Asignaciones activas</h2>
        {assignmentsLoading && <p>Cargando asignaciones...</p>}
        {assignmentsError && <p className="error">{assignmentsError}</p>}
        {!assignmentsLoading && !assignmentsError && !hasAssignments && (
          <p>No cuentas con asignaciones activas en este momento.</p>
        )}

        {hasAssignments && (
          <div className="selectors">
            <label>
              Evento
              <select value={currentEvent?.id ?? ''} onChange={handleEventChange}>
                <option value="">Selecciona un evento</option>
                {events.map((event) => (
                  <option key={event.id} value={event.id}>
                    {event.name}
                  </option>
                ))}
              </select>
            </label>

            <label>
              Venue
              <select value={currentVenue?.id ?? ''} onChange={handleVenueChange} disabled={!currentEvent}>
                <option value="">Todos los venues</option>
                {venues.map((venue) => (
                  <option key={venue.id} value={venue.id}>
                    {venue.name}
                  </option>
                ))}
              </select>
            </label>

            <label>
              Punto de control
              <select
                value={currentCheckpoint?.id ?? ''}
                onChange={handleCheckpointChange}
                disabled={!currentEvent}
              >
                <option value="">Todos los checkpoints</option>
                {checkpoints.map((checkpoint) => (
                  <option key={checkpoint.id} value={checkpoint.id}>
                    {checkpoint.name}
                  </option>
                ))}
              </select>
            </label>
          </div>
        )}

        {currentEvent && (
          <div className="current-selection">
            <h3>Selección actual</h3>
            <ul>
              <li>
                <strong>Evento:</strong> {currentEvent.name}
              </li>
              <li>
                <strong>Venue:</strong> {currentVenue ? currentVenue.name : 'Todos'}
              </li>
              <li>
                <strong>Checkpoint:</strong> {currentCheckpoint ? currentCheckpoint.name : 'Todos'}
              </li>
            </ul>
          </div>
        )}
        </section>

        <section className="scanner-section">
        <h2>Escaneo de tickets</h2>
        {duplicateBanner && (
          <div className="sync-banner sync-banner--warning">
            <span>{duplicateBanner}</span>
            <button type="button" onClick={dismissDuplicateBanner}>
              Cerrar
            </button>
          </div>
        )}
        {!device && <p>Registra este dispositivo para habilitar el escaneo.</p>}
        {!currentEvent && <p>Selecciona un evento para comenzar a escanear.</p>}
        {device && currentEvent && !isLocked && (
          <>
            <QrScanner
              eventId={currentEvent.id}
              checkpointId={currentCheckpoint?.id ?? null}
              deviceId={device.id}
            />
            <ScanHistory history={attendanceHistory} pendingCount={pendingQueueCount} />
          </>
        )}
        {device && !currentEvent && !isLocked && (
          <ScanHistory history={attendanceHistory} pendingCount={pendingQueueCount} />
        )}
        </section>
      </div>

      <EventTotalsPanel
        eventId={currentEvent?.id ?? null}
        eventName={currentEvent?.name ?? null}
        checkpointId={currentCheckpoint?.id ?? null}
        checkpointName={checkpointDisplayName}
      />
    </div>
  );
};

export default Hostess;
