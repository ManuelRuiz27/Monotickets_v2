import { create } from 'zustand';
import type {
  HostessAssignment,
  HostessCheckpoint,
  HostessDevice,
  HostessEvent,
  HostessVenue,
} from './types';

interface HostessState {
  assignments: HostessAssignment[];
  currentEvent: HostessEvent | null;
  currentVenue: HostessVenue | null;
  currentCheckpoint: HostessCheckpoint | null;
  device: HostessDevice | null;
  setAssignments: (assignments: HostessAssignment[]) => void;
  selectEvent: (eventId: string | null) => void;
  selectVenue: (venueId: string | null) => void;
  selectCheckpoint: (checkpointId: string | null) => void;
  setDevice: (device: HostessDevice | null) => void;
}

function dedupeById<T extends { id: string }>(items: (T | null | undefined)[]): T[] {
  const map = new Map<string, T>();
  items.forEach((item) => {
    if (item && !map.has(item.id)) {
      map.set(item.id, item);
    }
  });
  return Array.from(map.values());
}

export const useHostessStore = create<HostessState>((set, get) => ({
  assignments: [],
  currentEvent: null,
  currentVenue: null,
  currentCheckpoint: null,
  device: null,
  setAssignments: (assignments) => {
    set({ assignments });
    const { currentEvent } = get();
    if (currentEvent) {
      const stillAvailable = assignments.some(
        (assignment) => (assignment.event?.id ?? assignment.event_id) === currentEvent.id
      );
      if (!stillAvailable) {
        set({ currentEvent: null, currentVenue: null, currentCheckpoint: null });
      }
    }
  },
  selectEvent: (eventId) => {
    if (!eventId) {
      set({ currentEvent: null, currentVenue: null, currentCheckpoint: null });
      return;
    }

    const { assignments, currentVenue, currentCheckpoint } = get();
    const eventAssignments = assignments.filter(
      (assignment) => (assignment.event?.id ?? assignment.event_id) === eventId
    );

    if (eventAssignments.length === 0) {
      set({ currentEvent: null, currentVenue: null, currentCheckpoint: null });
      return;
    }

    const event = eventAssignments[0].event ?? {
      id: eventAssignments[0].event_id,
      name: 'Evento',
      start_at: null,
      end_at: null,
    };

    const venues = dedupeById(eventAssignments.map((assignment) => assignment.venue));
    const resolvedVenue = venues.find((venue) => venue.id === currentVenue?.id) ?? venues[0] ?? null;

    const checkpointSource = resolvedVenue
      ? eventAssignments.filter((assignment) => assignment.venue?.id === resolvedVenue.id)
      : eventAssignments;
    const checkpoints = dedupeById(checkpointSource.map((assignment) => assignment.checkpoint));
    const resolvedCheckpoint =
      checkpoints.find((checkpoint) => checkpoint.id === currentCheckpoint?.id) ?? checkpoints[0] ?? null;

    set({
      currentEvent: event,
      currentVenue: resolvedVenue ?? null,
      currentCheckpoint: resolvedCheckpoint ?? null,
    });
  },
  selectVenue: (venueId) => {
    const { assignments, currentEvent, currentVenue, currentCheckpoint } = get();
    if (!currentEvent) {
      return;
    }

    if (!venueId) {
      const eventAssignments = assignments.filter(
        (assignment) => (assignment.event?.id ?? assignment.event_id) === currentEvent.id
      );
      const checkpoints = dedupeById(eventAssignments.map((assignment) => assignment.checkpoint));
      const resolvedCheckpoint =
        checkpoints.find((checkpoint) => checkpoint.id === currentCheckpoint?.id) ?? checkpoints[0] ?? null;

      set({ currentVenue: null, currentCheckpoint: resolvedCheckpoint ?? null });
      return;
    }

    const eventAssignments = assignments.filter(
      (assignment) => (assignment.event?.id ?? assignment.event_id) === currentEvent.id
    );
    const venueAssignments = eventAssignments.filter(
      (assignment) => assignment.venue?.id === venueId || assignment.venue_id === venueId
    );

    if (venueAssignments.length === 0) {
      set({ currentVenue, currentCheckpoint });
      return;
    }

    const venue = venueAssignments[0].venue ?? { id: venueId, name: 'Venue' };
    const checkpoints = dedupeById(venueAssignments.map((assignment) => assignment.checkpoint));
    const resolvedCheckpoint =
      checkpoints.find((checkpoint) => checkpoint.id === currentCheckpoint?.id) ?? checkpoints[0] ?? null;

    set({ currentVenue: venue, currentCheckpoint: resolvedCheckpoint ?? null });
  },
  selectCheckpoint: (checkpointId) => {
    const { assignments, currentEvent, currentVenue } = get();
    if (!currentEvent) {
      return;
    }

    if (!checkpointId) {
      set({ currentCheckpoint: null });
      return;
    }

    const eventAssignments = assignments.filter(
      (assignment) => (assignment.event?.id ?? assignment.event_id) === currentEvent.id
    );

    const relevantAssignments = currentVenue
      ? eventAssignments.filter(
          (assignment) => assignment.venue?.id === currentVenue.id || assignment.venue_id === currentVenue.id
        )
      : eventAssignments;

    const checkpoint = relevantAssignments
      .map((assignment) => assignment.checkpoint)
      .find((item) => item?.id === checkpointId);

    if (!checkpoint) {
      return;
    }

    set({ currentCheckpoint: checkpoint });
  },
  setDevice: (device) => set({ device }),
}));
