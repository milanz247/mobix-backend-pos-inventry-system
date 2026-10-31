export type CompletedSale = {
    id: number;
    sale_number: string;
    customer: string;
    total: string;
    paid_total: string;
    outstanding: string;
    cash_tendered: string | null;
    change_given: string;
};
