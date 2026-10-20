<?php

namespace App\Http\Controllers\Receipt;

use App\Domain\Payment\Models\Bill;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReceiptPresenter;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    /** Public tokenised link (shareable over WhatsApp). Unguessable ULID; no customer data stored. */
    public function show(string $publicId): Response
    {
        Inertia::setRootView('public');

        $bill = Bill::query()->where('public_id', $publicId)->firstOrFail();

        return Inertia::render('receipt/show', ['receipt' => ReceiptPresenter::forBill($bill)]);
    }

    public function data(Bill $bill, Request $request): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment') || $this->actor($request)->can('create-order'), 403);

        return response()->json(['receipt' => ReceiptPresenter::forBill($bill)]);
    }

    /** Reprints are labelled COPY and written to the audit log (PRD 4.13). */
    public function reprint(Bill $bill, Request $request): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);
        Audit::log('receipt.reprinted', $bill, ['order' => $bill->order->number, 'bill' => $bill->id]);

        return response()->json(['receipt' => ReceiptPresenter::forBill($bill, copy: true)]);
    }
}
