<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\GetReceipt;
use App\Http\Requests\Orders\GetReceiptRequest;
use App\Http\Resources\ReceiptResource;

final class ReceiptController
{
    public function __invoke(GetReceiptRequest $request, GetReceipt $action): ReceiptResource
    {
        return new ReceiptResource($action->execute(
            (string) $request->route('order'),
            $request->registerId(),
        ));
    }
}
