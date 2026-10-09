<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dated assignment of a collector to one area or one service account
 * (FR-SUB-011). Only AssignCollector closes a row (sets effective_to); the
 * rest of the row is never rewritten.
 *
 * @property int $id
 * @property int $collector_id
 * @property int|null $collection_area_id
 * @property int|null $service_account_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $assigned_by
 * @property Carbon $created_at
 */
class CollectorAssignment extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<Collector, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(Collector::class);
    }
}
