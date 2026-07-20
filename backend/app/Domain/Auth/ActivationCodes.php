<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Generation and lookup for one-time register activation codes.
 *
 * Same two-representation idea as Pins, minus the bcrypt authority: the code is
 * high-entropy (~49 bits over a 30-char alphabet), so the keyed HMAC is both the index
 * and the verifier. Keyed rather than plain SHA-256 so a database dump alone cannot
 * brute-force the code space offline — you also need APP_KEY.
 *
 * The key is injected rather than read from config here, so this stays a pure
 * collaborator and its tests need no container. See docs/04-backend-conventions.md.
 */
final readonly class ActivationCodes
{
    /** No 0/O, 1/I/L, or U — every character survives a phone call and a sticky note. */
    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private string $key,
    ) {}

    /** 10 random alphabet chars, displayed XXXXX-XXXXX. */
    public function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 5).'-'.substr($chars, 5);
    }

    /** "abcde fgh-23" and "ABCDE-FGH23" are the same code. */
    public function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', trim($code)));
    }

    /** Deterministic and keyed: useless to anyone holding the database but not APP_KEY. */
    public function lookup(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), $this->key);
    }
}
