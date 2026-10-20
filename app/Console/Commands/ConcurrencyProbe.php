<?php

namespace App\Console\Commands;

use App\Domain\Payment\Actions\RecordPaymentAction;
use App\Domain\Payment\Models\Bill;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Test-only helper: runs one payment in its own OS process so the concurrency
 * tests can race two real database connections. Refuses to run outside `testing`.
 */
class ConcurrencyProbe extends Command
{
    protected $signature = 'pos:concurrency-probe {bill} {user} {method} {amount} {key} {startAt : epoch milliseconds to wait for}';

    protected $description = 'Test only: record a payment at an exact start time and print the outcome as JSON.';

    public function handle(RecordPaymentAction $pay): int
    {
        if (! app()->environment('testing')) {
            $this->error('Hanya untuk environment testing.');

            return self::FAILURE;
        }

        // Barrier: both processes boot first, then fire together.
        $wait = (int) $this->argument('startAt') - (int) (microtime(true) * 1000);
        if ($wait > 0) {
            usleep($wait * 1000);
        }

        $key = $this->argument('key');
        if (! is_string($key)) {
            return self::FAILURE;
        }

        try {
            $payment = $pay->handle(
                Bill::query()->findOrFail((int) $this->argument('bill')),
                User::query()->findOrFail((int) $this->argument('user')),
                (int) $this->argument('method'),
                (int) $this->argument('amount'),
                $key,
            );
            $this->line((string) json_encode(['ok' => true, 'payment_id' => $payment->id]));
        } catch (Throwable $e) {
            $this->line((string) json_encode(['ok' => false, 'error' => $e->getMessage()]));
        }

        return self::SUCCESS;
    }
}
