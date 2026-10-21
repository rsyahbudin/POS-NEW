import { Head, Link, router } from '@inertiajs/react';
import {
    Bell,
    ChevronUp,
    Clock,
    Settings2,
    ShoppingBag,
    UtensilsCrossed,
    Volume2,
    VolumeX,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { DiscountDialog } from '@/components/pos/discount-dialog';
import { ModifierSheet } from '@/components/pos/modifier-sheet';
import { Modal } from '@/components/pos/modal';
import { MoveDialog } from '@/components/pos/move-dialog';
import { OrderPanel } from '@/components/pos/order-panel';
import type { PanelActions, PickState } from '@/components/pos/order-panel';
import { PaymentDialog } from '@/components/pos/payment-dialog';
import { usePinGate } from '@/components/pos/pin-gate';
import { ProductGrid } from '@/components/pos/product-grid';
import { QrInbox } from '@/components/pos/qr-inbox';
import { ReasonDialog } from '@/components/pos/reason-dialog';
import { ReceiptView } from '@/components/pos/receipt-view';
import { SplitDialog } from '@/components/pos/split-dialog';
import { TableMap } from '@/components/pos/table-map';
import { Field, MoneyInput, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useRealtime } from '@/hooks/use-realtime';
import { useSound } from '@/hooks/use-sound';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { rupiah } from '@/lib/format';
import {
    bluetoothPrinterName,
    bluetoothSupported,
    getPrinterPrefs,
    listPrinters,
    pairBluetoothPrinter,
    printTest,
    printReceipt,
    savePrinterPrefs,
} from '@/lib/print';
import type { PrinterPrefs } from '@/lib/print';
import { cn } from '@/lib/utils';
import type {
    Bill,
    CartLine,
    Category,
    FloorTable,
    Order,
    OrderItem,
    Payment,
    PaymentMethod,
    PosState,
    Product,
    Receipt,
} from '@/types/pos';

type Props = {
    menu: Category[];
    paymentMethods: PaymentMethod[];
    shift: {
        id: number;
        opened_at: string;
        mine: boolean;
        owner: string | null;
    } | null;
    suggestedOpening: number;
    can: {
        pay: boolean;
        void: boolean;
        refund: boolean;
        discount: boolean;
        confirm_qr: boolean;
    };
    settings: {
        discount_limit_percent: number;
        qr_alert_seconds: number;
        quick_cash: number[];
        kds_enabled: boolean;
        outlet_id: number;
    };
    state: PosState;
};

type Ctx = {
    type: 'dine_in' | 'takeaway' | 'online';
    table: FloorTable | null;
    platform: string;
};

type Dialog =
    | { kind: 'pay'; bill: Bill }
    | { kind: 'split' }
    | { kind: 'discount'; item: OrderItem | null }
    | { kind: 'void-item'; item: OrderItem }
    | { kind: 'comp'; item: OrderItem }
    | { kind: 'void-order' }
    | { kind: 'refund'; payment: Payment }
    | { kind: 'move' }
    | { kind: 'allocate'; bill: Bill }
    | { kind: 'receipt'; receipt: Receipt; note?: string }
    | { kind: 'online' }
    | { kind: 'printer' }
    | { kind: 'shift' }
    | null;

export default function PosPage(props: Props) {
    const { menu, paymentMethods, can, settings } = props;
    const [state, setState] = useState<PosState>(props.state);
    const [ctx, setCtx] = useState<Ctx | null>(null);
    const [order, setOrder] = useState<Order | null>(null);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [view, setView] = useState<'tables' | 'menu'>('tables');
    const [picking, setPicking] = useState<Product | null>(null);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [inbox, setInbox] = useState(false);
    const [sheet, setSheet] = useState(false);
    const [sending, setSending] = useState(false);
    const [markMode, setMarkMode] = useState(false);
    const pin = usePinGate();
    const sound = useSound();
    const alerted = useRef<Record<number, number>>({});
    const pendingCount = state.pending_qr.length;

    // One notification per QR round, whichever arrives first: WebSocket event or the polled state.
    const seenQr = useRef(
        new Set<number>([
            ...props.state.pending_qr.map((r) => r.id),
            ...props.state.recent_qr.map((r) => r.id),
        ]),
    );
    const notifyQr = useCallback(
        (
            roundId: number,
            table: string | null,
            who: string | null,
            auto: boolean,
        ) => {
            if (seenQr.current.has(roundId)) {
                return;
            }

            seenQr.current.add(roundId);
            sound.beep('order');
            toast(
                `${auto ? t('Pesanan QR masuk dapur') : t('Pesanan QR baru')}: ${table ?? ''} · ${who ?? ''}`,
                { duration: 10000 },
            );

            // Auto-confirmed orders need no action, so the inbox stays closed.
            if (!auto) {
                setInbox(true);
            }
        },
        [sound],
    );

    const refresh = useCallback(async () => {
        try {
            setState(await api<PosState>('GET', '/pos/state'));
        } catch {
            // transient; polling/realtime will retry
        }
    }, []);

    const reloadOrder = useCallback(async (id: number) => {
        try {
            setOrder(
                (await api<{ order: Order }>('GET', `/pos/orders/${id}`)).order,
            );
        } catch {
            // ignore
        }
    }, []);

    const orderRef = useRef<Order | null>(null);
    useEffect(() => {
        orderRef.current = order;
    }, [order]);

    const { online } = useRealtime(
        [
            {
                channel: `outlet.${settings.outlet_id}.orders`,
                events: [
                    'QrOrderSubmitted',
                    'OrderRoundConfirmed',
                    'OrderItemStatusChanged',
                    'OrderPaid',
                    'ProductAvailabilityChanged',
                ],
            },
            {
                channel: `outlet.${settings.outlet_id}.menu`,
                private: false,
                events: ['ProductAvailabilityChanged'],
            },
        ],
        (event, payload) => {
            const p = payload as {
                orderId?: number;
                customerName?: string;
                tableName?: string;
                label?: string;
                status?: string;
                autoConfirmed?: boolean;
                roundId?: number;
            };
            void refresh();

            if (p.orderId && orderRef.current?.id === p.orderId) {
                void reloadOrder(p.orderId);
            }

            if (event === 'QrOrderSubmitted' && p.roundId) {
                notifyQr(
                    p.roundId,
                    p.tableName ?? null,
                    p.customerName ?? null,
                    !!p.autoConfirmed,
                );
            }

            if (event === 'OrderItemStatusChanged' && p.status === 'ready') {
                sound.beep('ready');
                toast.success(p.label || t('Item siap diantar'));
            }
        },
        refresh,
    );

    // Fallback when the WebSocket is down: new rounds show up in the polled state.
    useEffect(() => {
        state.pending_qr.forEach((r) =>
            notifyQr(r.id, r.table, r.customer_name, false),
        );
        state.recent_qr.forEach((r) =>
            notifyQr(r.id, r.table, r.customer_name, true),
        );
    }, [state.pending_qr, state.recent_qr, notifyQr]);

    // Re-alert (sound + toast) for QR orders left unconfirmed longer than the configured time.
    useEffect(() => {
        const id = window.setInterval(() => {
            const now = Date.now();

            for (const r of state.pending_qr) {
                const waited = (now - new Date(r.created_at).getTime()) / 1000;
                const last =
                    alerted.current[r.id] ?? new Date(r.created_at).getTime();

                if (
                    waited >= settings.qr_alert_seconds &&
                    now - last >= settings.qr_alert_seconds * 1000
                ) {
                    alerted.current[r.id] = now;
                    sound.beep('alert');
                    toast.warning(
                        `${t('QR belum dikonfirmasi')}: ${r.table ?? ''} · ${r.customer_name}`,
                    );
                }
            }
        }, 10000);

        return () => window.clearInterval(id);
    }, [state.pending_qr, settings.qr_alert_seconds, sound]);

    const soldOut = state.sold_out;

    const selectTable = (tb: FloorTable) => {
        setCtx({ type: 'dine_in', table: tb, platform: '' });
        setCart([]);
        setOrder(null);

        if (tb.order) {
            void reloadOrder(tb.order.id);
        }

        setView('menu');
    };

    const openTakeaway = (
        type: 'takeaway' | 'online' | 'dine_in',
        platform = '',
    ) => {
        setCtx({ type, table: null, platform });
        setCart([]);
        setOrder(null);
        setView('menu');
    };

    const openExisting = async (id: number) => {
        await reloadOrder(id);
        setCtx({ type: 'takeaway', table: null, platform: '' });
        setView('menu');
    };

    const pick = async (p: Product) => {
        if (markMode) {
            try {
                const out = p.is_available && !soldOut.includes(p.id);
                await api('POST', `/pos/products/${p.id}/availability`, {
                    is_available: !out,
                });
                toast.success(
                    `${p.name}: ${out ? t('ditandai habis') : t('tersedia lagi')}`,
                );
                void refresh();
                router.reload({ only: ['menu'] });
            } catch (e) {
                toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
            }

            return;
        }

        if (!ctx) {
            toast.info(t('Pilih meja atau tipe order dulu.'));
            setView('tables');

            return;
        }

        if (
            p.variants.length > 0 ||
            p.modifier_groups.some((g) => g.min > 0 || g.modifiers.length > 0)
        ) {
            setPicking(p);

            return;
        }

        addLine({
            key: crypto.randomUUID(),
            product: p,
            variantId: null,
            modifierIds: [],
            qty: 1,
            note: '',
            unitPrice: p.price,
            label: p.name,
        });
    };

    const addLine = (line: CartLine) =>
        setCart((c) => {
            const same = c.find(
                (x) =>
                    x.product.id === line.product.id &&
                    x.variantId === line.variantId &&
                    x.note === line.note &&
                    [...x.modifierIds].sort((a, b) => a - b).join() ===
                        [...line.modifierIds].sort((a, b) => a - b).join(),
            );

            return same
                ? c.map((x) =>
                      x === same
                          ? { ...x, qty: Math.min(99, x.qty + line.qty) }
                          : x,
                  )
                : [...c, line];
        });

    const send = async (): Promise<Order | null> => {
        if (!ctx || cart.length === 0 || sending) {
            return null;
        }

        setSending(true);

        try {
            const res = await api<{ order: Order }>('POST', '/pos/orders', {
                type: ctx.type,
                table_id: ctx.table?.id ?? null,
                order_id: order?.id ?? null,
                counter: ctx.type === 'dine_in' && !ctx.table,
                platform: ctx.platform || null,
                items: cart.map((l) => ({
                    product_id: l.product.id,
                    variant_id: l.variantId,
                    modifier_ids: l.modifierIds,
                    qty: l.qty,
                    note: l.note || null,
                })),
            });
            setOrder(res.order);
            setCart([]);
            toast.success(
                res.order.call_number
                    ? `${t('Terkirim')} · ${t('Nomor panggil')} ${res.order.call_number}`
                    : t('Terkirim ke dapur.'),
            );
            setSheet(false);
            void refresh();

            return res.order;
        } catch (e) {
            toast.error(
                e instanceof ApiError
                    ? e.message
                    : t('Gagal mengirim pesanan.'),
            );

            return null;
        } finally {
            setSending(false);
        }
    };

    /** Walk-in at the counter: order goes to the kitchen and the payment screen opens at once. */
    const sendAndPay = async () => {
        const placed = await send();
        const bill = placed?.bills.find(
            (b) => b.status === 'unpaid' && !b.is_unallocated,
        );

        if (placed && bill) {
            setDialog({ kind: 'pay', bill });
        }
    };

    const apply = (o: Order, message?: string) => {
        setOrder(o);
        void refresh();

        if (message) {
            toast.success(message);
        }
    };

    const guard = async <T,>(fn: () => Promise<T>): Promise<T | undefined> => {
        try {
            return await fn();
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));

            return undefined;
        }
    };

    const showReceipt = async (bill: Bill, copy: boolean) => {
        const r = await guard(() =>
            copy
                ? api<{ receipt: Receipt }>(
                      'POST',
                      `/pos/bills/${bill.id}/reprint`,
                  )
                : api<{ receipt: Receipt }>(
                      'GET',
                      `/pos/bills/${bill.id}/receipt`,
                  ),
        );

        if (r) {
            const how = await printReceipt(r.receipt);
            setDialog({
                kind: 'receipt',
                receipt: r.receipt,
                note: how === 'qz' ? t('Struk dikirim ke printer.') : undefined,
            });
        }
    };

    const actions: PanelActions = {
        send: () => void send(),
        sendAndPay: () => void sendAndPay(),
        sending,
        setQty: (key, d) =>
            setCart((c) =>
                c.map((l) =>
                    l.key === key
                        ? { ...l, qty: Math.max(1, Math.min(99, l.qty + d)) }
                        : l,
                ),
            ),
        remove: (key) => setCart((c) => c.filter((l) => l.key !== key)),
        setNote: (key, note) =>
            setCart((c) => c.map((l) => (l.key === key ? { ...l, note } : l))),
        voidItem: (item) => setDialog({ kind: 'void-item', item }),
        comp: (item) => setDialog({ kind: 'comp', item }),
        discount: (item) => setDialog({ kind: 'discount', item }),
        removeDiscount: async (id) => {
            const r = await guard(() =>
                api<{ order: Order }>('DELETE', `/pos/discounts/${id}`),
            );

            if (r) {
                apply(r.order);
            }
        },
        pay: (bill) => setDialog({ kind: 'pay', bill }),
        closeZero: async () => {
            const r =
                order &&
                (await guard(() =>
                    api<{ order: Order }>(
                        'POST',
                        `/pos/orders/${order.id}/close-zero`,
                    ),
                ));

            if (r) {
                apply(r.order, t('Order ditutup.'));
            }
        },
        split: () => setDialog({ kind: 'split' }),
        allocate: (bill) => setDialog({ kind: 'allocate', bill }),
        billRequest: async () => {
            const r =
                order &&
                (await guard(() =>
                    api<{ order: Order }>(
                        'POST',
                        `/pos/orders/${order.id}/bill-request`,
                    ),
                ));

            if (r) {
                apply(r.order);
                const bill = r.order.bills[0];
                const rc = await guard(() =>
                    api<{ receipt: Receipt }>(
                        'GET',
                        `/pos/bills/${bill.id}/receipt`,
                    ),
                );

                if (rc) {
                    setDialog({
                        kind: 'receipt',
                        receipt: {
                            ...rc.receipt,
                            footer: t('PRE-BILL: belum dibayar'),
                        },
                    });
                }
            }
        },
        voidOrder: () => setDialog({ kind: 'void-order' }),
        move: () => setDialog({ kind: 'move' }),
        reprint: (bill) => void showReceipt(bill, true),
        refund: (payment) => setDialog({ kind: 'refund', payment }),
        clear: () => setCart([]),
    };

    const title = useMemo(() => {
        if (!ctx) {
            return t('Pilih meja / tipe order');
        }

        if (ctx.table) {
            return ctx.table.name;
        }

        if (order?.call_number) {
            return `${order.type === 'dine_in' ? t('Dine-in') : 'Takeaway'} ${order.call_number}`;
        }

        if (ctx.type === 'online') {
            return `Online ${ctx.platform}`;
        }

        return ctx.type === 'dine_in' ? t('Dine-in (bayar dulu)') : 'Takeaway';
    }, [ctx, order]);

    const cartCount = cart.reduce((s, l) => s + l.qty, 0);
    const cartTotal = cart.reduce((s, l) => s + l.unitPrice * l.qty, 0);

    // --- "Bayar per item": pick items + qty, see the server-priced total, pay only those.
    const [pickOn, setPickOn] = useState(false);
    const [pickQty, setPickQty] = useState<Record<number, number>>({});
    const [pickPreview, setPickPreview] = useState<PickState['preview']>(null);
    const [pickBusy, setPickBusy] = useState(false);
    const orderId = order?.id ?? null;

    // On phones the order lives in a bottom sheet; close it when a dialog (payment, receipt...) opens.
    useEffect(() => {
        if (dialog) {
            setSheet(false);
        }
    }, [dialog]);

    useEffect(() => {
        setPickOn(false);
        setPickQty({});
        setPickPreview(null);
    }, [orderId]);

    useEffect(() => {
        const items = Object.entries(pickQty)
            .filter(([, q]) => q > 0)
            .map(([id, qty]) => ({ order_item_id: Number(id), qty }));

        if (!pickOn || !orderId || items.length === 0) {
            setPickPreview(null);

            return;
        }

        const timer = window.setTimeout(() => {
            api<NonNullable<PickState['preview']>>(
                'POST',
                `/pos/orders/${orderId}/carve/preview`,
                { items },
            )
                .then(setPickPreview)
                .catch((e) => {
                    setPickPreview(null);
                    toast.error(
                        e instanceof ApiError
                            ? e.message
                            : t('Gagal menghitung.'),
                    );
                });
        }, 250);

        return () => window.clearTimeout(timer);
    }, [pickOn, pickQty, orderId]);

    const payPick: PickState = {
        active: pickOn,
        qty: pickQty,
        supported:
            !!order &&
            order.is_editable &&
            order.grand_total > 0 &&
            order.bills.every((b) => !b.mode || b.mode === 'item') &&
            order.rounds.every((r) => r.status !== 'pending'),
        available: (item) =>
            item.qty -
            (order?.bills
                .filter((b) => b.paid_total > 0 || b.status === 'paid')
                .flatMap((b) => b.items)
                .filter((x) => x.order_item_id === item.id)
                .reduce((a, x) => a + x.qty, 0) ?? 0),
        set: (id, qty) => setPickQty((q) => ({ ...q, [id]: qty })),
        toggle: () => {
            setPickOn((on) => !on);
            setPickQty({});
        },
        busy: pickBusy,
        preview: pickPreview,
        submit: async () => {
            if (!order || pickBusy) {
                return;
            }

            setPickBusy(true);

            try {
                const res = await api<{ order: Order; bill_id: number }>(
                    'POST',
                    `/pos/orders/${order.id}/carve`,
                    {
                        items: Object.entries(pickQty)
                            .filter(([, q]) => q > 0)
                            .map(([id, qty]) => ({
                                order_item_id: Number(id),
                                qty,
                            })),
                    },
                );
                const bill = res.order.bills.find((b) => b.id === res.bill_id);
                apply(res.order);
                setPickOn(false);
                setPickQty({});
                setPickPreview(null);

                if (bill) {
                    setDialog({ kind: 'pay', bill });
                }
            } catch (e) {
                toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
            } finally {
                setPickBusy(false);
            }
        },
    };

    const panel = (
        <OrderPanel
            pick={payPick}
            showKitchenStatus={settings.kds_enabled}
            title={title}
            subtitle={order?.number}
            order={order}
            cart={cart}
            can={can}
            actions={actions}
        />
    );

    return (
        <>
            <Head title="Kasir" />
            <div className="flex h-dvh flex-col bg-background">
                <header className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b bg-card px-3 py-2">
                    <Link href="/" className="text-base font-semibold">
                        POS
                    </Link>
                    <span
                        className={cn(
                            'rounded px-2 py-0.5 text-xs',
                            props.shift
                                ? 'bg-emerald-100 text-emerald-900'
                                : 'bg-amber-100 text-amber-900',
                        )}
                    >
                        {props.shift
                            ? props.shift.mine || !props.shift.owner
                                ? t('Shift terbuka')
                                : `${t('Shift terbuka')} · ${props.shift.owner}`
                            : t('Shift belum dibuka')}
                    </span>
                    {!online && (
                        <span
                            role="status"
                            aria-label="Offline"
                            className="flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-xs text-red-900"
                        >
                            <WifiOff className="size-3" />{' '}
                            <span className="hidden min-[400px]:inline">
                                Offline
                            </span>
                        </span>
                    )}
                    <div className="ml-auto flex items-center gap-1">
                        {can.confirm_qr && (
                            <Button
                                type="button"
                                variant={pendingCount ? 'default' : 'outline'}
                                size="sm"
                                className="relative h-11"
                                onClick={() => setInbox(true)}
                                aria-label={`${t('Pesanan QR')}: ${pendingCount}`}
                            >
                                <Bell /> QR
                                {pendingCount > 0 && (
                                    <span className="absolute -top-1 -right-1 grid size-5 place-items-center rounded-full bg-red-600 text-[11px] text-white">
                                        {pendingCount}
                                    </span>
                                )}
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-11"
                            aria-label={
                                sound.prefs.muted
                                    ? t('Nyalakan bunyi')
                                    : t('Matikan bunyi')
                            }
                            onClick={() => {
                                sound.unlock();
                                sound.setPrefs((p) => ({
                                    ...p,
                                    muted: !p.muted,
                                }));
                            }}
                        >
                            {sound.prefs.muted ? <VolumeX /> : <Volume2 />}
                        </Button>
                        <Button
                            type="button"
                            variant={markMode ? 'default' : 'outline'}
                            size="sm"
                            className="hidden h-11 sm:inline-flex"
                            aria-pressed={markMode}
                            onClick={() => setMarkMode((m) => !m)}
                        >
                            {t('Tandai Habis')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-11"
                            aria-label={t('Pengaturan printer')}
                            onClick={() => setDialog({ kind: 'printer' })}
                        >
                            <Settings2 />
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            className="h-11"
                        >
                            <Link href="/shift">
                                <Clock />{' '}
                                <span className="hidden sm:inline">Shift</span>
                            </Link>
                        </Button>
                    </div>
                </header>

                {!sound.unlocked && !sound.prefs.muted && (
                    <div
                        role="status"
                        className="fixed inset-x-3 bottom-3 z-50 flex items-center justify-between gap-2 rounded-xl bg-sky-100 px-3 py-2 text-sm text-sky-950 shadow-lg"
                    >
                        <span>
                            {t(
                                'Bunyi notifikasi belum aktif. Ketuk di mana saja atau tombol ini.',
                            )}
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            className="h-10"
                            onClick={() => {
                                sound.unlock();
                                sound.beep('order');
                            }}
                        >
                            {t('Aktifkan bunyi')}
                        </Button>
                    </div>
                )}

                {!props.shift && (
                    <div
                        role="alert"
                        className="flex items-center justify-between gap-2 bg-amber-100 px-3 py-2 text-sm text-amber-950"
                    >
                        <span>
                            {t('Buka shift untuk menerima pembayaran.')}
                        </span>
                        <Button asChild size="sm" className="h-10">
                            <Link href="/shift">{t('Buka shift')}</Link>
                        </Button>
                    </div>
                )}

                {state.ready_items.length > 0 && (
                    <div
                        className="flex gap-2 overflow-x-auto bg-emerald-50 px-3 py-1.5 text-xs text-emerald-950"
                        role="status"
                        aria-label={t('Item siap diantar')}
                    >
                        {state.ready_items.map((i) => (
                            <span
                                key={i.id}
                                className="shrink-0 rounded-full bg-emerald-200 px-2 py-1"
                            >
                                ✔ {i.table}: {i.qty}× {i.name}
                            </span>
                        ))}
                    </div>
                )}

                <main className="grid min-h-0 flex-1 gap-3 p-3 md:grid-cols-[minmax(0,1fr)_380px]">
                    <div className="flex min-h-0 flex-col gap-3">
                        <div role="tablist" className="grid grid-cols-2 gap-2">
                            <Button
                                role="tab"
                                aria-selected={view === 'tables'}
                                type="button"
                                variant={
                                    view === 'tables' ? 'default' : 'outline'
                                }
                                className="h-12"
                                onClick={() => setView('tables')}
                            >
                                {t('Meja & order')}
                            </Button>
                            <Button
                                role="tab"
                                aria-selected={view === 'menu'}
                                type="button"
                                variant={
                                    view === 'menu' ? 'default' : 'outline'
                                }
                                className="h-12"
                                onClick={() => setView('menu')}
                            >
                                {t('Menu')}
                            </Button>
                        </div>

                        {view === 'tables' ? (
                            <div className="min-h-0 flex-1 overflow-y-auto">
                                <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-14"
                                        onClick={() => openTakeaway('takeaway')}
                                    >
                                        <ShoppingBag /> Takeaway
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-14"
                                        onClick={() => openTakeaway('dine_in')}
                                    >
                                        <UtensilsCrossed />{' '}
                                        {t('Dine-in bayar dulu')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-14"
                                        onClick={() =>
                                            setDialog({ kind: 'online' })
                                        }
                                    >
                                        Online / Ojol
                                    </Button>
                                </div>
                                {state.takeaway_orders.length > 0 && (
                                    <div className="mb-4">
                                        <h2 className="mb-2 text-sm font-medium text-muted-foreground">
                                            {t('Order terbuka (tanpa meja)')}
                                        </h2>
                                        <div className="flex flex-wrap gap-2">
                                            {state.takeaway_orders.map((o) => (
                                                <Button
                                                    key={o.id}
                                                    type="button"
                                                    variant="outline"
                                                    className="h-12"
                                                    onClick={() =>
                                                        openExisting(o.id)
                                                    }
                                                >
                                                    {o.call_number ??
                                                        o.platform ??
                                                        o.number}{' '}
                                                    · {rupiah(o.grand_total)}
                                                </Button>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                <TableMap
                                    floor={state.floor}
                                    selectedId={ctx?.table?.id ?? null}
                                    onSelect={selectTable}
                                />
                            </div>
                        ) : (
                            <ProductGrid
                                menu={menu}
                                soldOut={soldOut}
                                markMode={markMode}
                                onPick={pick}
                            />
                        )}
                    </div>

                    <aside className="hidden min-h-0 md:block">{panel}</aside>
                </main>

                {/* Phone: cart is a bottom sheet opened from a floating total button (PRD 6.1). */}
                {(ctx || cart.length > 0) && (
                    <div className="fixed inset-x-3 bottom-3 z-30 md:hidden">
                        <Button
                            type="button"
                            className="h-14 w-full justify-between text-base shadow-lg"
                            onClick={() => setSheet(true)}
                        >
                            <span>
                                {cartCount > 0
                                    ? `${cartCount} ${t('item baru')}`
                                    : order
                                      ? order.number
                                      : t('Pesanan')}
                            </span>
                            <span className="flex items-center gap-1 tabular-nums">
                                {rupiah(
                                    cartCount > 0
                                        ? cartTotal
                                        : (order?.grand_total ?? 0),
                                )}{' '}
                                <ChevronUp />
                            </span>
                        </Button>
                    </div>
                )}
                <Sheet open={sheet} onOpenChange={setSheet}>
                    <SheetContent side="bottom" className="h-[88dvh] p-3">
                        <SheetHeader className="sr-only">
                            <SheetTitle>{t('Pesanan')}</SheetTitle>
                            <SheetDescription>{title}</SheetDescription>
                        </SheetHeader>
                        {panel}
                    </SheetContent>
                </Sheet>
            </div>

            <ModifierSheet
                product={picking}
                onClose={() => setPicking(null)}
                onAdd={addLine}
            />
            <QrInbox
                open={inbox}
                onClose={() => setInbox(false)}
                items={state.pending_qr}
                onChanged={() => {
                    void refresh();
                    if (order) void reloadOrder(order.id);
                }}
            />

            {order && dialog?.kind === 'pay' && (
                <PaymentDialog
                    quickCash={settings.quick_cash}
                    order={order}
                    bill={
                        order.bills.find((b) => b.id === dialog.bill.id) ?? null
                    }
                    methods={paymentMethods}
                    onClose={() => setDialog(null)}
                    onPaid={async (res, bill) => {
                        apply(res.order);
                        const done = res.order.bills.find(
                            (b) => b.id === bill.id,
                        );
                        setDialog(null);

                        if (res.change > 0) {
                            toast.success(
                                `${t('Kembalian')} ${rupiah(res.change)}`,
                                { duration: 10000 },
                            );
                        }

                        if (done) {
                            await showReceipt(done, false);
                        }

                        if (res.order.status === 'paid') {
                            setCtx(null);
                            setOrder(null);
                        }
                    }}
                />
            )}
            {order && dialog?.kind === 'split' && (
                <SplitDialog
                    order={order}
                    canPayPartial={payPick.supported}
                    onPayPartial={() => {
                        setDialog(null);
                        setPickOn(true);
                        setPickQty({});
                    }}
                    onClose={() => setDialog(null)}
                    onDone={(o) => {
                        apply(o);
                        setDialog(null);
                    }}
                />
            )}
            {order && dialog?.kind === 'discount' && (
                <DiscountDialog
                    order={order}
                    item={dialog.item}
                    limit={settings.discount_limit_percent}
                    onClose={() => setDialog(null)}
                    onDone={(o) => {
                        apply(o);
                        setDialog(null);
                    }}
                />
            )}
            {order && dialog?.kind === 'move' && (
                <MoveDialog
                    order={order}
                    floor={state.floor}
                    onClose={() => setDialog(null)}
                    onDone={(o, m) => {
                        apply(o, m);
                        setDialog(null);
                        setCtx(null);
                        setOrder(null);
                    }}
                />
            )}

            {dialog?.kind === 'void-item' && (
                <ReasonDialog
                    title={`Void ${dialog.item.name}`}
                    description={t('Item dibatalkan dan tercatat di laporan.')}
                    confirmLabel="Void"
                    destructive
                    quick={[
                        'Salah input',
                        'Pelanggan batal',
                        'Stok habis',
                        'Terlalu lama',
                    ]}
                    onClose={() => setDialog(null)}
                    onSubmit={async (reason) => {
                        const r = await guard(() =>
                            pin.run((p) =>
                                api<{ order: Order }>(
                                    'POST',
                                    `/pos/items/${dialog.item.id}/void`,
                                    { reason, pin: p },
                                ),
                            ),
                        );

                        if (r) {
                            apply(r.order, t('Item di-void.'));
                            setDialog(null);
                        }
                    }}
                />
            )}
            {dialog?.kind === 'comp' && (
                <CompDialog
                    item={dialog.item}
                    onClose={() => setDialog(null)}
                    onSubmit={async (type, reason) => {
                        const r = await guard(() =>
                            pin.run((p) =>
                                api<{ order: Order }>(
                                    'POST',
                                    `/pos/items/${dialog.item.id}/comp`,
                                    { type, reason, pin: p },
                                ),
                            ),
                        );

                        if (r) {
                            apply(r.order, t('Item ditandai.'));
                            setDialog(null);
                        }
                    }}
                />
            )}
            {order && dialog?.kind === 'void-order' && (
                <ReasonDialog
                    title={`Void ${order.number}`}
                    description={t(
                        'Order belum dibayar akan dibatalkan (tetap tercatat).',
                    )}
                    confirmLabel={t('Void order')}
                    destructive
                    quick={[
                        'Salah input',
                        'Pelanggan batal',
                        'Stok habis',
                        'Terlalu lama',
                    ]}
                    onClose={() => setDialog(null)}
                    onSubmit={async (reason) => {
                        const r = await guard(() =>
                            pin.run((p) =>
                                api<{ order: Order }>(
                                    'POST',
                                    `/pos/orders/${order.id}/void`,
                                    { reason, pin: p },
                                ),
                            ),
                        );

                        if (r) {
                            apply(r.order, t('Order dibatalkan.'));
                            setDialog(null);
                            setCtx(null);
                            setOrder(null);
                        }
                    }}
                />
            )}
            {order && dialog?.kind === 'refund' && (
                <RefundDialog
                    payment={dialog.payment}
                    onClose={() => setDialog(null)}
                    onDone={(o) => {
                        apply(o, t('Refund dicatat.'));
                        setDialog(null);
                    }}
                />
            )}
            {order && dialog?.kind === 'allocate' && (
                <AllocateDialog
                    order={order}
                    bill={dialog.bill}
                    onClose={() => setDialog(null)}
                    onDone={(o) => {
                        apply(o, t('Item dialokasikan.'));
                        setDialog(null);
                    }}
                />
            )}

            {dialog?.kind === 'online' && (
                <OnlineDialog
                    onClose={() => setDialog(null)}
                    onSubmit={(platform) => {
                        openTakeaway('online', platform);
                        setDialog(null);
                    }}
                />
            )}
            {dialog?.kind === 'receipt' && (
                <Modal
                    open
                    onClose={() => setDialog(null)}
                    title={t('Struk')}
                    description={dialog.note}
                >
                    <div className="overflow-hidden rounded-lg border">
                        <ReceiptView r={dialog.receipt} />
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-12"
                            onClick={() => void printReceipt(dialog.receipt)}
                        >
                            {t('Cetak')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="h-12"
                            onClick={() =>
                                window.open(
                                    `https://wa.me/?text=${encodeURIComponent(`${t('Struk')} ${dialog.receipt.order_number}: ${dialog.receipt.public_url}`)}`,
                                    '_blank',
                                )
                            }
                        >
                            WhatsApp
                        </Button>
                    </div>
                </Modal>
            )}
            {dialog?.kind === 'printer' && (
                <PrinterDialog onClose={() => setDialog(null)} />
            )}
            {pin.dialog}
        </>
    );
}

function CompDialog({
    item,
    onClose,
    onSubmit,
}: {
    item: OrderItem;
    onClose: () => void;
    onSubmit: (
        type: 'complimentary' | 'staff_meal',
        reason: string,
    ) => Promise<void>;
}) {
    const [type, setType] = useState<'complimentary' | 'staff_meal'>(
        'complimentary',
    );

    return (
        <ReasonDialog
            title={`${t('Komplimen')} · ${item.name}`}
            description={t(
                'Nilai tidak masuk omzet. Butuh PIN manager untuk kasir.',
            )}
            confirmLabel={t('Tandai')}
            onClose={onClose}
            onSubmit={(reason) => onSubmit(type, reason)}
            extra={
                <Field label={t('Jenis')}>
                    <Select
                        value={type}
                        onChange={(e) => setType(e.target.value as typeof type)}
                    >
                        <option value="complimentary">{t('Komplimen')}</option>
                        <option value="staff_meal">{t('Staff meal')}</option>
                    </Select>
                </Field>
            }
        />
    );
}

function OnlineDialog({
    onClose,
    onSubmit,
}: {
    onClose: () => void;
    onSubmit: (platform: string) => void;
}) {
    const [platform, setPlatform] = useState('GoFood');

    return (
        <Modal
            open
            onClose={onClose}
            title="Online / Ojol"
            description={t('Hanya label platform, tanpa integrasi.')}
        >
            <Field label={t('Platform')}>
                <Select
                    value={platform}
                    onChange={(e) => setPlatform(e.target.value)}
                >
                    {['GoFood', 'GrabFood', 'ShopeeFood', 'Lainnya'].map(
                        (p) => (
                            <option key={p}>{p}</option>
                        ),
                    )}
                </Select>
            </Field>
            <Button
                type="button"
                className="h-12"
                onClick={() => onSubmit(platform)}
            >
                {t('Mulai order')}
            </Button>
        </Modal>
    );
}

function RefundDialog({
    payment,
    onClose,
    onDone,
}: {
    payment: Payment;
    onClose: () => void;
    onDone: (o: Order) => void;
}) {
    const max = payment.amount - payment.refunded_total;
    const [amount, setAmount] = useState<number | ''>(max);
    const key = useRef(crypto.randomUUID());

    return (
        <ReasonDialog
            title={`Refund ${payment.method}`}
            description={`${t('Maksimal')} ${rupiah(max)}`}
            confirmLabel="Refund"
            destructive
            onClose={onClose}
            extra={
                <Field label={t('Nominal refund')}>
                    <MoneyInput value={amount} onChange={setAmount} />
                </Field>
            }
            onSubmit={async (reason) => {
                try {
                    const res = await api<{ order: Order }>(
                        'POST',
                        `/pos/payments/${payment.id}/refund`,
                        { amount, reason, idempotency_key: key.current },
                    );
                    onDone(res.order);
                } catch (e) {
                    toast.error(
                        e instanceof ApiError ? e.message : t('Gagal.'),
                    );
                }
            }}
        />
    );
}

function AllocateDialog({
    order,
    bill,
    onClose,
    onDone,
}: {
    order: Order;
    bill: Bill;
    onClose: () => void;
    onDone: (o: Order) => void;
}) {
    const pool = order.bills.find((b) => b.is_unallocated);
    const items = order.rounds
        .flatMap((r) => r.items)
        .filter((i) => pool?.items.some((p) => p.order_item_id === i.id));
    const [qty, setQty] = useState<Record<number, number>>({});

    const submit = async () => {
        try {
            const res = await api<{ order: Order }>(
                'POST',
                `/pos/orders/${order.id}/allocate`,
                {
                    bill_id: bill.id,
                    items: Object.entries(qty)
                        .filter(([, q]) => q > 0)
                        .map(([id, q]) => ({
                            order_item_id: Number(id),
                            qty: q,
                        })),
                },
            );
            onDone(res.order);
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`${t('Alokasi ke')} ${bill.label}`}
            description={t('Item yang belum dialokasikan')}
        >
            {items.map((i) => {
                const avail =
                    pool?.items.find((p) => p.order_item_id === i.id)?.qty ?? 0;

                return (
                    <div
                        key={i.id}
                        className="flex items-center justify-between gap-2 text-sm"
                    >
                        <span>
                            {i.name}{' '}
                            <span className="text-muted-foreground">
                                ({avail})
                            </span>
                        </span>
                        <Input
                            className="h-11 w-20 text-center"
                            type="number"
                            min={0}
                            max={avail}
                            value={qty[i.id] ?? 0}
                            onChange={(e) =>
                                setQty((q) => ({
                                    ...q,
                                    [i.id]: Math.max(
                                        0,
                                        Math.min(avail, Number(e.target.value)),
                                    ),
                                }))
                            }
                            aria-label={i.name}
                        />
                    </div>
                );
            })}
            <Button
                type="button"
                className="h-12"
                disabled={!Object.values(qty).some((q) => q > 0)}
                onClick={submit}
            >
                {t('Alokasikan')}
            </Button>
        </Modal>
    );
}

function PrinterDialog({ onClose }: { onClose: () => void }) {
    const [prefs, setPrefs] = useState<PrinterPrefs>(getPrinterPrefs());
    const [printers, setPrinters] = useState<string[]>([]);
    const [error, setError] = useState('');

    const detect = async () => {
        try {
            setPrinters(await listPrinters());
            setError('');
        } catch {
            setError(
                t(
                    'QZ Tray tidak terdeteksi. Pastikan QZ Tray berjalan di perangkat ini.',
                ),
            );
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={t('Printer perangkat ini')}
            description={t(
                'Pengaturan hanya berlaku di perangkat/browser ini.',
            )}
        >
            <Field label={t('Cara cetak')}>
                <Select
                    value={prefs.method}
                    onChange={(e) =>
                        setPrefs({
                            ...prefs,
                            method: e.target.value as PrinterPrefs['method'],
                        })
                    }
                >
                    <option value="bluetooth">
                        {t('Bluetooth langsung (printer BLE)')}
                    </option>
                    <option value="rawbt">
                        {t('Aplikasi RawBT (Android, printer Bluetooth biasa)')}
                    </option>
                    <option value="qz">{t('QZ Tray (komputer/laptop)')}</option>
                </Select>
            </Field>
            {prefs.method === 'bluetooth' && (
                <div className="grid gap-2 rounded-lg bg-muted p-3 text-sm">
                    {bluetoothSupported() ? (
                        <>
                            <p>
                                {t(
                                    'Nyalakan printer, lalu tekan Hubungkan dan pilih printer dari daftar.',
                                )}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                className="h-11"
                                onClick={async () => {
                                    try {
                                        const name =
                                            await pairBluetoothPrinter();
                                        setError('');
                                        toast.success(
                                            `${t('Terhubung')}: ${name}`,
                                        );
                                    } catch (e) {
                                        setError(
                                            e instanceof Error
                                                ? e.message
                                                : t('Gagal menghubungkan.'),
                                        );
                                    }
                                }}
                            >
                                {bluetoothPrinterName()
                                    ? `${t('Terhubung')}: ${bluetoothPrinterName()} · ${t('ganti')}`
                                    : t('Hubungkan printer Bluetooth')}
                            </Button>
                        </>
                    ) : (
                        <p role="alert">
                            {t(
                                'Browser ini tidak mendukung Bluetooth langsung. Di iPhone pakai browser Bluefy; di Android pakai Chrome.',
                            )}
                        </p>
                    )}
                </div>
            )}
            {prefs.method === 'rawbt' && (
                <p className="rounded-lg bg-muted p-3 text-sm">
                    {t(
                        'Pasang aplikasi RawBT dari Play Store, pasangkan printer lewat Bluetooth Android, lalu pilih printer itu di RawBT. Struk dikirim otomatis ke RawBT.',
                    )}
                </p>
            )}
            {prefs.method === 'qz' && (
                <Button
                    type="button"
                    variant="outline"
                    className="h-11"
                    onClick={detect}
                >
                    {t('Cari printer (QZ Tray)')}
                </Button>
            )}
            {error && (
                <p className="text-sm text-destructive" role="alert">
                    {error}
                </p>
            )}
            {prefs.method === 'qz' && (
                <Field label="Printer">
                    <Select
                        value={prefs.printer}
                        onChange={(e) =>
                            setPrefs({ ...prefs, printer: e.target.value })
                        }
                    >
                        <option value="">{t('— pilih —')}</option>
                        {[
                            ...new Set(
                                [prefs.printer, ...printers].filter(Boolean),
                            ),
                        ].map((p) => (
                            <option key={p}>{p}</option>
                        ))}
                    </Select>
                </Field>
            )}
            <Field label={t('Lebar kertas')}>
                <Select
                    value={prefs.paper}
                    onChange={(e) =>
                        setPrefs({
                            ...prefs,
                            paper: Number(e.target.value) as 58 | 80,
                        })
                    }
                >
                    <option value={58}>58 mm</option>
                    <option value={80}>80 mm</option>
                </Select>
            </Field>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-5"
                    checked={prefs.enabled}
                    onChange={(e) =>
                        setPrefs({ ...prefs, enabled: e.target.checked })
                    }
                />
                {t('Cetak langsung tanpa dialog browser')}
            </label>
            <Button
                type="button"
                variant="outline"
                className="h-11"
                onClick={async () => {
                    try {
                        await printTest(prefs);
                        setError('');
                    } catch (e) {
                        setError(
                            e instanceof Error
                                ? e.message
                                : t('Gagal mencetak.'),
                        );
                    }
                }}
            >
                {t('Cetak tes')}
            </Button>
            <Button
                type="button"
                className="h-12"
                onClick={() => {
                    savePrinterPrefs(prefs);
                    toast.success(t('Tersimpan.'));
                    onClose();
                }}
            >
                {t('Simpan')}
            </Button>
        </Modal>
    );
}
