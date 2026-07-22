<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Actions\Admin\Settings\UpdateSettings;
use App\Http\Requests\Admin\Settings\UpdateSettingsRequest;
use Illuminate\Http\JsonResponse;

final class UpdateSettingsController
{
    public function __invoke(UpdateSettingsRequest $request, UpdateSettings $action): JsonResponse
    {
        return response()->json([
            'data' => ['settings' => $action->execute($request->toInput())],
        ]);
    }
}
