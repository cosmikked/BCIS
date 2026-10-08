<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only history of setting changes (FR-SEC-013).
 *
 * @property int $id
 * @property string $key
 * @property mixed $old_value
 * @property mixed $new_value
 * @property int|null $changed_by
 */
class ApplicationSettingChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_value' => 'json', 'new_value' => 'json', 'changed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Setting history is append-only.'));
        static::deleting(fn () => throw new LogicException('Setting history is append-only.'));
    }
}
