<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethod;
use App\Http\Requests\Admin\PaymentMethods\CreatePaymentMethodRequest;
use App\Http\Resources\Admin\AdminPaymentMethodResource;
use Illuminate\Http\JsonResponse;

final class CreatePaymentMethodController
{
    public function __invoke(CreatePaymentMethodRequest $request, CreatePaymentMethod $action): JsonResponse
    {
        return response()->json([
            'data' => ['payment_method' => new AdminPaymentMethodResource($action->execute($request->toInput()))],
        ], 201);
    }
}
