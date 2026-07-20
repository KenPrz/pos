<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ActivateRegister;
use App\Http\Requests\Auth\ActivateRegisterRequest;
use App\Http\Resources\EnrolledRegisterResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ActivateRegisterController
{
    public function __invoke(ActivateRegisterRequest $request, ActivateRegister $action): JsonResponse
    {
        return EnrolledRegisterResource::make($action->execute($request->toInput()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
