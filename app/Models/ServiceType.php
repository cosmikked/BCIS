<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Small lookup table: INTERNET, CABLE, COMBO (FR-SUB-005).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class ServiceType extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'name'];
}
