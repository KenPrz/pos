<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Registers;

use App\Actions\Admin\Registers\IssueActivationCodeInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class IssueActivationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REGISTER_ENROLL);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }

    public function toInput(): IssueActivationCodeInput
    {
        return new IssueActivationCodeInput(
            registerId: (string) $this->route('register'),
            actorId: $this->user()->id,
        );
    }
}
