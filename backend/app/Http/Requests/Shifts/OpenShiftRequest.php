<?php

declare(strict_types=1);

namespace App\Http\Requests\Shifts;

use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::SHIFT_OPEN);
    }

    public function rules(): array
    {
        return [
            'opening_float_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): OpenShiftInput
    {
        return new OpenShiftInput(
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            openingFloatCents: $this->integer('opening_float_cents'),
            actorId: $this->user()->id,
        );
    }
}
