<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| POS configuration
|--------------------------------------------------------------------------
|
| Config is what engineers change and deploy; the database is what admins
| change at runtime. Nothing lives in both. See docs/04-backend-conventions.md
| for the rule and the table of where each setting belongs.
|
| Money is always integer minor units (cents). See docs/01-architecture.md.
|
*/

return [

    'version' => env('POS_VERSION', 'dev'),

    // ISO-4217. Fixed at setup — changing it is a data migration, not a setting.
    'currency' => env('POS_CURRENCY'),

    'business' => [
        'name'    => env('POS_BUSINESS_NAME'),
        'address' => env('POS_BUSINESS_ADDRESS'),
        'tax_id'  => env('POS_BUSINESS_TAX_ID'),
    ],

    'staff' => [
        'session_ttl_minutes' => 480,   // 8h; ends at shift close regardless
        'pin_max_attempts'    => 5,
        'pin_lockout_seconds' => 60,
    ],

    'idempotency' => [
        'ttl_hours' => 24,              // pruning window
    ],

    'shifts' => [
        // Borderline: business policy, but there is one number and nobody has asked
        // for it to differ per store. Promote to a locations column on that request.
        'variance_approval_threshold_cents' => 500,
    ],

    'orders' => [
        'number_format' => '{location}-{date}-{seq}',   // DT-20260715-0042
    ],

    'rate_limits' => [
        'pin_per_minute'     => 5,
        'catalog_per_minute' => 10,
        'default_per_minute' => 300,
    ],

    /*
    | Keys that must be present for the app to boot. A null currency does not fail
    | loudly — it produces a wrong receipt at lunchtime. Validated in AppServiceProvider.
    */
    'required' => [
        'pos.currency',
        'pos.business.name',
    ],

];
