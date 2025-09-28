import { apiFetch } from './client';
import type { HostessAssignment, HostessDevice } from '../hostess/types';

interface AssignmentsResponse {
  data: HostessAssignment[];
}

interface DeviceRegisterPayload {
  fingerprint: string;
  name: string;
  platform: string;
}

interface DeviceResponse {
  data: HostessDevice;
}

export async function fetchHostessAssignments(): Promise<HostessAssignment[]> {
  const response = await apiFetch<AssignmentsResponse>('/me/assignments');
  return response.data;
}

export async function registerHostessDevice(payload: DeviceRegisterPayload): Promise<HostessDevice> {
  const response = await apiFetch<DeviceResponse>('/devices/register', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
  return response.data;
}
