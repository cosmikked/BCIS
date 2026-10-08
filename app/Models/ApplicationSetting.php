<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One configurable value (FR-SEC-013). Changed only through the settings
 * Action, which also writes the history row and the audit record.
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 */
class ApplicationSetting extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json', 'updated_at' => 'datetime'];
    }
}
