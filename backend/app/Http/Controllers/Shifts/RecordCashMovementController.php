<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\RecordCashMovement;
use App\Http\Requests\Shifts\RecordCashMovementRequest;
use App\Http\Resources\CashMovementResource;
use Illuminate\Http\JsonResponse;

final class RecordCashMovementController
{
    public function __invoke(RecordCashMovementRequest $request, RecordCashMovement $action): JsonResponse
    {
        return (new CashMovementResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
