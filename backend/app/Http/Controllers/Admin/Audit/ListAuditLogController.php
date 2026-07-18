<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Audit;

use App\Actions\Admin\Audit\ListAuditLog;
use App\Http\Requests\Admin\Audit\ListAuditLogRequest;
use App\Http\Resources\Admin\AdminAuditResource;

final class ListAuditLogController
{
    public function __invoke(ListAuditLogRequest $request, ListAuditLog $action): AdminAuditResource
    {
        return new AdminAuditResource($action->execute($request->toInput()));
    }
}
