<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Ordering\Actions\ConfirmRoundAction;
use App\Domain\Ordering\Actions\MarkCompAction;
use App\Domain\Ordering\Actions\MergeOrdersAction;
use App\Domain\Ordering\Actions\MoveItemsAction;
use App\Domain\Ordering\Actions\MoveTableAction;
use App\Domain\Ordering\Actions\PlaceOrderAction;
use App\Domain\Ordering\Actions\RejectRoundAction;
use App\Domain\Ordering\Actions\RequestBillAction;
use App\Domain\Ordering\Actions\VoidItemAction;
use App\Domain\Ordering\Actions\VoidOrderAction;
use App\Domain\Ordering\Enums\CompType;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderRound;
use App\Domain\Payment\Actions\AllocateItemsAction;
use App\Domain\Payment\Actions\CancelSplitAction;
use App\Domain\Payment\Actions\CloseZeroOrderAction;
use App\Domain\Payment\Actions\RecordPaymentAction;
use App\Domain\Payment\Actions\RefundPaymentAction;
use App\Domain\Payment\Actions\SplitBillAction;
use App\Domain\Payment\Models\Bill;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pricing\Actions\ApplyDiscountAction;
use App\Domain\Pricing\Models\Discount;
use App\Http\Controllers\Controller;
use App\Http\Presenters\OrderPresenter;
use App\Http\Requests\PayRequest;
use App\Http\Requests\PlaceOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Thin: validate -> call Action -> return JSON. No queries or math here. */
class OrderController extends Controller
{
    private function respond(?Order $order, int $status = 200): JsonResponse
    {
        abort_if($order === null, 404);

        return response()->json(['order' => OrderPresenter::full($order->refresh())], $status);
    }

    public function show(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        return $this->respond($order);
    }

    public function store(PlaceOrderRequest $request, PlaceOrderAction $action): JsonResponse
    {
        return $this->respond($action->handle($this->actor($request), $request->validated()), 201);
    }

    public function requestBill(Order $order, Request $request, RequestBillAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('create-order'), 403);

