<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class GetZReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REPORT_Z_VIEW);
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'uuid'],
        ];
    }

    public function shiftId(): string
    {
        return $this->string('shift_id')->toString();
    }

    public function registerId(): string
    {
        return $this->attributes->get(EnsureDeviceToken::REGISTER)->id;
    }
}
