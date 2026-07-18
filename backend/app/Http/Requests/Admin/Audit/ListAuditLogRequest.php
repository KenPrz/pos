<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Audit;

use App\Actions\Admin\Audit\ListAuditLogInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class ListAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::AUDIT_VIEW);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'entity_type' => ['sometimes', 'string'],
            'entity_id' => ['sometimes', 'uuid'],
            'user_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function toInput(): ListAuditLogInput
    {
        return new ListAuditLogInput(
            entityType: $this->filled('entity_type') ? $this->string('entity_type')->toString() : null,
            entityId: $this->filled('entity_id') ? $this->string('entity_id')->toString() : null,
            userId: $this->filled('user_id') ? $this->string('user_id')->toString() : null,
            action: $this->filled('action') ? $this->string('action')->toString() : null,
            from: $this->filled('from') ? $this->string('from')->toString() : null,
            to: $this->filled('to') ? $this->string('to')->toString() : null,
            page: $this->integer('page', 1),
        );
    }
}