        return $this->respond($action->handle($order, $this->actor($request)));
    }

    public function pay(Order $order, PayRequest $request, RecordPaymentAction $action): JsonResponse
    {
        $bill = Bill::query()->where('order_id', $order->id)->whereKey($request->integer('bill_id'))->firstOrFail();
        $payment = $action->handle($bill, $this->actor($request), $request->integer('payment_method_id'), $request->integer('amount'), $request->string('idempotency_key')->toString(), $request->input('reference'));

        return response()->json(['order' => OrderPresenter::full($order->refresh()), 'payment_id' => $payment->id, 'change' => $payment->change, 'bill_public_id' => $bill->public_id]);
    }

    public function closeZero(Order $order, Request $request, CloseZeroOrderAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);

        return $this->respond($action->handle($order, $this->actor($request)));
    }

    public function void(Order $order, Request $request, VoidOrderAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:200', 'pin' => 'nullable|digits:6']);

        return $this->respond($action->handle($order, $this->actor($request), $data['reason'], $data['pin'] ?? null));
    }

    public function voidItem(OrderItem $item, Request $request, VoidItemAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('create-order'), 403);
        $data = $request->validate(['reason' => 'required|string|max:200', 'pin' => 'nullable|digits:6']);
        $action->handle($item, $this->actor($request), $data['reason'], $data['pin'] ?? null);

        return $this->respond($item->order);
    }

    public function comp(OrderItem $item, Request $request, MarkCompAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('create-order'), 403);
        $data = $request->validate(['type' => 'required|in:complimentary,staff_meal', 'reason' => 'required|string|max:200', 'pin' => 'nullable|digits:6']);
        $action->handle($item, $this->actor($request), CompType::from($data['type']), $data['reason'], $data['pin'] ?? null);

        return $this->respond($item->order);
    }

    public function discount(Order $order, Request $request, ApplyDiscountAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('discount-within-limit') || $this->actor($request)->can('create-order'), 403);
        $data = $request->validate([
            'type' => 'required|in:percent,fixed', 'value' => 'required|integer|min:1', 'reason' => 'required|string|max:200',
            'item_id' => 'nullable|integer', 'pin' => 'nullable|digits:6',
        ]);
        $item = isset($data['item_id']) ? OrderItem::query()->where('order_id', $order->id)->whereKey((int) $data['item_id'])->firstOrFail() : null;
        $action->handle($order, $this->actor($request), $data['type'], (int) $data['value'], $data['reason'], $item, $data['pin'] ?? null);

        return $this->respond($order);
    }

    public function removeDiscount(Discount $discount, Request $request, ApplyDiscountAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('discount-within-limit'), 403);
        $action->remove($discount, $this->actor($request));

        return $this->respond(Order::findOrFail($discount->order_id));
    }

    public function split(Order $order, Request $request, SplitBillAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);
        $data = $request->validate([
            'mode' => 'required|in:item,equal,amount', 'count' => 'nullable|integer|min:2|max:20',
            'parts' => 'nullable|array', 'parts.*.label' => 'nullable|string|max:40', 'parts.*.amount' => 'nullable|integer|min:1',
            'parts.*.items' => 'nullable|array', 'parts.*.items.*.order_item_id' => 'required_with:parts.*.items|integer', 'parts.*.items.*.qty' => 'required_with:parts.*.items|integer|min:1',
            'labels' => 'nullable|array',
        ]);
        $action->handle($order, $data['mode'], $data, $this->actor($request));

        return $this->respond($order);
    }

    public function cancelSplit(Order $order, Request $request, CancelSplitAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);
        $action->handle($order);

        return $this->respond($order);
    }

    public function allocate(Order $order, Request $request, AllocateItemsAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);
        $data = $request->validate(['bill_id' => 'required|integer', 'items' => 'required|array', 'items.*.order_item_id' => 'required|integer', 'items.*.qty' => 'required|integer|min:1']);
        $bill = Bill::query()->where('order_id', $order->id)->whereKey((int) $data['bill_id'])->firstOrFail();
        $qty = [];
        foreach ($data['items'] as $row) {
            $qty[(int) $row['order_item_id']] = (int) $row['qty'];
        }
        $action->handle($bill, $qty);

        return $this->respond($order);
    }

    public function moveTable(Order $order, Request $request, MoveTableAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment') || $this->actor($request)->can('manage-shifts-any'), 403);
        $data = $request->validate(['table_id' => 'required|integer|exists:tables,id']);

        return $this->respond($action->handle($order, $data['table_id'], $this->actor($request)));
    }

    public function merge(Order $order, Request $request, MergeOrdersAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment') || $this->actor($request)->can('manage-shifts-any'), 403);
        $data = $request->validate(['target_order_id' => 'required|integer|exists:orders,id']);

        return $this->respond($action->handle($order, Order::findOrFail((int) $data['target_order_id']), $this->actor($request)));
    }

    public function moveItems(Order $order, Request $request, MoveItemsAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment') || $this->actor($request)->can('manage-shifts-any'), 403);
        $data = $request->validate(['target_order_id' => 'required|integer|exists:orders,id', 'item_ids' => 'required|array|min:1', 'item_ids.*' => 'integer']);
        $items = array_map('intval', OrderItem::query()->where('order_id', $order->id)->whereIn('id', $data['item_ids'])->pluck('id')->all());

        return $this->respond($action->handle($items, Order::findOrFail((int) $data['target_order_id']), $this->actor($request)));
    }

    public function confirmRound(OrderRound $round, Request $request, ConfirmRoundAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);
        $data = $request->validate(['changes' => 'nullable|array', 'changes.*' => 'integer|min:0|max:99']);
        $action->handle($round, $this->actor($request), array_combine(array_map('intval', array_keys($data['changes'] ?? [])), array_map('intval', $data['changes'] ?? [])));

        return $this->respond($round->order);
    }

    public function rejectRound(OrderRound $round, Request $request, RejectRoundAction $action): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $action->handle($round, $this->actor($request), $data['reason']);

        return $this->respond($round->order);
    }

    public function refund(Payment $payment, Request $request, RefundPaymentAction $action): JsonResponse
    {
        $data = $request->validate(['amount' => 'required|integer|min:1', 'reason' => 'required|string|max:200', 'idempotency_key' => 'required|string|max:100']);
        $action->handle($payment, $this->actor($request), (int) $data['amount'], $data['reason'], $data['idempotency_key']);

        return $this->respond($payment->order);
    }
}
