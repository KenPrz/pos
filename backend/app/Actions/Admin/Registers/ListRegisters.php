<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Models\Register;
use Illuminate\Database\Eloquent\Collection;

final class ListRegisters
{
    /** @return Collection<int, Register> */
    public function execute(): Collection
    {
        return Register::query()->orderBy('name')->get();
    }
}
