export type Customer = {
    id: number;
    name: string;
    phone: string;
    email: string | null;
    credit_limit: string | null;
    credit_days: number;
    can_delete?: boolean;
};
