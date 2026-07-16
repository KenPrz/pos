<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\LookupBarcode;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\ResolvedVariantResource;
use Illuminate\Http\Request;

final class LookupBarcodeController
{
    public function __invoke(Request $request, LookupBarcode $action): ResolvedVariantResource
    {
        $request->validate(['barcode' => ['required', 'string']]);

        return new ResolvedVariantResource($action->execute(
            $request->query('barcode'),
            $request->attributes->get(EnsureDeviceToken::REGISTER)->location_id,
        ));
    }
}
