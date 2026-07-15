<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** The device token is absent, unknown, or belongs to a deactivated register. */
final class InvalidDeviceToken extends DomainException
{
    public function __construct(string $message = 'This device is not enrolled.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'invalid_device_token';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
