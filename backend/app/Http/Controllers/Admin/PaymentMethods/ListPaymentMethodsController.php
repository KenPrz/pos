<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\ListPaymentMethods;
use App\Http\Requests\Admin\PaymentMethods\ListPaymentMethodsRequest;
use App\Http\Resources\Admin\AdminPaymentMethodResource;
use Illuminate\Http\JsonResponse;

final class ListPaymentMethodsController
{
    public function __invoke(ListPaymentMethodsRequest $request, ListPaymentMethods $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminPaymentMethodResource::collection($action->execute($request->toInput()))],
        ]);
    }
}
