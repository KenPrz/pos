<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Actions\Admin\AdminSession;
use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminSession */
final class AdminSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AdminSession $session */
        $session = $this->resource;
        $user = $session->user;
        $access = app(AdminAccess::class);

        // null = every location (admin); otherwise the union of everywhere a report
        // permission is held — the client uses it to filter the location switcher down
        // to what this session can actually see reports for.
        $reportLocations = $user->is_admin ? null : array_values(array_unique([
            ...($access->locationIdsWhere($user, Permissions::REPORT_SALES_VIEW) ?? []),
            ...($access->locationIdsWhere($user, Permissions::REPORT_STOCK_VIEW) ?? []),
        ]));

        return [
            'token' => $session->token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
            ],
            'sections' => $access->sectionsFor($user),
            'report_location_ids' => $reportLocations,
            'currency' => config('pos.currency'),
        ];
    }
}
