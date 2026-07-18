<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\ApproveVariance;
use App\Http\Requests\Shifts\ApproveVarianceRequest;
use App\Http\Resources\ApproveVarianceResource;

final class ApproveVarianceController
{
    public function __invoke(ApproveVarianceRequest $request, ApproveVariance $action): ApproveVarianceResource
    {
        return new ApproveVarianceResource($action->execute($request->toInput()));
    }
}
