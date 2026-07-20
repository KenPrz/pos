<?php

declare(strict_types=1);

use App\Domain\Auth\ActivationCodes;

it('generates a 5-5 grouped code from the unambiguous alphabet', function (): void {
    $codes = new ActivationCodes('base64:test-key');

    $code = $codes->generate();

    expect($code)->toHaveLength(11)
        ->and($code[5])->toBe('-')
        ->and(str_replace('-', '', $code))->toMatch('/^[23456789ABCDEFGHJKMNPQRSTVWXYZ]{10}$/');
});

it('normalizes case, spaces, and hyphens away', function (): void {
    $codes = new ActivationCodes('base64:test-key');

    expect($codes->normalize('abcde-fgh23'))->toBe('ABCDEFGH23')
        ->and($codes->normalize(' AB CDE-FGH23 '))->toBe('ABCDEFGH23');
});

it('produces the same lookup for every spelling of the same code, keyed by APP_KEY', function (): void {
    $codes = new ActivationCodes('base64:test-key');

    expect($codes->lookup('ABCDE-FGH23'))->toBe($codes->lookup('abcde fgh23'))
        ->and($codes->lookup('ABCDE-FGH23'))->not->toBe(new ActivationCodes('base64:other-key')->lookup('ABCDE-FGH23'));
});
