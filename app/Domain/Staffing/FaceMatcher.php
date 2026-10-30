<?php

namespace App\Domain\Staffing;

use App\Domain\Staffing\Models\FaceTemplate;
use App\Models\User;

/**
 * Finds whose face a 128-number descriptor is. Distances are Euclidean (face-api: the same person is usually under
 * 0.45, 0.6 is the library's loose default). We are stricter and also refuse when two people are almost as close.
 */
class FaceMatcher
{
    public const THRESHOLD = 0.5;

    /** The runner-up must be at least this much farther, or the face is too ambiguous to book. */
    public const MARGIN = 0.05;

    /** A new face closer than this to someone already enrolled is the same person (or a look-alike): refused. */
    public const DUPLICATE = 0.45;

    /**
     * @param  list<float>  $descriptor
     * @return array{user: User, distance: float}|null
     */
    public function match(int $outletId, array $descriptor, ?int $exceptUser = null): ?array
    {
        $best = $this->nearestPerUser($outletId, $descriptor, $exceptUser);
        if ($best === []) {
            return null;
        }
        asort($best);
        $ids = array_keys($best);
        $first = $best[$ids[0]];
        if ($first > self::THRESHOLD) {
            return null;
        }
        if (isset($ids[1]) && $best[$ids[1]] - $first < self::MARGIN) {
            return null;
        }
        $user = User::query()->where('is_active', true)->find($ids[0]);

        return $user === null ? null : ['user' => $user, 'distance' => round($first, 4)];
    }

    /**
     * The user whose face is nearly the same as this one, if any (used to stop one person enrolling as another).
     *
     * @param  list<float>  $descriptor
     */
    public function duplicateOf(int $outletId, array $descriptor, int $exceptUser): ?User
    {
        $best = $this->nearestPerUser($outletId, $descriptor, $exceptUser);
        if ($best === []) {
            return null;
        }
        asort($best);
        $id = array_key_first($best);

        return $best[$id] < self::DUPLICATE ? User::query()->find($id) : null;
    }

    /**
     * @param  list<float>  $descriptor
     * @return array<int, float> user id => smallest distance over that person's samples
     */
    private function nearestPerUser(int $outletId, array $descriptor, ?int $exceptUser): array
    {
        $best = [];
        $query = FaceTemplate::query()->where('outlet_id', $outletId);
        if ($exceptUser !== null) {
            $query->where('user_id', '!=', $exceptUser);
        }
        foreach ($query->get(['user_id', 'descriptor']) as $t) {
            $d = self::distance($descriptor, $t->descriptor);
            if ($d < ($best[$t->user_id] ?? INF)) {
                $best[$t->user_id] = $d;
            }
        }

        return $best;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function distance(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $sum += ($v - ($b[$i] ?? 0.0)) ** 2;
        }

        return sqrt($sum);
    }
}
