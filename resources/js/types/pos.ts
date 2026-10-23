// Mirrors the server presenters (App\Http\Presenters). Hand-maintained.

export type ItemStatus =
    | 'pending'
    | 'new'
    | 'preparing'
    | 'ready'
    | 'voided'
    | 'rejected';

export type OrderItem = {
    id: number;
    name: string;
    qty: number;
    unit_price: number;
    line_total: number;
    modifiers: string[];
    note: string | null;
    status: ItemStatus;
    comp_type: 'complimentary' | 'staff_meal' | null;
    void_reason: string | null;
};

export type Round = {
    id: number;
    source: 'pos' | 'qr';
    status: 'pending' | 'confirmed' | 'rejected';
    customer_name: string | null;
    reject_reason: string | null;
    by: string | null;
    confirmed_by: string | null;
    created_at: string;
    items: OrderItem[];
};

export type Bill = {
    id: number;
    public_id: string;
    label: string | null;
    mode: 'item' | 'equal' | 'amount' | null;
    is_unallocated: boolean;
    subtotal: number;
    discount_total: number;
    charge_total: number;
    rounding: number;
    grand_total: number;
    paid_total: number;
    remaining: number;
    status: 'unpaid' | 'paid' | 'void';
    items: { order_item_id: number; qty: number; line_total: number }[];
};

export type Payment = {
    id: number;
    bill_id: number;
    method: string;
    method_type: 'cash' | 'qris' | 'transfer';
    amount: number;
    tendered: number;
    change: number;
    reference: string | null;
    refunded_total: number;
    by: string | null;
    created_at: string;
};

export type Order = {
    id: number;
    public_id: string;
    number: string;
    type: 'dine_in' | 'takeaway' | 'online';
    platform: string | null;
    status: 'open' | 'partially_paid' | 'paid' | 'cancelled' | 'refunded';
    status_label: string;
    table: { id: number; name: string; area: string | null } | null;
    customer_name: string | null;
    call_number: string | null;
    cashier: string | null;
    bill_requested_by: string | null;
    subtotal: number;
    discount_total: number;
    charge_total: number;
    rounding: number;
    grand_total: number;
    paid_total: number;
    refunded_total: number;
    remaining: number;
    bill_requested: boolean;
    is_editable: boolean;
    rounds: Round[];
    charges: {
        name: string;
        rate: number;
        amount: number;
        is_inclusive: boolean;
    }[];
    discounts: {
        id: number;
        item_id: number | null;
        type: 'percent' | 'fixed' | 'promo';
        value: number;
        amount: number;
        reason: string;
    }[];
    bills: Bill[];
    payments: Payment[];
};

export type Modifier = { id: number; name: string; price_delta: number };
export type ModifierGroup = {
    id: number;
    name: string;
    min: number;
    max: number;
    required: boolean;
    modifiers: Modifier[];
};
export type Variant = { id: number; name: string; price: number };

export type Product = {
    id: number;
    name: string;
    sku: string | null;
    description: string | null;
    price: number;
    image: string | null;
    is_available: boolean;
    stock_qty: number | null;
    variants: Variant[];
    modifier_groups: ModifierGroup[];
};

export type Category = { id: number; name: string; products: Product[] };

export type FloorTable = {
    id: number;
    public_id: string;
    name: string;
    capacity: number;
    status: 'empty' | 'occupied' | 'awaiting_payment';
    qr_enabled: boolean;
    order: {
        id: number;
        number: string;
        grand_total: number;
        bill_requested: boolean;
        opened_at: string | null;
    } | null;
};

export type Floor = { id: number; name: string; tables: FloorTable[] }[];

export type PendingQr = Round & {
    order_id: number;
    table: string | null;
    table_id: number | null;
};

export type PosState = {
    floor: Floor;
    pending_qr: PendingQr[];
    recent_qr: {
        id: number;
        table: string | null;
        customer_name: string | null;
    }[];
    ready_items: {
        id: number;
        name: string;
        qty: number;
        table: string;
        order_id: number;
    }[];
    takeaway_orders: {
        id: number;
        number: string;
        type: string;
        call_number: string | null;
        platform: string | null;
        grand_total: number;
        status: string;
    }[];
    sold_out: number[];
};

export type PaymentMethod = {
    id: number;
    name: string;
    type: 'cash' | 'qris' | 'transfer';
};

export type CartLine = {
    key: string;
    product: Product;
    variantId: number | null;
    modifierIds: number[];
    qty: number;
    note: string;
    unitPrice: number;
    label: string;
};

export type Receipt = {
    title?: string | null;
    copy: boolean;
    outlet: {
        name: string | null;
        address: string | null;
        npwp: string | null;
    };
    header: string | null;
    show_link: boolean;
    order_number: string;
    label: string | null;
    is_split: boolean;
    date: string;
    cashier: string | null;
    table: string | null;
    call_number: string | null;
    items: {
        name: string;
        qty: number;
        unit_price: number;
        line_total: number;
        modifiers: string[];
    }[];
    subtotal: number;
    discount_total: number;
    charges: { name: string; amount: number; is_inclusive: boolean }[];
    rounding: number;
    grand_total: number;
    payments: {
        method: string;
        amount: number;
        tendered: number;
        change: number;
    }[];
    change: number;
    footer: string | null;
    public_url: string;
};
