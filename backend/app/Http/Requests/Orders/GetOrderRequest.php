<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reads carry a gate too: an endpoint with no permission is a bug in either the route
 * list or the permission catalog (docs/05-rbac.md). ORDER_OPEN is the same floor
 * ListOrders uses — recalling an order is part of running a till.
 */
final class GetOrderRequest extends FormRequest
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
