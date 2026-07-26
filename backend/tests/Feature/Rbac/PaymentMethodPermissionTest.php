<?php

declare(strict_types=1);

use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;

it('is in the permission catalog and the admin section list', function (): void {
    expect(Permissions::all())->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(AdminAccess::SECTIONS)->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(Permissions::grouped()['Administration'])->toContain(Permissions::PAYMENT_METHOD_MANAGE);
});

it('is granted by no default role and is not a money-leaves permission', function (): void {
    // Only admins configure tenders, and admins bypass the gate entirely (Gate::before)
    // — same shape as catalog.manage and day.close. Naming a tender does not move money
    // out of a till; taking one is still gated by payment.take.
    expect(Permissions::cashier())->not->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(Permissions::supervisor())->not->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(Permissions::moneyLeaves())->not->toContain(Permissions::PAYMENT_METHOD_MANAGE);
});
