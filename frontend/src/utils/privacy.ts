const EMAIL_REGEX = /([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g;
const PHONE_REGEX = /\+?\d[\d\s\-]{4,}\d/g;

function maskEmail(value: string): string {
  return value.replace(EMAIL_REGEX, (_, localPart: string) => `${localPart}@***`);
}

function maskPhone(value: string): string {
  return value.replace(PHONE_REGEX, (match: string) => {
    const digits = match.replace(/\D/g, '');
    if (digits.length <= 2) {
      return '***';
    }
    const lastTwo = digits.slice(-2);
    const prefix = match.trim().startsWith('+') ? '+' : '';
    return `${prefix}••••••${lastTwo}`;
  });
}

export function maskSensitiveText(value: string): string {
  if (!value) {
    return value;
  }
  const maskedEmail = maskEmail(value);
  return maskPhone(maskedEmail);
}
