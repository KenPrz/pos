<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\ListPaymentMethodGroups;
use App\Http\Requests\Admin\PaymentMethods\ListPaymentMethodGroupsRequest;
use App\Http\Resources\Admin\AdminPaymentMethodGroupResource;
use Illuminate\Http\JsonResponse;

final class ListPaymentMethodGroupsController
{
    public function __invoke(ListPaymentMethodGroupsRequest $request, ListPaymentMethodGroups $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminPaymentMethodGroupResource::collection($action->execute($request->toInput()))],
        ]);
    }
}
