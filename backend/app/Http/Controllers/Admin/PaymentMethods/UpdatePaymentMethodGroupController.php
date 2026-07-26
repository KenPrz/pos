<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\UpdatePaymentMethodGroup;
use App\Http\Requests\Admin\PaymentMethods\UpdatePaymentMethodGroupRequest;
use App\Http\Resources\Admin\AdminPaymentMethodGroupResource;
use Illuminate\Http\JsonResponse;

final class UpdatePaymentMethodGroupController
{
    public function __invoke(UpdatePaymentMethodGroupRequest $request, UpdatePaymentMethodGroup $action): JsonResponse
    {
        return response()->json([
            'data' => ['payment_method_group' => new AdminPaymentMethodGroupResource($action->execute($request->toInput()))],
        ]);
    }
}
