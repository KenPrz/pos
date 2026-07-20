<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Known and unredeemed, but past its expiry — distinct so a real installer asks for a reissue instead of retyping. */
final class ActivationCodeExpired extends DomainException
{
    public function __construct()
    {
        parent::__construct('This activation code has expired. Ask an admin to issue a new one.');
    }

    public function errorCode(): string
    {
        return 'activation_code_expired';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
