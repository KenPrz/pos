<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Actions\Auth\EnrollRegisterInput;
use App\Domain\Rbac\Permissions;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class EnrollRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::REGISTER_ENROLL) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    public function toInput(): EnrollRegisterInput
    {
        /** @var User $actor */
        $actor = $this->user();

        return new EnrollRegisterInput(
            locationId: $this->string('location_id')->toString(),
            name: $this->string('name')->toString(),
            actorId: $actor->id,
        );
    }
}
