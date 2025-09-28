import { ChangeEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { fetchHostessAssignments, registerHostessDevice } from '../api/hostess';
import { useHostessStore } from '../hostess/store';
import type { HostessCheckpoint, HostessEvent, HostessVenue } from '../hostess/types';
import { extractApiErrorMessage } from '../utils/apiErrors';
import {
  generateDeviceFingerprint,
  resolveDeviceName,
  resolveDevicePlatform,
} from '../utils/fingerprint';

const Hostess = () => {
  const [assignmentsLoading, setAssignmentsLoading] = useState(false);
  const [assignmentsError, setAssignmentsError] = useState<string | null>(null);
  const [deviceLoading, setDeviceLoading] = useState(false);
  const [deviceError, setDeviceError] = useState<string | null>(null);

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

      const desiredEventId = currentEvent?.id ?? data[0].event?.id ?? data[0].event_id;
      selectEvent(desiredEventId ?? null);
    } catch (error) {
      setAssignmentsError(extractApiErrorMessage(error, 'No fue posible cargar tus asignaciones.'));
    } finally {
      setAssignmentsLoading(false);
    }
  }, [currentEvent?.id, selectEvent, setAssignments]);

  const handleDeviceRegistration = useCallback(async () => {
    setDeviceLoading(true);
    setDeviceError(null);
    try {
      const fingerprint = await generateDeviceFingerprint();
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

  const hasAssignments = assignments.length > 0;

  return (
    <div className="hostess-page">
      <header>
        <h1>Panel de hostess</h1>
        <p>Selecciona el evento, venue y punto de control donde estarás trabajando.</p>
      </header>

      <section className="device-section">
        <h2>Registro de dispositivo</h2>
        {deviceLoading && <p>Registrando dispositivo...</p>}
        {device && !deviceLoading && (
          <div className="device-card">
            <p><strong>Nombre:</strong> {device.name}</p>
            <p><strong>Plataforma:</strong> {device.platform}</p>
            <p><strong>Última actividad:</strong> {device.last_seen_at ?? 'N/D'}</p>
          </div>
        )}
        {deviceError && <p className="error">{deviceError}</p>}
        <button type="button" onClick={() => handleDeviceRegistration()} disabled={deviceLoading}>
          {device ? 'Actualizar registro' : 'Registrar dispositivo'}
        </button>
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
    </div>
  );
};

export default Hostess;
