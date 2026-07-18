<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reports;

use App\Actions\Admin\Reports\SalesReportInput;
use App\Domain\Rbac\Permissions;
use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class SalesReportRequest extends FormRequest
{
    private const int MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REPORT_SALES_VIEW);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'group_by' => ['required', 'string', 'in:day,category,user'],
        ];
    }

    /**
     * Range checking needs both fields at once, so it can't be a per-field rule. Guarded
     * by try/catch rather than re-validating the format: a malformed date already failed
     * `date_format` above and gets its own message, not this one.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');
            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            try {
                $fromDate = new DateTimeImmutable($from);
                $toDate = new DateTimeImmutable($to);
            } catch (Exception) {
                return;
            }

            if ($toDate < $fromDate) {
                $validator->errors()->add('to', 'to must not be before from.');

                return;
            }

            $days = (int) $fromDate->diff($toDate)->days;
            if ($days > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('to', 'The date range must not exceed '.self::MAX_RANGE_DAYS.' days.');
            }
        });
    }

    public function toInput(): SalesReportInput
    {
        return new SalesReportInput(
            locationId: $this->string('location_id')->toString(),
            from: $this->string('from')->toString(),
            to: $this->string('to')->toString(),
            groupBy: $this->string('group_by')->toString(),
        );
    }
}
