<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Registers;

use App\Actions\Admin\Registers\ReissueDeviceTokenInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class ReissueDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REGISTER_ENROLL);
    }

    public function rules(): array
    {
        return [];
    }

    public function toInput(): ReissueDeviceTokenInput
    {
        return new ReissueDeviceTokenInput(
            registerId: (string) $this->route('register'),
            actorId: $this->user()->id,
        );
    }
}
