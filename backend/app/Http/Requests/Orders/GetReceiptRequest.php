<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Same floor as GetOrder: reprinting a receipt is till work, gated at ORDER_OPEN.
 */
final class GetReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_OPEN);
    }

    public function rules(): array
    {
        return [];
    }

    public function registerId(): string
    {
        return $this->attributes->get(EnsureDeviceToken::REGISTER)->id;
    }
}
