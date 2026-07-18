<?php

// backend/tests/Feature/Shifts/OpenShiftRegistersTest.php
declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Roles;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->acting = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
});

it('lists other open-shift registers at the same location, excluding the acting register', function (): void {
    $other = registerAt($this->location);
    $closed = registerAt($this->location);
    $inactive = registerAt($this->location);
    $inactive->update(['is_active' => false]);

    $otherLocation = provisionedLocation();
    $elsewhere = registerAt($otherLocation);

    $opener = staffWithRole($this->location, Roles::CASHIER);

    // Acting register also has an open shift — it must not appear in its own list.
    app(OpenShift::class)->execute(new OpenShiftInput($this->acting->id, 0, $this->cashier->id));

    app(OpenShift::class)->execute(new OpenShiftInput($other->id, 0, $opener->id));

    $closedShift = app(OpenShift::class)->execute(new OpenShiftInput($closed->id, 0, $this->cashier->id));
    app(CloseShift::class)->execute(new CloseShiftInput($closedShift->id, $closed->id, 0, null, $this->cashier->id));

    app(OpenShift::class)->execute(new OpenShiftInput($inactive->id, 0, $this->cashier->id));

    $elsewhereOpener = staffWithRole($otherLocation, Roles::CASHIER);
    app(OpenShift::class)->execute(new OpenShiftInput($elsewhere->id, 0, $elsewhereOpener->id));

    $response = $this->getJson('/api/v1/registers/open-shifts', staffHeaders($this->acting, $this->cashier))
        ->assertOk();

    $items = $response->json('data.items');
    expect($items)->toHaveCount(1);
    expect($items[0])->toMatchArray([
        'register_id' => $other->id,
        'register_name' => $other->name,
        'opened_by_name' => $opener->name,
    ]);
    expect($items[0]['shift_id'])->not->toBeNull();
});

it('requires a staff session — device token alone is not enough', function (): void {
    $device = $this->acting->createToken("device:{$this->acting->id}", ['device'])->plainTextToken;

    $this->getJson('/api/v1/registers/open-shifts', ['Authorization' => "Bearer {$device}"])
        ->assertStatus(401);
});
