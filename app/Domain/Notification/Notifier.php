<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Events\NotificationCreated;
use App\Domain\Notification\Models\AppNotification;
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

        return $n;
    }

    /** The thing the alert was about is dealt with: take it off everyone's list. */
    public function resolve(int $outletId, string $dedupeKey): void
    {
        AppNotification::query()->where('outlet_id', $outletId)->where('dedupe_key', $dedupeKey)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    /** @return Builder<AppNotification> */
    public function visibleTo(User $user): Builder
    {
        $permissions = $user->getAllPermissions()->pluck('name')->all();

        return AppNotification::query()->where('outlet_id', $user->outlet_id)->whereNull('resolved_at')->where(function (Builder $q) use ($user, $permissions) {
            $q->where('user_id', $user->id)->orWhere(function (Builder $q2) use ($permissions) {
                $q2->whereNull('user_id')->where(function (Builder $q3) use ($permissions) {
                    $q3->whereNull('permissions');
                    foreach ($permissions as $permission) {
                        $q3->orWhereJsonContains('permissions', $permission);
                    }
                });
            });
        });
    }

    /** @return array<string, mixed> */
    public function feed(User $user, int $limit = 30): array
    {
        $read = DB::table('notification_reads')->where('user_id', $user->id)->pluck('notification_id')->flip();
        $items = $this->visibleTo($user)->latest('id')->limit($limit)->get();

        return [
            'unread' => $this->visibleTo($user)->whereNotIn('id', DB::table('notification_reads')->where('user_id', $user->id)->select('notification_id'))->count(),
            'items' => $items->map(fn (AppNotification $n) => [
                'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url, 'level' => $n->level,
                'at' => $n->created_at?->toIso8601String(), 'read' => $read->has($n->id),
            ])->values()->all(),
        ];
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
