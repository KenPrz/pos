<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\GetCatalog;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\CatalogResource;
use Illuminate\Http\Request;

final class GetCatalogController
{
    public function __invoke(Request $request, GetCatalog $action): CatalogResource
    {
        return new CatalogResource($action->execute($request->attributes->get(EnsureDeviceToken::REGISTER)->location_id));
    }
}
