<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\Outlet;

/**
 * Single-outlet MVP: resolve the active outlet. Tables still carry outlet_id.
 */
class OutletContext
{
    private ?Outlet $outlet = null;

    public function get(): Outlet
    {
        return $this->outlet ??= Outlet::query()->orderBy('id')->firstOrFail();
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function forget(): void
    {
        $this->outlet = null;
    }
}
