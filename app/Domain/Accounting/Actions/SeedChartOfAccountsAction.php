<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\SystemAccount;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Payment\Enums\PaymentMethodType;
use App\Domain\Payment\Models\PaymentMethod;

class SeedChartOfAccountsAction
{
    public function handle(int $outletId): void
    {
        foreach (SystemAccount::cases() as $case) {
            $d = $case->definition();
            Account::firstOrCreate(
                ['outlet_id' => $outletId, 'code' => $d['code']],
                ['name' => $d['name'], 'type' => $d['type'], 'system_key' => $case->value, 'is_system' => true],
            );
        }

        $bank = fn (string $code, string $name) => Account::firstOrCreate(
            ['outlet_id' => $outletId, 'code' => $code],
            ['name' => $name, 'type' => AccountType::Asset],
        );
        $qris = $bank('1-1210', 'Bank — QRIS');
        $transfer = $bank('1-1220', 'Bank — Transfer');

        $cash = SystemAccount::CashDrawer->resolve($outletId);
        foreach ([
            ['Tunai', PaymentMethodType::Cash, $cash, 1],
            ['QRIS', PaymentMethodType::Qris, $qris, 2],
            ['Transfer Bank', PaymentMethodType::Transfer, $transfer, 3],
        ] as [$name, $type, $account, $sort]) {
            PaymentMethod::firstOrCreate(
                ['outlet_id' => $outletId, 'name' => $name],
                ['type' => $type, 'account_id' => $account->id, 'sort' => $sort],
            );
        }

        foreach (['Bahan Baku' => '5-1000', 'Gaji' => '5-2000', 'Listrik & Air' => '5-3000', 'Sewa' => '5-4000', 'Lain-lain' => '5-5000'] as $name => $code) {
            $acc = Account::firstOrCreate(
                ['outlet_id' => $outletId, 'code' => $code],
                ['name' => "Beban {$name}", 'type' => AccountType::Expense],
            );
            ExpenseCategory::firstOrCreate(['outlet_id' => $outletId, 'name' => $name], ['account_id' => $acc->id]);
        }
    }
}
