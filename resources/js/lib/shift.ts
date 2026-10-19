export function shiftMoney(value: string | null, currency: string): string {
    const [whole = '0', fraction = '00'] = (value ?? '0.00').split('.');
    return `${currency} ${BigInt(whole || '0').toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
export function shiftDate(value: string | null, timezone: string): string {
    return value
        ? new Intl.DateTimeFormat('en-GB', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: timezone,
          }).format(new Date(value))
        : '—';
}
export function cashCents(value: string): bigint | null {
    if (!/^\d+(\.\d{0,2})?$/.test(value)) {
        return null;
    }
    const [whole = '0', fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}
export function signedCents(value: bigint): string {
    const absolute = value < 0n ? -value : value;
    return `${value < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
}
