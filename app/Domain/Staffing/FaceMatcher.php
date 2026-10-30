<?php

namespace App\Domain\Staffing;

use App\Domain\Staffing\Models\FaceTemplate;
use App\Models\User;

/**
 * Checks a 128-number face descriptor. Distances are Euclidean (face-api: the same person is usually under 0.45, 0.6 is
 * the library's loose default). We are stricter and also refuse when another person is as close as the claimed one.
 */
class FaceMatcher
{
    public const THRESHOLD = 0.5;

    /** Everybody else must be at least this much farther than the claimed person, or the face is too ambiguous to book. */
    public const MARGIN = 0.05;

    /** A new face closer than this to someone already enrolled is the same person (or a look-alike): refused. */
    public const DUPLICATE = 0.45;

    /**
     * Is this face the face of the person who says they are at the camera? A 1:1 check against that person's own samples,
     * and also against everybody else: if someone else is as close or closer, it is refused. So a person showing a
     * colleague's face (or a colleague's photo) is never booked as themselves, and the colleague is never booked at all.
     *
     * @param  list<float>  $descriptor
     * @return array{ok: bool, reason: 'not_enrolled'|'mismatch'|'ambiguous'|null, distance: float|null}
     */
    public function verify(int $outletId, int $userId, array $descriptor): array
    {
        $near = $this->nearestPerUser($outletId, $descriptor, null);
        if (! isset($near[$userId])) {
            return ['ok' => false, 'reason' => 'not_enrolled', 'distance' => null];
        }
        $own = $near[$userId];
        if ($own > self::THRESHOLD) {
            return ['ok' => false, 'reason' => 'mismatch', 'distance' => round($own, 4)];
        }
        unset($near[$userId]);
        $other = $near === [] ? null : min($near);
        if ($other !== null && $other < $own + self::MARGIN) {
            return ['ok' => false, 'reason' => $other < $own ? 'mismatch' : 'ambiguous', 'distance' => round($own, 4)];
        }

        return ['ok' => true, 'reason' => null, 'distance' => round($own, 4)];
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
