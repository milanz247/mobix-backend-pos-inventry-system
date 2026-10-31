export type InvoiceSale = {
    id: number;
    sale_number: string;
    posted_at: string;
    due_date?: string | null;
    cashier: string;
    location: { name: string; code: string };
    customer: {
        name: string | null;
        phone: string | null;
        email: string | null;
    };
    subtotal: string;
    discount_total: string;
    tax_total: string;
    total: string;
    paid_total: string;
    returned_total: string;
    refund_total: string;
    lines: {
        is_replacement?: boolean;
        returned_quantity?: string;
        id: number;
        product_name: string;
        variant_name: string;
        sku: string;
        quantity: string;
        unit_price: string;
        discount_amount: string;
        total: string;
        imei: string | null;
        is_combo: boolean;
        components: {
            name: string;
            quantity: string;
            imei: string | null;
        }[];
    }[];
    returns?: {
        id: number;
        return_number: string;
        is_exchange: boolean;
        total: string;
        refund_total: string;
        processed_at: string;
        reason: string;
    }[];
    payments: {
        amount: string;
        cash_tendered: string | null;
        change_given: string;
        method: string;
        reference: string | null;
    }[];
};

export type InvoiceBusiness = {
    name: string;
    phone: string;
    email: string | null;
    address: string;
    city: string;
    logo_url: string | null;
    currency: string;
    invoice_footer: string | null;
    invoice_return_policy: string | null;
    invoice_title: string;
    invoice_paper_size: 'a4' | 'thermal';
    invoice_thermal_width: 58 | 80;
    invoice_accent_color: string;
    invoice_show_logo: boolean;
    invoice_show_business_details: boolean;
    invoice_show_customer: boolean;
    invoice_show_cashier: boolean;
    invoice_show_payment_details: boolean;
    invoice_show_tax: boolean;
    invoice_show_product_codes: boolean;
    invoice_show_line_discounts: boolean;
};
