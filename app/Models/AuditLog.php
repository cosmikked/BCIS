<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Insert-only (INV-18). The model refuses updates and deletes as a first guard;
 * the database trigger and revoked privileges are the real ones. Rows are
 * written through AuditLogger, never built by hand.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string|null $reason
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $request_id
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit rows are insert-only.'));
        static::deleting(fn () => throw new LogicException('Audit rows are insert-only.'));
    }
}
