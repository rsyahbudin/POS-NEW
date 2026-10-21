<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Events\NotificationCreated;
use App\Domain\Notification\Models\AppNotification;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates and reads in-app notifications. Who sees one is decided by permission, so a role gets exactly the
 * alerts of the things it is allowed to act on (Peran & Akses), or by user for a result sent to one person.
 */
class Notifier
{
    /** Runs after the surrounding transaction commits and never breaks the action that raised it. */
    public static function later(Closure $fn): void
    {
        DB::afterCommit(function () use ($fn) {
            try {
                $fn(app(self::class));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * @param  array<int, string>|null  $permissions  any of these permissions sees it; null = every signed-in staff member
     */
    public function notify(
        int $outletId, string $kind, string $title, ?string $body = null, ?string $url = null, string $level = 'info',
        ?array $permissions = null, ?int $userId = null, ?string $dedupeKey = null,
    ): AppNotification {
        if ($dedupeKey !== null) {
            $open = AppNotification::query()->where('outlet_id', $outletId)->where('dedupe_key', $dedupeKey)->whereNull('resolved_at')->first();
            if ($open !== null) {
                return $open;
            }
        }
        $n = AppNotification::create([
            'outlet_id' => $outletId, 'kind' => $kind, 'title' => $title, 'body' => $body, 'url' => $url, 'level' => $level,
            'permissions' => $permissions, 'user_id' => $userId, 'dedupe_key' => $dedupeKey,
        ]);
        try {
            event(new NotificationCreated($outletId));
        } catch (Throwable $e) {
            report($e);
        }
        $this->forward($n);

        return $n;
    }

    /** Important alerts for the owner also go to Telegram / email / WhatsApp when switched on. */
    private function forward(AppNotification $n): void
    {
        if ($n->user_id !== null || ! in_array($n->level, ['warning', 'danger'], true) || ! in_array($n->kind, ['stock.low', 'shift.difference', 'shift.forgotten', 'asset.attention'], true)) {
            return;
        }
        try {
            if ((bool) app(SettingsRepository::class)->get('notify.forward_alerts', false)) {
                app(ReportDelivery::class)->deliver('Peringatan: '.$n->title, (string) $n->body);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The thing the alert was about is dealt with: take it off everyone's list. */
    public function resolve(int $outletId, string $dedupeKey): void
    {
        AppNotification::query()->where('outlet_id', $outletId)->where('dedupe_key', $dedupeKey)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    /** Notifications older than this are gone from the bell (and deleted by the nightly prune). */
    public const WINDOW_DAYS = 30;

    /**
     * What this user may see: addressed to them, or targeted at a permission they hold. One jsonb "any of"
     * check instead of one condition per permission, and only the recent window.
     *
     * @return Builder<AppNotification>
     */
    public function visibleTo(User $user): Builder
    {
        $permissions = array_values($user->getAllPermissions()->pluck('name')->all());

        return AppNotification::query()->where('app_notifications.outlet_id', $user->outlet_id)->whereNull('app_notifications.resolved_at')
            ->where('app_notifications.created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where(function (Builder $q) use ($user, $permissions) {
                $q->where('app_notifications.user_id', $user->id)->orWhere(function (Builder $q2) use ($permissions) {
                    $q2->whereNull('app_notifications.user_id')->where(function (Builder $q3) use ($permissions) {
                        $q3->whereNull('app_notifications.permissions');
                        if ($permissions !== []) {
                            $q3->orWhereRaw('jsonb_exists_any(app_notifications.permissions, ARRAY['.implode(',', array_fill(0, count($permissions), '?')).']::text[])', $permissions);
                        }
                    });
                });
            });
    }

    /** @return array<string, mixed> */
    public function feed(User $user, int $limit = 30): array
    {
        $rows = $this->visibleTo($user)
            ->leftJoin('notification_reads as r', fn ($j) => $j->on('r.notification_id', '=', 'app_notifications.id')->where('r.user_id', '=', $user->id))
            ->select('app_notifications.*', 'r.read_at')
            ->orderByDesc('app_notifications.id')->limit($limit)->get();
        $unread = $this->visibleTo($user)->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('notification_reads as r')->whereColumn('r.notification_id', 'app_notifications.id')->where('r.user_id', $user->id),
        )->count();

        return [
            'unread' => $unread,
            'items' => $rows->map(fn (AppNotification $n) => [
                'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url, 'level' => $n->level,
                'at' => $n->created_at?->toIso8601String(), 'read' => $n->getAttribute('read_at') !== null,
            ])->values()->all(),
        ];
    }

    /** Deletes what nobody needs any more; returns how many rows went. */
    public function prune(): int
    {
        return AppNotification::query()->where(function (Builder $q) {
            $q->where('resolved_at', '<', now()->subDays(3))
                ->orWhere('created_at', '<', now()->subDays(self::WINDOW_DAYS))
                ->orWhere(fn (Builder $i) => $i->where('level', 'info')->where('created_at', '<', now()->subDays(7)));
        })->delete();
    }

    public function markRead(User $user, int $id): void
    {
        if ($this->visibleTo($user)->whereKey($id)->exists()) {
            DB::table('notification_reads')->insertOrIgnore(['notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()]);
        }
    }

    public function markAllRead(User $user): void
    {
        $ids = $this->visibleTo($user)->pluck('id');
        $rows = $ids->map(fn ($id) => ['notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()])->all();
        if ($rows !== []) {
            DB::table('notification_reads')->insertOrIgnore($rows);
        }
    }
}
