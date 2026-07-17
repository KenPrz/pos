<?php

declare(strict_types=1);

namespace App\Http\Requests\Shifts;

use App\Actions\Shifts\ApproveVarianceInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class ApproveVarianceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::SHIFT_APPROVE_VARIANCE);
    }

    public function rules(): array
    {
        return [];
    }

    public function toInput(): ApproveVarianceInput
    {
        return new ApproveVarianceInput(
            shiftId: (string) $this->route('shift'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            actorId: $this->user()->id,
        );
    }
}
