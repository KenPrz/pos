<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\EnrollRegister;
use App\Http\Requests\Auth\EnrollRegisterRequest;
use App\Http\Resources\EnrolledRegisterResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class EnrollRegisterController
{
    public function __invoke(EnrollRegisterRequest $request, EnrollRegister $action): JsonResponse
    {
        return EnrolledRegisterResource::make($action->execute($request->toInput()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
