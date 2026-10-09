<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
use App\Domain\Money\MoneyCast;
use Database\Factories\ServicePlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editing the price here never changes an existing service account (INV-20):
 * accounts hold their own copy of the rate.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $service_type_id
 * @property Money $price_centavos
 * @property Money $installation_fee_centavos
 * @property int|null $speed_mbps
 * @property int|null $channel_count
 * @property string|null $description
 * @property bool $is_active
 * @property-read ServiceType $serviceType
 */
class ServicePlan extends Model
{
    /** @use HasFactory<ServicePlanFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_centavos' => MoneyCast::class,
            'installation_fee_centavos' => MoneyCast::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ServiceType, $this> */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
