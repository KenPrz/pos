<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;

it('carries the rbac-v2 permissions in the catalog', function (): void {
    expect(Permissions::all())
        ->toContain('report.stock.view')
        ->toContain('settings.manage')
        ->toContain('role.manage');
    expect(Permissions::supervisor())->toContain('report.stock.view');
    // grouped() covers the whole catalog, no strays
    expect(collect(Permissions::grouped())->flatten()->sort()->values()->all())
        ->toBe(collect(Permissions::all())->sort()->values()->all());
});
