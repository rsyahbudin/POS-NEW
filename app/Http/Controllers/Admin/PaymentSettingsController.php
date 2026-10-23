<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Account;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** The "Pembayaran & Pajak" page: payment methods and tax / service-charge rules, away from Pengaturan. */
class PaymentSettingsController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(): Response
    {
        $id = $this->outlet->id();

        return Inertia::render('admin/payments-tax', [
            'paymentMethods' => PaymentMethod::query()->where('outlet_id', $id)->orderBy('sort')->get(['id', 'name', 'type', 'account_id', 'is_active']),
            'cashAccounts' => Account::query()->where('outlet_id', $id)->where('type', 'asset')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'taxRules' => TaxRule::query()->where('outlet_id', $id)->orderBy('sequence')->get(['id', 'name', 'kind', 'rate_bp', 'sequence', 'base', 'is_inclusive', 'is_active']),
        ]);
    }
}
