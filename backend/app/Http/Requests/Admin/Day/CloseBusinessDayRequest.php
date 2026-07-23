<?php
// backend/app/Http/Requests/Admin/Day/CloseBusinessDayRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Admin\Day;

use App\Actions\Admin\Day\CloseBusinessDayInput;
use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CloseBusinessDayRequest extends FormRequest
{
    use AuthorizesBackOffice;

    /** @var list<string> */
    private const CHECKLIST_KEYS = ['cash_drop_confirmed', 'spoilage_note', 'next_day_note'];

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::DAY_CLOSE);
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'deposit_cents' => ['required', 'integer', 'min:0'],
            'checklist' => ['present', 'array'],
            'checklist.cash_drop_confirmed' => ['sometimes', 'boolean'],
            'checklist.spoilage_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'checklist.next_day_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            if ($user instanceof User && ! $user->is_admin) {
                $allowed = app(AdminAccess::class)->locationIdsWhere($user, Permissions::DAY_CLOSE) ?? [];
                if (! in_array($this->route('location'), $allowed, true)) {
                    throw new AuthorizationException;
                }
            }

            // "Today" is the LOCATION'S local today, never the server's — a naive
            // before_or_equal:today rule would compare against the wrong clock. A future
            // date here would freeze a day that hasn't happened yet and block OpenShift
            // on it, so reject rather than silently accepting a typo.
            $date = $this->string('date')->toString();
            if ($date !== '') {
                $location = Location::query()->find((string) $this->route('location'));
                if ($location !== null && $date > now($location->timezone)->toDateString()) {
                    $validator->errors()->add('date', 'The date may not be in the future.');
                }
            }

            // `checklist` is fixed-key (docs/02-data-model.md) — 'present','array' alone
            // lets arbitrary extra keys ride into the jsonb column, so reject anything
            // outside the three known ones explicitly.
            $checklist = $this->input('checklist');
            if (is_array($checklist)) {
                $unknown = array_diff(array_keys($checklist), self::CHECKLIST_KEYS);
                if ($unknown !== []) {
                    $validator->errors()->add('checklist', 'Unknown checklist key(s): '.implode(', ', $unknown).'.');
                }
            }
        });
    }

    public function toInput(): CloseBusinessDayInput
    {
        $locationId = (string) $this->route('location');
        $date = $this->string('date')->toString();
        if ($date === '') {
            $date = now(Location::query()->findOrFail($locationId)->timezone)->toDateString();
        }

        /** @var array<string, mixed> $checklist */
        $checklist = $this->input('checklist', []);

        return new CloseBusinessDayInput(
            locationId: $locationId,
            businessDate: $date,
            depositCents: (int) $this->input('deposit_cents'),
            checklist: $checklist,
            note: $this->input('note'),
            actorId: (string) $this->user()->id,
        );
    }
}
