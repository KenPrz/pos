<?php

declare(strict_types=1);

namespace App\Http\Controllers\Drawer;

use App\Actions\Drawer\OpenDrawerNoSale;
use App\Http\Requests\Drawer\OpenDrawerNoSaleRequest;
use Illuminate\Http\JsonResponse;

final class OpenDrawerNoSaleController
{
    public function __invoke(OpenDrawerNoSaleRequest $request, OpenDrawerNoSale $action): JsonResponse
    {
        $result = $action->execute($request->toInput());

        return response()->json(['data' => [
            'authorized' => $result->authorized,
            'shift_id' => $result->shift_id,
        ]]);
    }
}
