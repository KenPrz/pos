<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Actions\Admin\Reports\SalesReport;
use App\Http\Requests\Admin\Reports\SalesReportRequest;
use App\Http\Resources\Admin\SalesReportResource;

final class SalesReportController
{
    public function __invoke(SalesReportRequest $request, SalesReport $action): SalesReportResource
    {
        return new SalesReportResource($action->execute($request->toInput()));
    }
}
