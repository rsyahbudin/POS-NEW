<?php

namespace App\Domain\Accounting;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Settings\OutletContext;

/**
 * Accounts that automation depends on. Looked up by `system_key`.
 */
enum SystemAccount: string
{
    case CashDrawer = 'cash_drawer';
    case SalesRevenue = 'sales_revenue';
    case ServiceRevenue = 'service_revenue';
    case TaxPayable = 'tax_payable';
    case SalesDiscount = 'sales_discount';
    case CashVariance = 'cash_variance';
    case RoundingVariance = 'rounding_variance';
    case OwnerEquity = 'owner_equity';

    public function resolve(?int $outletId = null): Account
    {
        $outletId ??= app(OutletContext::class)->id();

        return Account::query()
            ->where('outlet_id', $outletId)
            ->where('system_key', $this->value)
            ->first()
            ?? throw new AccountingException("Akun sistem {$this->value} belum ada.");
    }

    /** @return array{code: string, name: string, type: AccountType} */
    public function definition(): array
    {
        return match ($this) {
            self::CashDrawer => ['code' => '1-1100', 'name' => 'Kas Laci', 'type' => AccountType::Asset],
            self::SalesRevenue => ['code' => '4-1000', 'name' => 'Pendapatan Penjualan', 'type' => AccountType::Revenue],
            self::ServiceRevenue => ['code' => '4-2000', 'name' => 'Pendapatan Service Charge', 'type' => AccountType::Revenue],
            self::TaxPayable => ['code' => '2-1100', 'name' => 'Utang Pajak', 'type' => AccountType::Liability],
            self::SalesDiscount => ['code' => '4-3000', 'name' => 'Diskon Penjualan', 'type' => AccountType::Revenue],
            self::CashVariance => ['code' => '5-9100', 'name' => 'Selisih Kas', 'type' => AccountType::Expense],
            self::RoundingVariance => ['code' => '5-9200', 'name' => 'Selisih Pembulatan', 'type' => AccountType::Expense],
            self::OwnerEquity => ['code' => '3-1000', 'name' => 'Modal Pemilik', 'type' => AccountType::Equity],
        };
    }
}
