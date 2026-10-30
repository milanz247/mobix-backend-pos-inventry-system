export type DamageSummary = {
    skus: number;
    locations: number;
    quantity: string;
    devices: number;
    value: string | null;
};
export type DamageRow = {
    id: string;
    product: string;
    variant: string;
    sku: string;
    barcode: string | null;
    model_number: string | null;
    category: string;
    brand: string | null;
    location: string;
    unit: string;
    tracking_mode: string;
    quantity: string;
    devices: number;
    value: string | null;
    last_damage_at: string;
};
export type DamageGroup = {
    name: string;
    skus: number;
    quantity: string;
    devices: number;
    value: string | null;
};
export function damageQuantity(value: string): string {
    return value.includes('.')
        ? value.replace(/0+$/, '').replace(/\.$/, '')
        : value;
}
export function damageMoney(value: string, currency: string): string {
    const [whole = '0', fraction = '00'] = value.split('.');
    return `${currency} ${BigInt(whole).toLocaleString()}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
