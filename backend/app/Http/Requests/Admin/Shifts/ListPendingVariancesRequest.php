<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Shifts;

use App\Actions\Admin\Shifts\ListPendingVariancesInput;
use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ListPendingVariancesRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::SHIFT_APPROVE_VARIANCE);
    }

    public function rules(): array
    {
        return [];
    }

    public function toInput(): ListPendingVariancesInput
    {
        /** @var User $user authorize() already required allowsBackOffice, so this is a User */
        $user = $this->user();

        // No location_id parameter, so unlike the other admin lists there is no
        // withValidator scoping check to write — the scope IS the caller's held
        // locations: null for an admin (every location), otherwise exactly where they
        // hold the permission.
        return new ListPendingVariancesInput(
            locationIds: app(AdminAccess::class)->locationIdsWhere($user, Permissions::SHIFT_APPROVE_VARIANCE),
        );
    }
}
