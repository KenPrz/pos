<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Registers;

use App\Actions\Admin\Registers\UpdateRegisterInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UpdateRegisterRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::REGISTER_ENROLL);
    }

    public function rules(): array
    {
        // Which location a register belongs to is a create-time decision (location_id
        // is prohibited below), so the uniqueness scope is always the register's
        // *current* location, never a client-supplied one.
        $locationId = DB::table('registers')->where('id', $this->route('register'))->value('location_id');

        return [
            'location_id' => ['prohibited'],
            'name' => ['sometimes', 'string', 'max:200',
                Rule::unique('registers', 'name')->where('location_id', $locationId)->ignore($this->route('register'))],
            'mode' => ['sometimes', 'string', 'in:retail,food'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): UpdateRegisterInput
    {
        return new UpdateRegisterInput(
            registerId: (string) $this->route('register'),
            changes: $this->safe()->only(['name', 'mode', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
