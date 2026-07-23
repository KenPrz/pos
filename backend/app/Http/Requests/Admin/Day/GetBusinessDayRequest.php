<?php
// backend/app/Http/Requests/Admin/Day/GetBusinessDayRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Admin\Day;

use App\Actions\Admin\Day\GetBusinessDayInput;
use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class GetBusinessDayRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::DAY_CLOSE);
    }

    public function rules(): array
    {
        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (): void {
            $user = $this->user();
            if ($user instanceof User && ! $user->is_admin) {
                $allowed = app(AdminAccess::class)->locationIdsWhere($user, Permissions::DAY_CLOSE) ?? [];
                if (! in_array($this->route('location'), $allowed, true)) {
                    throw new AuthorizationException;
                }
            }
        });
    }

    public function toInput(): GetBusinessDayInput
    {
        $locationId = (string) $this->route('location');
        $date = $this->string('date')->toString();
        if ($date === '') {
            $tz = Location::query()->findOrFail($locationId)->timezone;
            $date = now($tz)->toDateString();
        }

        return new GetBusinessDayInput(locationId: $locationId, businessDate: $date);
    }
}
