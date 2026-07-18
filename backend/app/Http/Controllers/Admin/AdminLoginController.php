<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AdminLogin;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Http\Resources\Admin\AdminSessionResource;

final class AdminLoginController
{
    public function __invoke(AdminLoginRequest $request, AdminLogin $action): AdminSessionResource
    {
        return AdminSessionResource::make($action->execute($request->toInput()));
    }
}
