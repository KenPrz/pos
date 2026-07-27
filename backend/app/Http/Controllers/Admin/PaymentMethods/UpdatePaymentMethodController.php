<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\UpdatePaymentMethod;
use App\Http\Requests\Admin\PaymentMethods\UpdatePaymentMethodRequest;
use App\Http\Resources\Admin\AdminPaymentMethodResource;
use Illuminate\Http\JsonResponse;

final class UpdatePaymentMethodController
{
    public function __invoke(UpdatePaymentMethodRequest $request, UpdatePaymentMethod $action): JsonResponse
    {
        return response()->json([
            'data' => ['payment_method' => new AdminPaymentMethodResource($action->execute($request->toInput()))],
        ]);
    }
}
