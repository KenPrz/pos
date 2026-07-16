<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\GetReceipt;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\ReceiptResource;
use Illuminate\Http\Request;

final class ReceiptController
{
    public function __invoke(Request $request, GetReceipt $action): ReceiptResource
    {
        return new ReceiptResource($action->execute(
            (string) $request->route('order'),
            $request->attributes->get(EnsureDeviceToken::REGISTER)->id,
        ));
    }
}
