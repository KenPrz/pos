<?php
// backend/app/Http/Controllers/Admin/Day/GetBusinessDayController.php
declare(strict_types=1);

namespace App\Http\Controllers\Admin\Day;

use App\Actions\Admin\Day\GetBusinessDay;
use App\Http\Requests\Admin\Day\GetBusinessDayRequest;
use App\Http\Resources\Admin\BusinessDayResource;
use Illuminate\Http\JsonResponse;

final class GetBusinessDayController
{
    public function __invoke(GetBusinessDayRequest $request, GetBusinessDay $action): JsonResponse
    {
        $view = $action->execute($request->toInput());
        $snap = $view->snapshot;

        return response()->json(['data' => [
            'business_date' => $view->business_date,
            'location_today' => $view->location_today,
            'closable' => $view->closable,
            'open_shifts' => $view->open_shifts,
            'open_orders_count' => $view->open_orders_count,
            'unapproved_variance_count' => $view->unapproved_variance_count,
            'totals' => [
                'gross_sales_cents' => $snap->grossSalesCents,
                'refunds_cents' => $snap->refundsCents,
                'net_sales_cents' => $snap->netSalesCents,
                'tax_cents' => $snap->taxCents,
                'expected_cash_cents' => $snap->expectedCashCents,
                'counted_cash_cents' => $snap->countedCashCents,
                'variance_cents' => $snap->varianceCents,
                'shift_count' => $snap->shiftCount,
            ],
            'record' => $view->record ? (new BusinessDayResource($view->record))->toArray($request) : null,
        ]]);
    }
}
