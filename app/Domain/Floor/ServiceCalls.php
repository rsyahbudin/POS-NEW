<?php

namespace App\Domain\Floor;

use App\Domain\Floor\Events\ServiceCallChanged;
use App\Domain\Floor\Models\ServiceCall;
use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Settings\OutletContext;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Support\Audit;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/** Guests calling staff from the table QR page, and staff answering. Where a call shows up is set in Pengaturan. */
class ServiceCalls
{
    /** A guest who keeps pressing re-rings the bell at most this often. */
    public const RERING_SECONDS = 30;

    public function __construct(private SettingsRepository $settings, private OutletContext $outlet) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('call.enabled', false);
    }

    /** Whether a screen type ('pos' for cashier and waiter, 'kitchen' for KDS) receives calls. */
    public function reaches(string $screen): bool
    {
        return $this->enabled() && (bool) $this->settings->get($screen === 'kitchen' ? 'call.to_kitchen' : 'call.to_pos', $screen !== 'kitchen');
    }

    /** @return array<int, array{id: int, table: string, table_id: int, reason: string, reason_label: string, repeats: int, at: string, rang_at: string}> */
    public function pending(string $screen): array
    {
        if (! $this->reaches($screen)) {
            return [];
        }

        return ServiceCall::query()->where('outlet_id', $this->outlet->id())->where('status', 'pending')->with('table:id,name')->oldest()->get()
            ->map(fn (ServiceCall $c) => [
                'id' => $c->id, 'table' => $c->table->name, 'table_id' => $c->table_id, 'reason' => $c->reason, 'reason_label' => $c->reasonLabel(),
                'repeats' => $c->repeats, 'at' => $c->created_at?->toIso8601String() ?? '', 'rang_at' => ($c->last_rang_at ?? $c->created_at)?->toIso8601String() ?? '',
            ])->values()->all();
    }

    public function request(Table $table, string $reason): ServiceCall
    {
        if (! $this->enabled()) {
            throw new OrderException('Panggil waiter belum diaktifkan.');
        }
        $reason = array_key_exists($reason, ServiceCall::REASONS) ? $reason : 'help';

        return DB::transaction(function () use ($table, $reason) {
            $call = ServiceCall::query()->where('table_id', $table->id)->where('status', 'pending')->lockForUpdate()->latest('id')->first();
            if ($call !== null) {
                if ($call->last_rang_at === null || $call->last_rang_at->lt(now()->subSeconds(self::RERING_SECONDS))) {
                    $call->update(['reason' => $reason, 'repeats' => $call->repeats + 1, 'last_rang_at' => now()]);
                    $this->announce($call, $table);
                }

                return $call;
            }

            $call = ServiceCall::query()->create(['outlet_id' => $table->outlet_id, 'table_id' => $table->id, 'reason' => $reason, 'status' => 'pending', 'repeats' => 0, 'last_rang_at' => now()]);
            $this->announce($call, $table);

            return $call;
        });
    }

    public function resolve(ServiceCall $call, User $user): ServiceCall
    {
        return DB::transaction(function () use ($call, $user) {
            $call = ServiceCall::query()->lockForUpdate()->findOrFail($call->id);
            if ($call->status === 'pending') {
                $call->update(['status' => 'handled', 'handled_by' => $user->id, 'handled_at' => now()]);
                $table = Table::query()->findOrFail($call->table_id);
                Audit::log('call.handled', $call, ['table' => $table->name, 'reason' => $call->reason], $user);
                $this->announce($call, $table);
            }

            return $call;
        });
    }

    private function announce(ServiceCall $call, Table $table): void
    {
        Realtime::dispatch(new ServiceCallChanged($call->outlet_id, $call->id, $table->name, $call->status, $call->reason));
    }
}
