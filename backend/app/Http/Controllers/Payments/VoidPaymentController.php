<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\VoidPayment;
use App\Http\Requests\Payments\VoidPaymentRequest;
use App\Http\Resources\TakePaymentResource;
use Illuminate\Http\JsonResponse;

final class VoidPaymentController
{
    public function __invoke(VoidPaymentRequest $request, VoidPayment $action): JsonResponse
    {
        return (new TakePaymentResource($action->execute($request->toInput())))->response();
    }
}
