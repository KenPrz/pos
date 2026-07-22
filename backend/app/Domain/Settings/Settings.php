<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use Illuminate\Support\Facades\DB;

/**
 * Keyed runtime settings, database first with a config fallback.
 *
 * Config is what engineers deploy; the database is what admins change at runtime — see
 * docs/04-backend-conventions.md. `REGISTRY` is the whole surface: a short, public key
 * (e.g. `business.name`) mapped to the config path it falls back to until an admin sets
 * it. Deliberately not an Eloquent model, same reasoning as AuditLogger — there is no
 * relation to load and no reason to invite mass-assignment or timestamps-as-magic here,
 * just a key/value row read and written directly.
 *
 * Contract: set a value to override config; set null to fall back to config again. `set()`
 * writes an override, `clear()` deletes it — there is no way to store an explicit null,
 * because a stored null would pin `source: 'db'` forever with no path back to config.
 */
final class Settings
{
    /** @var array<string, string> registry key => config path fallback */
    public const array REGISTRY = [
        'business.name' => 'pos.business.name',
        'business.address' => 'pos.business.address',
        'business.tax_id' => 'pos.business.tax_id',
    ];

    public function get(string $key): mixed
    {
        $row = DB::table('settings')->where('key', $key)->first();

        if ($row !== null) {
            return json_decode((string) $row->value, true);
        }

        return config(self::REGISTRY[$key] ?? $key);
    }

    /**
     * Every registry key, resolved to its effective value and where it came from.
     *
     * @return list<array{key: string, value: mixed, source: 'db'|'config'}>
     */
    public function all(): array
    {
        $stored = DB::table('settings')
            ->whereIn('key', array_keys(self::REGISTRY))
            ->pluck('value', 'key');

        return array_map(
            static fn (string $key): array => $stored->has($key)
                ? ['key' => $key, 'value' => json_decode((string) $stored->get($key), true), 'source' => 'db']
                : ['key' => $key, 'value' => config(self::REGISTRY[$key]), 'source' => 'config'],
            array_keys(self::REGISTRY),
        );
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('settings')->upsert(
            [[
                'key' => $key,
                'value' => json_encode($value),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    /** Delete the override, if any — the key falls back to config again. */
    public function clear(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();
    }
}
