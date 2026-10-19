export type ShiftSummary = {
    opened_at?: string;
    closed_at?: string;
    opening_cash: string;
    cash_sales: string;
    cash_refunds: string;
    payments: Record<string, string>;
    refunds: Record<string, string>;
    movements: Record<string, string>;
    expected_cash: string;
    sales_count: number;
    identity?: {
        business: string;
        currency: string;
        timezone: string;
        location: string;
        location_code: string;
        cashier: string;
        closed_by: string;
    };
};

export type PosDrawer = {
    location_id: number;
    shift: {
        id: number;
        reference: string;
        opening_cash: string;
        opened_at: string;
        status: 'open';
    } | null;
    summary: ShiftSummary | null;
    other_location: { id: number; name: string } | null;
    permissions: { open: boolean; movements: boolean; close: boolean };
};
export type Shift = {
    id: number;
    reference: string;
    status: 'open' | 'closed' | 'pending_approval' | 'approved';
    opening_cash: string;
    opening_note: string | null;
    opened_at: string;
    closed_at: string | null;
    actual_cash: string | null;
    expected_cash: string | null;
    variance: string | null;
    variance_reason: string | null;
    closing_note: string | null;
    approver_name: string | null;
    approved_at: string | null;
    cashier: { id: number; name: string };
    location: { id: number; name: string };
};
export type PageLink = { url: string | null; label: string; active: boolean };
export type Paginated<T> = { data: T[]; links: PageLink[]; total: number };
export type Movement = {
    reversal_exists: boolean;
    id: number;
    reference: string;
    type: string;
    amount: string;
    reason: string;
    supporting_reference: string | null;
    reverses_id: number | null;
    created_at: string;
    creator: { name: string };
};
export type ShiftDetails = {
    shift: Shift;
    summary: ShiftSummary;
    currency: string;
    timezone: string;
    movements: Paginated<Movement>;
    events: Paginated<{
        id: number;
        type: string;
        created_at: string;
        actor: { name: string };
        details: Record<string, unknown>;
    }>;
    canManage: boolean;
    canApprove: boolean;
    canCorrect: boolean;
    generatedAt: string;
};
