<?php
// backend/tests/Feature/Shifts/ApproveVarianceTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    // threshold is 500 (config/pos.php); 600 short requires approval
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id, 'opened_by' => $this->cashier->id,
        'closed_at' => now(), 'closed_by' => $this->cashier->id,
        'counted_cash_cents' => 0, 'expected_cash_cents' => 600, 'variance_cents' => -600,
    ]);
});

it('records the approval once, supervisor only', function (): void {
    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->cashier))->assertStatus(403);

    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.shift.variance_approved_by', $this->supervisor->id);

    $this->assertDatabaseHas('audit_log', ['action' => 'shift.approve_variance', 'entity_id' => $this->shift->id]);

    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(422)->assertJsonPath('error.code', 'variance_already_approved');
});

it('refuses when not required: under threshold, or shift still open', function (): void {
    $this->shift->forceFill(['variance_cents' => -500])->save();   // exactly at threshold = not over
    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(422)->assertJsonPath('error.code', 'variance_approval_not_required');

    $open = Shift::factory()->create(['register_id' => registerAt($this->location)->id, 'opened_by' => $this->cashier->id]);
    $this->postJson("/api/v1/shifts/{$open->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(422)->assertJsonPath('error.code', 'variance_approval_not_required');
});
