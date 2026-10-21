<?php

namespace App\Domain\Import;

use App\Models\User;

final class ImportContext
{
    /** @param  array<string, mixed>  $extra  values of the importer's extra fields (e.g. supplier, date) */
    public function __construct(public int $outletId, public User $user, public array $extra = []) {}
}
