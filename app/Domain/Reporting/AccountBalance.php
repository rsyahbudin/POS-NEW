<?php

namespace App\Domain\Reporting;

use App\Domain\Accounting\Enums\AccountType;

/** Net balance of one account over a period. Debit-normal accounts: debit - credit; others: credit - debit. */
final readonly class AccountBalance
{
    public int $balance;

    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $type,
        public int $debit,
        public int $credit,
    ) {
        $this->balance = AccountType::from($type)->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }
}
