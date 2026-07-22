<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Actions\Admin\Settings\UpdateSettingsInput;
use App\Domain\Rbac\Permissions;
use App\Domain\Settings\Settings;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSettingsRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
        ];
    }

    /**
     * Registry keys are dotted strings (`business.name`), which collide with Laravel's
     * own dot-notation for nested array rules (`settings.*` can't tell a literal dot in
     * a key apart from a nested attribute). Validated by hand instead, one key at a time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! is_array($this->input('settings'))) {
                return;
            }

            foreach ($this->input('settings', []) as $key => $value) {
                if (! in_array($key, array_keys(Settings::REGISTRY), true)) {
                    $validator->errors()->add("settings.{$key}", "Unregistered setting key: {$key}.");

                    continue;
                }

                if ($value !== null && ! is_string($value)) {
                    $validator->errors()->add("settings.{$key}", 'The value must be a string.');

                    continue;
                }

                if (is_string($value) && mb_strlen($value) > 500) {
                    $validator->errors()->add("settings.{$key}", 'The value must not exceed 500 characters.');
                }
            }
        });
    }

    public function toInput(): UpdateSettingsInput
    {
        return new UpdateSettingsInput(
            changes: $this->input('settings', []),
            actorId: $this->user()->id,
        );
    }
}
