<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\StaffLogout;
use App\Http\Middleware\EnsureStaffSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class StaffLogoutController
{
    public function __invoke(Request $request, StaffLogout $action): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->attributes->get(EnsureStaffSession::STAFF_TOKEN);

        $action->execute($token);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
