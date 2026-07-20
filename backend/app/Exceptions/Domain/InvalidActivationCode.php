<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Unknown, already-redeemed, or deactivated-register code — deliberately one error for
 * all three, so the endpoint is not an oracle for which codes exist.
 */
final class InvalidActivationCode extends DomainException
{
    public function __construct()
    {
        parent::__construct('That activation code is not valid.');
    }

    public function errorCode(): string
    {
        return 'invalid_activation_code';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
