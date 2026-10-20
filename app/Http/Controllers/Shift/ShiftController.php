<?php

namespace App\Http\Controllers\Shift;

use App\Domain\Accounting\Models\Account;
use App\Domain\Settings\OutletContext;
use App\Domain\Shift\Actions\AddCashMovementAction;
use App\Domain\Shift\Actions\CloseShiftAction;
use App\Domain\Shift\Actions\OpenShiftAction;
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

    public function index(Request $request, ShiftCash $cash, OpenShiftAction $open): Response
    {
        $user = $this->actor($request);
        $shift = Shift::query()->where('user_id', $user->id)->whereNull('closed_at')->first();

        $outletId = $this->outlet->id();

        return Inertia::render('shift/index', [
            'shift' => $shift ? $this->present($shift, $cash) : null,
            'suggestedOpening' => $open->suggestedOpeningCash(),
            'accounts' => [
                'expense' => Account::query()->where('outlet_id', $outletId)->whereIn('type', ['expense'])->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
                'other' => Account::query()->where('outlet_id', $outletId)->whereIn('type', ['equity', 'revenue', 'liability'])->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
                'bank' => Account::query()->where('outlet_id', $outletId)->where('type', 'asset')->where('system_key', null)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            ],
            'recent' => Shift::query()->where('user_id', $user->id)->whereNotNull('closed_at')->latest('closed_at')->limit(5)->get()->map(fn ($s) => $this->present($s, $cash))->all(),
            // Managers/owner see (and may close) shifts opened by others, e.g. the cashier's.
            'others' => $user->can('manage-shifts-any')
                ? Shift::query()->where('outlet_id', $outletId)->whereNull('closed_at')->where('user_id', '!=', $user->id)->with('user:id,name')->get()
                    ->map(fn (Shift $s) => $this->present($s, $cash) + ['user' => $s->user?->name])->all()
                : [],
            'sharing' => app(ShiftResolver::class)->canShare($user),
            'can' => ['any' => $user->can('manage-shifts-any')],
        ]);
    }

    public function open(Request $request, OpenShiftAction $action): RedirectResponse
    {
        $data = $request->validate(['opening_cash' => 'required|integer|min:0', 'device_label' => 'nullable|string|max:60']);
        $action->handle($this->actor($request), (int) $data['opening_cash'], $data['device_label'] ?? null);

        return redirect()->route('shift.index')->with('success', 'Shift dibuka.');
    }

    public function movement(Request $request, AddCashMovementAction $action): RedirectResponse
    {
        $data = $request->validate(['direction' => 'required|in:in,out', 'amount' => 'required|integer|min:1', 'reason' => 'required|string|max:200', 'account_id' => 'required|integer|exists:accounts,id']);
        $shift = Shift::query()->where('user_id', $this->actor($request)->id)->whereNull('closed_at')->firstOrFail();
        $action->handle($shift, $this->actor($request), $data['direction'], (int) $data['amount'], $data['reason'], (int) $data['account_id']);

        return back()->with('success', 'Kas dicatat.');
    }

    public function close(Request $request, CloseShiftAction $action): RedirectResponse
    {
        $data = $request->validate([
            'counted_cash' => 'required|integer|min:0',
            'cash_left' => 'required|integer|min:0', 'deposit_account_id' => 'nullable|integer|exists:accounts,id',
            'difference_reason' => 'nullable|string|max:200', 'close_key' => 'required|string|max:100', 'shift_id' => 'nullable|integer',
        ]);
        $actor = $this->actor($request);
        // Managers/owner may close another person's shift; everybody else closes their own.
        $shift = isset($data['shift_id']) && $actor->can('manage-shifts-any')
            ? Shift::query()->whereKey((int) $data['shift_id'])->whereNull('closed_at')->firstOrFail()
            : Shift::query()->where('user_id', $actor->id)->whereNull('closed_at')->firstOrFail();
        $closedOwn = $shift->user_id === $actor->id;
        $action->handle(
            $shift, $this->actor($request),
            (int) $data['counted_cash'],
            (int) $data['cash_left'], $data['deposit_account_id'] ?? null, $data['difference_reason'] ?? null, $data['close_key'],
        );

        // Session ends when the cashier's shift closes (PRD 8).
        if ($closedOwn && ! $actor->can('manage-shifts-any')) {
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
            'movements' => $shift->movements()->latest()->get(['direction', 'amount', 'reason', 'created_at'])->all(),
        ];
    }
}
