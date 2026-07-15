<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\StaffLogin;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Resources\StaffSessionResource;

final class StaffLoginController
{
    public function __invoke(StaffLoginRequest $request, StaffLogin $action): StaffSessionResource
    {
        return StaffSessionResource::make($action->execute($request->toInput()));
    }
}
