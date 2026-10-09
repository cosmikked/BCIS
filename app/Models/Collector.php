<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CollectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A collector is a record, not a login (user_id stays empty in this project).
 *
 * @property int $id
 * @property string $code
 * @property string $full_name
 * @property string|null $contact_no
 * @property int|null $user_id
 * @property bool $is_active
 */
class Collector extends Model
{
    /** @use HasFactory<CollectorFactory> */
    use HasFactory;

    protected $fillable = ['code', 'full_name', 'contact_no', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
