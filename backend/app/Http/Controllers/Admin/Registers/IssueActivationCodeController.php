<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Registers;

use App\Actions\Admin\Registers\IssueActivationCode;
use App\Http\Requests\Admin\Registers\IssueActivationCodeRequest;
use Illuminate\Http\JsonResponse;

final class IssueActivationCodeController
{
    public function __invoke(IssueActivationCodeRequest $request, IssueActivationCode $action): JsonResponse
    {
        $issued = $action->execute($request->toInput());

        return response()->json([
            'data' => [
                // Shown exactly once. Never retrievable again — only supersedable.
                'activation_code' => $issued->activationCode,
                'expires_at' => $issued->register->activation_code_expires_at?->toIso8601String(),
            ],
        ], 201);
    }
}
