<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\GetZReport;
use App\Http\Requests\Reports\GetZReportRequest;
use App\Http\Resources\ZReportResource;

final class GetZReportController
{
    public function __invoke(GetZReportRequest $request, GetZReport $action): ZReportResource
    {
        return new ZReportResource($action->execute($request->shiftId(), $request->registerId()));
    }
}
