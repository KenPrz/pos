<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A lookup, not a browse — cashiers do this constantly (recalling a receipt
        // number, finding their own open tabs), so ORDER_OPEN is the natural floor.
        return $this->user()->can(Permissions::ORDER_OPEN);
    }

    public function rules(): array
    {
        return [
            'number' => ['nullable', 'string'],
            'status' => ['nullable', 'in:open,closed,voided'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query('number') === null && $this->query('status') === null) {
                $validator->errors()->add('number', 'At least one of number or status is required.');
            }
        });
    }

    public function registerId(): string
    {
        return $this->attributes->get(EnsureDeviceToken::REGISTER)->id;
    }
}
