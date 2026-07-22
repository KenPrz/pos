<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reports;

use App\Actions\Admin\Reports\StockReportInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class StockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REPORT_STOCK_VIEW);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            // Deliberately not a 'boolean' rule: a query string sends the literal text
            // "true"/"false", which Laravel's strict boolean rule rejects outright.
            // $this->boolean() below (filter_var under the hood) is what actually
            // accepts it.
            'low_only' => ['sometimes', 'string'],
        ];
    }

    public function toInput(): StockReportInput
    {
        return new StockReportInput(
            locationId: $this->string('location_id')->toString(),
            lowOnly: $this->boolean('low_only'),
        );
    }
}
