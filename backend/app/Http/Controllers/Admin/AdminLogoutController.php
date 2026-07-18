<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AdminLogout;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminLogoutController
{
    public function __invoke(Request $request, AdminLogout $action): Response
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        $action->execute($user, (string) $token->getKey());

        return response()->noContent();
    }
}
