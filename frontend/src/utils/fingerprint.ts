import nacl from 'tweetnacl';
import { base64ToUint8Array, sha256HexFromString, uint8ArrayToBase64 } from './crypto';

function getEncryptionKey(): Uint8Array {
  const key = import.meta.env.VITE_FINGERPRINT_ENCRYPTION_KEY as string | undefined;
  if (!key) {
    throw new Error('No se configuró la clave de cifrado para el fingerprint.');
  }

  const bytes = base64ToUint8Array(key);
  if (bytes.length !== nacl.secretbox.keyLength) {
    throw new Error('La clave de cifrado del fingerprint es inválida.');
  }
  return bytes;
}

function drawFingerprintCanvas(userAgent: string): string {
  try {
    const canvas = document.createElement('canvas');
    canvas.width = 200;
    canvas.height = 50;
    const context = canvas.getContext('2d');
    if (!context) {
      return 'no-canvas';
    }

    context.textBaseline = 'top';
    context.font = "16px 'Arial'";
    context.fillStyle = '#f60';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#069';
    context.fillText(userAgent, 4, 4);
    context.fillStyle = 'rgba(102, 204, 0, 0.7)';
    context.fillText(userAgent, 8, 18);

    return canvas.toDataURL();
  } catch {
    return 'no-canvas';
  }
}

export async function generateDeviceFingerprint(): Promise<string> {
  const userAgent = typeof navigator !== 'undefined' ? navigator.userAgent : 'unknown';
  const timeZone =
    typeof Intl !== 'undefined' && typeof Intl.DateTimeFormat === 'function'
      ? Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'unknown'
      : 'unknown';

  const canvasData = typeof document !== 'undefined' ? drawFingerprintCanvas(userAgent) : 'no-document';
  const rawFingerprint = `${userAgent}|${timeZone}|${canvasData}`;
  return sha256HexFromString(rawFingerprint);
}

export async function encryptFingerprintPayload(value: string): Promise<string> {
  const key = getEncryptionKey();
  const nonce = nacl.randomBytes(nacl.secretbox.nonceLength);
  const message = new TextEncoder().encode(value);
  const encrypted = nacl.secretbox(message, nonce, key);

  const payload = new Uint8Array(nonce.length + encrypted.length);
  payload.set(nonce, 0);
  payload.set(encrypted, nonce.length);

  return uint8ArrayToBase64(payload);
}

export async function generateEncryptedFingerprint(): Promise<string> {
  const fingerprint = await generateDeviceFingerprint();
  return encryptFingerprintPayload(fingerprint);
}

export function resolveDeviceName(): string {
  if (typeof navigator === 'undefined') {
    return 'Unknown device';
  }

  const userAgentData = (navigator as Navigator & { userAgentData?: { brands?: { brand: string; version: string }[] } })
    .userAgentData;

  if (userAgentData?.brands && userAgentData.brands.length > 0) {
    return userAgentData.brands.map((brand) => brand.brand).join(' ');
  }

  return navigator.userAgent ?? 'Unknown device';
}

export function resolveDevicePlatform(): string {
  if (typeof navigator === 'undefined') {
    return 'web';
  }

  const ua = navigator.userAgent.toLowerCase();
  const userAgentData = (navigator as Navigator & { userAgentData?: { platform?: string } }).userAgentData;
  const platformHint = userAgentData?.platform?.toLowerCase();

  if (platformHint === 'android' || ua.includes('android')) {
    return 'android';
  }

  if (
    platformHint === 'ios' ||
    ua.includes('iphone') ||
    ua.includes('ipad') ||
    ua.includes('ipod')
  ) {
    return 'ios';
  }

  return 'web';
}
