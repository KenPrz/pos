<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\OpenOrderInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class OpenOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_OPEN);
    }

    public function rules(): array
    {
        return [
            'table_ref' => ['nullable', 'string', 'max:20'],
            'customer_id' => ['nullable', 'uuid'],
        ];
    }

    public function toInput(): OpenOrderInput
    {
        return new OpenOrderInput(
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            actorId: $this->user()->id,
            tableRef: $this->string('table_ref')->toString() ?: null,
            customerId: $this->string('customer_id')->toString() ?: null,
        );
    }
}
