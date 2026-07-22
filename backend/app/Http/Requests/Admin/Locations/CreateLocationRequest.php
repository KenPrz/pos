<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Locations;

use App\Actions\Admin\Locations\CreateLocationInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class CreateLocationRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::LOCATION_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            // 'DT' — short, on receipts. NOT NULL + unique at the DB (locations migration);
            // not called out in the interface spec, but required for the insert to succeed.
            'code' => ['required', 'string', 'max:20', 'unique:locations,code'],
            // PHP 8.5 / Laravel 13 support the `timezone:all` group form natively.
            'timezone' => ['required', 'string', 'timezone:all'],
            'prices_include_tax' => ['sometimes', 'boolean'],
            'receipt_header' => ['nullable', 'string'],
            'receipt_footer' => ['nullable', 'string'],
            'variance_approval_threshold_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function toInput(): CreateLocationInput
    {
        return new CreateLocationInput(
            name: $this->string('name')->toString(),
            code: $this->string('code')->toString(),
            timezone: $this->string('timezone')->toString(),
            pricesIncludeTax: $this->boolean('prices_include_tax', false),
            receiptHeader: $this->input('receipt_header'),
            receiptFooter: $this->input('receipt_footer'),
            varianceApprovalThresholdCents: $this->input('variance_approval_threshold_cents'),
            lowStockThreshold: $this->input('low_stock_threshold') === null ? null : (string) $this->input('low_stock_threshold'),
            actorId: $this->user()->id,
        );
    }
}
