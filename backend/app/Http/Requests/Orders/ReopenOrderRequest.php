<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\ReopenOrderInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class ReopenOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_REOPEN);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    public function toInput(): ReopenOrderInput
    {
        return new ReopenOrderInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            reason: $this->string('reason')->toString(),
            actorId: $this->user()->id,
        );
    }
}
