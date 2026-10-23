<?php

namespace App\Http\Controllers\Shift;

use App\Domain\Accounting\Models\Account;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Actions\AddCashMovementAction;
use App\Domain\Shift\Actions\CloseShiftAction;
use App\Domain\Shift\Actions\OpenShiftAction;
use App\Domain\Shift\Actions\ReopenShiftAction;
use App\Domain\Shift\Models\Shift;
use App\Domain\Shift\ShiftCash;
use App\Domain\Shift\ShiftResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(Request $request, ShiftCash $cash, OpenShiftAction $open, ShiftResolver $resolver): Response
    {
        $user = $this->actor($request);
        $operate = $user->can('open-close-shift');
        $shift = $resolver->current();
        $outletId = $this->outlet->id();

        return Inertia::render('shift/index', [
            // One shift for the whole store: shown to every role, only people with the shift permission can act on it.
            'shift' => $shift ? $this->present($shift, $cash) : null,
            'suggestedOpening' => $open->suggestedOpeningCash(),
            'accounts' => $operate ? [
                'expense' => Account::query()->where('outlet_id', $outletId)->whereIn('type', ['expense'])->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
                'other' => Account::query()->where('outlet_id', $outletId)->whereIn('type', ['equity', 'revenue', 'liability'])->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
                'bank' => Account::query()->where('outlet_id', $outletId)->where('type', 'asset')->where('system_key', null)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            ] : ['expense' => [], 'other' => [], 'bank' => []],
            'recent' => Shift::query()->where('outlet_id', $outletId)->whereNotNull('closed_at')->with(['user:id,name', 'closer:id,name'])->latest('closed_at')->limit(10)->get()->map(fn ($s) => $this->present($s, $cash))->all(),
            // The transactions inside the shift are visible to everybody: the shift is one unit for the store.
            'transactions' => $shift ? Order::query()->where('shift_id', $shift->id)->with(['table:id,name', 'payments.receiver:id,name'])->latest('id')->limit(300)->get()
                ->map(fn ($o) => [
                    'id' => $o->id, 'number' => $o->number, 'where' => $o->table->name ?? $o->call_number ?? $o->platform ?? ucfirst((string) $o->type->value),
                    'at' => $o->created_at?->toIso8601String(), 'status' => $o->status->label(), 'total' => $o->grand_total - $o->refunded_total,
                    'paid_by' => $o->payments->map(fn ($p) => $p->receiver?->name)->filter()->unique()->implode(', '),
                ])->all() : [],
            'can' => ['operate' => $operate, 'reopen_id' => $shift ? null : $this->reopenable($request)],
        ]);
    }

    /** Id of the last closed shift when this person may undo its close, else null. */
    private function reopenable(Request $request): ?int
    {
        $user = $request->user();
        $last = Shift::query()->where('outlet_id', $this->outlet->id())->whereNotNull('closed_at')->latest('id')->first();
        if ($user === null || $last === null || ! $user->can('reopen-shift')) {
            return null;
        }
        $own = $last->closed_by === $user->id && $last->closed_at?->gte(now()->subMinutes(ReopenShiftAction::OWN_CLOSE_MINUTES));

        return $user->can('manage-shifts-any') || $own ? $last->id : null;
    }

    public function reopen(Request $request, Shift $shift, ReopenShiftAction $action): RedirectResponse
    {
        abort_unless($shift->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['reason' => 'nullable|string|max:200']);
        $action->handle($shift, $this->actor($request), $data['reason'] ?? null);

        return redirect()->route('shift.index')->with('success', 'Shift dibuka kembali.');
    }

    public function open(Request $request, OpenShiftAction $action): RedirectResponse
    {
        $data = $request->validate(['opening_cash' => 'required|integer|min:0', 'device_label' => 'nullable|string|max:60']);
        $action->handle($this->actor($request), (int) $data['opening_cash'], $data['device_label'] ?? null);

        return back()->with('success', 'Shift dibuka.');
    }

    public function movement(Request $request, AddCashMovementAction $action): RedirectResponse
    {
        $data = $request->validate(['direction' => 'required|in:in,out', 'amount' => 'required|integer|min:1', 'reason' => 'required|string|max:200', 'account_id' => 'required|integer|exists:accounts,id']);
        $shift = app(ShiftResolver::class)->current() ?? throw new OrderException('Belum ada shift terbuka.');
        $action->handle($shift, $this->actor($request), $data['direction'], (int) $data['amount'], $data['reason'], (int) $data['account_id']);

        return back()->with('success', 'Kas dicatat.');
    }

    public function close(Request $request, CloseShiftAction $action): RedirectResponse
    {
        $data = $request->validate([
            'counted_cash' => 'required|integer|min:0',
            'cash_left' => 'required|integer|min:0', 'deposit_account_id' => 'nullable|integer|exists:accounts,id',
            'difference_reason' => 'nullable|string|max:200', 'close_key' => 'required|string|max:100',
        ]);
        $actor = $this->actor($request);
        $shift = app(ShiftResolver::class)->current() ?? throw new OrderException('Belum ada shift terbuka.');
        $action->handle(
            $shift, $this->actor($request),
            (int) $data['counted_cash'],
            (int) $data['cash_left'], $data['deposit_account_id'] ?? null, $data['difference_reason'] ?? null, $data['close_key'],
        );

        // Session ends when the cashier's shift closes (PRD 8).
        if (! $actor->can('manage-shifts-any')) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Shift ditutup. Silakan login kembali.');
        }

        return redirect()->route('shift.index')->with('success', 'Shift ditutup.');
    }

    /** @return array<string, mixed> */
    private function present(Shift $shift, ShiftCash $cash): array
    {
        $summary = $cash->summarize($shift);
        $byMethod = DB::table('payments')->where('shift_id', $shift->id)->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->groupBy('payment_methods.name', 'payment_methods.type')->selectRaw('payment_methods.name as name, payment_methods.type as type, SUM(payments.amount) as total, COUNT(*) as count')->orderBy('payment_methods.name')->get();
        $orders = DB::table('orders')->where('shift_id', $shift->id)->whereIn('status', ['paid', 'refunded'])->count();
        $voids = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.shift_id', $shift->id)->whereNotNull('order_items.voided_at')->count();
        $discounts = (int) DB::table('orders')->where('shift_id', $shift->id)->sum('discount_total');
        $comps = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.shift_id', $shift->id)->whereNotNull('order_items.comp_type')->count();

        return [
            'id' => $shift->id, 'opened_at' => $shift->opened_at->toIso8601String(), 'closed_at' => $shift->closed_at?->toIso8601String(),
            'opening_cash' => $shift->opening_cash, 'counted_cash' => $shift->counted_cash, 'difference' => $shift->difference,
            'cash_left' => $shift->cash_left, 'cash_deposited' => $shift->cash_deposited, 'difference_reason' => $shift->difference_reason,
            'summary' => $summary,
            'by_method' => $byMethod->map(fn ($r) => ['name' => $r->name, 'type' => $r->type, 'total' => (int) $r->total, 'count' => (int) $r->count])->all(),
            'orders' => $orders, 'voids' => $voids, 'discounts' => $discounts, 'comps' => $comps,
            'opened_by' => $shift->user?->name, 'closed_by' => $shift->closer?->name,
            'movements' => $shift->movements()->with('creator:id,name')->latest()->get()->map(fn ($m) => ['direction' => $m->direction, 'amount' => $m->amount, 'reason' => $m->reason, 'created_at' => $m->created_at, 'by' => $m->creator?->name])->all(),
        ];
    }
}
