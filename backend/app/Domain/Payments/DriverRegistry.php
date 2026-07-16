<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use InvalidArgumentException;

/** Adding a processor = a driver class + one entry here. No action changes. */
final class DriverRegistry
{
    /** @var array<string, PaymentDriver> */
    private array $drivers = [];

    public function __construct(PaymentDriver ...$drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->code()] = $driver;
        }
    }

    public function driver(string $code): PaymentDriver
    {
        return $this->drivers[$code]
            ?? throw new InvalidArgumentException("No payment driver '{$code}' is registered.");
    }
}
