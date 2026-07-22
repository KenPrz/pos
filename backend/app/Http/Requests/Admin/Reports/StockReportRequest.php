<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reports;

use App\Actions\Admin\Reports\StockReportInput;
use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class StockReportRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::REPORT_STOCK_VIEW);
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

    /**
     * Report permissions are location-scoped even though the back-office login gate
     * (AdminAccess::holdsAnywhere) is "anywhere" — a stock-only grant at location A must
     * not read location B's stock report. Admins skip this: their locationIdsWhere() is
     * null (all locations), by definition.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (): void {
            $user = $this->user();
            if ($user instanceof User && ! $user->is_admin) {
                $allowed = app(AdminAccess::class)->locationIdsWhere($user, Permissions::REPORT_STOCK_VIEW) ?? [];
                if (! in_array($this->input('location_id'), $allowed, true)) {
                    throw new AuthorizationException;
                }
            }
        });
    }

    public function toInput(): StockReportInput
    {
        return new StockReportInput(
            locationId: $this->string('location_id')->toString(),
            lowOnly: $this->boolean('low_only'),
        );
    }
}
