<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Locations;

use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class ListLocationsRequest extends FormRequest
{
    use AuthorizesBackOffice;

    /**
     * Location names are low-sensitivity reference data every permitted section
     * composes from (the location switcher, UserEditor, reports) — any admin-tier
     * section is enough to read them, not location.manage specifically.
     */
    public function authorize(): bool
    {
        return $this->allowsAnyBackOfficeSection();
    }

    public function rules(): array
    {
        return [];
    }
}
