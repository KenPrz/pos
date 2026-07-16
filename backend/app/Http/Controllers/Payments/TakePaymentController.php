<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\TakePayment;
use App\Http\Requests\Payments\TakePaymentRequest;
use App\Http\Resources\TakePaymentResource;
use Illuminate\Http\JsonResponse;

final class TakePaymentController
{
    public function __invoke(TakePaymentRequest $request, TakePayment $action): JsonResponse
    {
        return (new TakePaymentResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
