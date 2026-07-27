<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethodGroup;
use App\Http\Requests\Admin\PaymentMethods\CreatePaymentMethodGroupRequest;
use App\Http\Resources\Admin\AdminPaymentMethodGroupResource;
use Illuminate\Http\JsonResponse;

final class CreatePaymentMethodGroupController
{
    public function __invoke(CreatePaymentMethodGroupRequest $request, CreatePaymentMethodGroup $action): JsonResponse
    {
        return response()->json([
            'data' => ['payment_method_group' => new AdminPaymentMethodGroupResource($action->execute($request->toInput()))],
        ], 201);
    }
}
