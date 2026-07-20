<?php

declare(strict_types=1);

namespace App\Http\Requests\Drawer;

use App\Actions\Drawer\OpenDrawerNoSaleInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class OpenDrawerNoSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::DRAWER_NO_SALE);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    public function toInput(): OpenDrawerNoSaleInput
    {
        return new OpenDrawerNoSaleInput(
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            reason: $this->string('reason')->toString(),
            actorId: $this->user()->id,
        );
    }
}
