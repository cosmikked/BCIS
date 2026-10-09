<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Money\Money;
use App\Models\ServicePlan;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

/**
 * 3 Internet, 2 Cable and 2 Combo plans. The price points come from the
 * amounts in PRD section 18 (799, 999, 1,000 and 1,499 a month; Ana Reyes'
 * 1,999 first invoice = Combo 1,499 + 500 installation). Plan names, speeds
 * and channel counts are not in the PRD, so they are invented here and flagged
 * in the commit. A plan that already exists is left alone: re-seeding must not
 * change a price (INV-20).
 */
class ServicePlanSeeder extends Seeder
{
    /** @var list<array{code: string, name: string, type: string, price: int, speed: ?int, channels: ?int}> price in centavos */
    private const PLANS = [
        ['code' => 'INT-10', 'name' => 'Internet 10 Mbps', 'type' => 'INTERNET', 'price' => 79900, 'speed' => 10, 'channels' => null],
        ['code' => 'INT-20', 'name' => 'Internet 20 Mbps', 'type' => 'INTERNET', 'price' => 99900, 'speed' => 20, 'channels' => null],
        ['code' => 'INT-50', 'name' => 'Internet 50 Mbps', 'type' => 'INTERNET', 'price' => 129900, 'speed' => 50, 'channels' => null],
        ['code' => 'CAB-BASIC', 'name' => 'Cable Basic', 'type' => 'CABLE', 'price' => 50000, 'speed' => null, 'channels' => 80],
        ['code' => 'CAB-PLUS', 'name' => 'Cable Plus', 'type' => 'CABLE', 'price' => 100000, 'speed' => null, 'channels' => 150],
        ['code' => 'CMB-STD', 'name' => 'Combo Standard', 'type' => 'COMBO', 'price' => 149900, 'speed' => 20, 'channels' => 100],
        ['code' => 'CMB-PREM', 'name' => 'Combo Premium', 'type' => 'COMBO', 'price' => 199900, 'speed' => 50, 'channels' => 150],
    ];

    public function run(): void
    {
        $typeIds = ServiceType::query()->pluck('id', 'code');

        foreach (self::PLANS as $plan) {
            ServicePlan::query()->firstOrCreate(['code' => $plan['code']], [
                'name' => $plan['name'],
                'service_type_id' => $typeIds[$plan['type']],
                'price_centavos' => Money::fromCentavos($plan['price']),
                'installation_fee_centavos' => Money::fromCentavos(50000),
                'speed_mbps' => $plan['speed'],
                'channel_count' => $plan['channels'],
                'is_active' => true,
            ]);
        }
    }
}
