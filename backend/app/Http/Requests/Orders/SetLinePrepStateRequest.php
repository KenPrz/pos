<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\SetLinePrepStateInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class SetLinePrepStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_LINE_UPDATE);
    }

    public function rules(): array
    {
        return [
            'state' => ['required', 'in:pending,in_progress,ready'],
        ];
    }

    public function toInput(): SetLinePrepStateInput
    {
        return new SetLinePrepStateInput(
            orderId: (string) $this->route('order'),
            lineId: (string) $this->route('line'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            state: $this->string('state')->toString(),
            actorId: $this->user()->id,
        );
    }
}
