<?php
// backend/app/Models/BusinessDay.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The reconciliation snapshot for one location's local day — NOT a ledger entry. It reads
 * from the ledgers at close and is self-contained thereafter (an auditor reads these
 * columns, never re-queries). Closed iff a row exists AND `reopened_at is null`.
 * See docs/02-data-model.md and the End-Of-Day design.
 */
final class BusinessDay extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'checklist' => 'array',
        'closed_at' => 'immutable_datetime',
        'reopened_at' => 'immutable_datetime',
    ];
}
