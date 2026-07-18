<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Actions\Admin\Reports\StockReport;
use App\Http\Requests\Admin\Reports\StockReportRequest;
use App\Http\Resources\Admin\StockReportResource;

final class StockReportController
{
    public function __invoke(StockReportRequest $request, StockReport $action): StockReportResource
    {
        return new StockReportResource($action->execute($request->toInput()));
    }
}
