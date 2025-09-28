export interface HostessEvent {
  id: string;
  name: string;
  start_at?: string | null;
  end_at?: string | null;
}

export interface HostessVenue {
  id: string;
  name: string;
}

export interface HostessCheckpoint {
  id: string;
  name: string;
}

export interface HostessAssignment {
  id: string;
  tenant_id: string;
  hostess_user_id?: string;
  event_id: string;
  venue_id: string | null;
  checkpoint_id: string | null;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
  event: HostessEvent | null;
  venue: HostessVenue | null;
  checkpoint: HostessCheckpoint | null;
}

export interface HostessDevice {
  id: string;
  tenant_id: string;
  name: string;
  platform: string;
  fingerprint: string;
  last_seen_at: string | null;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}
