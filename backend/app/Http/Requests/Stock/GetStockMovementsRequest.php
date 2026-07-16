<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class GetStockMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::STOCK_MOVEMENTS_VIEW);
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'uuid', 'exists:product_variants,id'],
        ];
    }

    public function variantId(): string
    {
        return $this->string('variant_id')->toString();
    }

    public function registerId(): string
    {
        return $this->attributes->get(EnsureDeviceToken::REGISTER)->id;
    }
}
