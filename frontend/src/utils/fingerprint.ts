function bufferToHex(buffer: ArrayBuffer): string {
  return Array.from(new Uint8Array(buffer))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
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
  const encoded = new TextEncoder().encode(rawFingerprint);
  const hashBuffer = await crypto.subtle.digest('SHA-256', encoded);
  return bufferToHex(hashBuffer);
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
    return 'unknown';
  }

  const userAgentData = (navigator as Navigator & { userAgentData?: { platform?: string } }).userAgentData;

  if (userAgentData?.platform) {
    return userAgentData.platform;
  }

  return navigator.platform ?? 'unknown';
}
