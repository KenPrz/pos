<?php

// tests/Feature/Orders/OrderNumbersTest.php
declare(strict_types=1);

use App\Domain\Orders\OrderNumbers;
use App\Models\Location;

it('formats and increments per location per day', function (): void {
    $dt = Location::factory()->create(['code' => 'DT']);
    $numbers = app(OrderNumbers::class);

    expect($numbers->next($dt, '2026-07-16'))->toBe('DT-20260716-0001')
        ->and($numbers->next($dt, '2026-07-16'))->toBe('DT-20260716-0002');
});

it('resets per day and per location', function (): void {
    $dt = Location::factory()->create(['code' => 'DT']);
    $ldn = Location::factory()->create(['code' => 'LDN']);
    $numbers = app(OrderNumbers::class);

    $numbers->next($dt, '2026-07-16');

    expect($numbers->next($dt, '2026-07-17'))->toBe('DT-20260717-0001')
        ->and($numbers->next($ldn, '2026-07-16'))->toBe('LDN-20260716-0001');
});
