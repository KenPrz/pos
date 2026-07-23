<?php
// backend/app/Http/Requests/Admin/Day/ReopenBusinessDayRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Admin\Day;

use App\Actions\Admin\Day\ReopenBusinessDayInput;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ReopenBusinessDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->is_admin;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function toInput(): ReopenBusinessDayInput
    {
        $locationId = (string) $this->route('location');
        $date = $this->string('date')->toString();
        if ($date === '') {
            $date = now(Location::query()->findOrFail($locationId)->timezone)->toDateString();
        }

        return new ReopenBusinessDayInput(
            locationId: $locationId,
            businessDate: $date,
            reason: $this->string('reason')->toString(),
            actorId: (string) $this->user()->id,
        );
    }
}
