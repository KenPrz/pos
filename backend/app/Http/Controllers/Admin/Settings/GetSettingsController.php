<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Actions\Admin\Settings\GetSettings;
use App\Http\Requests\Admin\Settings\GetSettingsRequest;
use Illuminate\Http\JsonResponse;

final class GetSettingsController
{
    public function __invoke(GetSettingsRequest $request, GetSettings $action): JsonResponse
    {
        return response()->json([
            'data' => ['settings' => $action->execute()],
        ]);
    }
}
