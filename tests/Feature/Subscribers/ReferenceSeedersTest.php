<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\ServicePlan;
use Database\Seeders\CollectionSetupSeeder;
use Database\Seeders\ServicePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

// FR-SUB-005, INV-20, NFR-14. Demo reference data per PRD section 18.

uses(RefreshDatabase::class);

it('seeds 3 Internet, 2 Cable and 2 Combo plans with the right fields', function (): void {
    $this->seed(ServicePlanSeeder::class);

    $byType = ServicePlan::query()->with('serviceType')->get()->groupBy(fn (ServicePlan $plan) => $plan->serviceType->code);

    expect($byType['INTERNET'])->toHaveCount(3)
        ->and($byType['CABLE'])->toHaveCount(2)
        ->and($byType['COMBO'])->toHaveCount(2);

    foreach ($byType['INTERNET'] as $plan) {
        expect($plan->speed_mbps)->not->toBeNull();
    }
    foreach ($byType['CABLE'] as $plan) {
        expect($plan->channel_count)->not->toBeNull();
    }
    foreach ($byType['COMBO'] as $plan) {
        expect($plan->speed_mbps)->not->toBeNull()->and($plan->channel_count)->not->toBeNull();
    }
});

it('seeds the demo areas and collectors', function (): void {
    $this->seed(CollectionSetupSeeder::class);

    expect(CollectionArea::query()->orderBy('name')->pluck('name')->all())
        ->toBe(['Poblacion', 'Riverside', 'Sampaguita Heights'])
        ->and(Collector::query()->orderBy('full_name')->pluck('full_name')->all())
        ->toBe(['Mila Santos', 'Rico Dela Cruz']);
});

it('does not reset an edited plan price when seeded again (INV-20)', function (): void {
    $this->seed(ServicePlanSeeder::class);
    ServicePlan::query()->where('code', 'INT-20')->firstOrFail()->update(['price_centavos' => Money::fromCentavos(109900)]);

    $this->seed(ServicePlanSeeder::class);

    expect(ServicePlan::query()->where('code', 'INT-20')->firstOrFail()->price_centavos->centavos)->toBe(109900)
        ->and(ServicePlan::query()->count())->toBe(7);
});
