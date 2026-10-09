<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CollectionArea;
use App\Models\Collector;
use Illuminate\Database\Seeder;

/**
 * Demo collection areas and collectors (PRD section 18). Names are synthetic.
 * Safe to run twice: rows are matched by code and left alone when they exist.
 */
class CollectionSetupSeeder extends Seeder
{
    /** @var array<string, string> code => name */
    private const AREAS = [
        'POB' => 'Poblacion',
        'SAMP' => 'Sampaguita Heights',
        'RIV' => 'Riverside',
    ];

    /** @var array<string, string> code => full name */
    private const COLLECTORS = [
        'COL-001' => 'Rico Dela Cruz',
        'COL-002' => 'Mila Santos',
    ];

    public function run(): void
    {
        foreach (self::AREAS as $code => $name) {
            CollectionArea::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        foreach (self::COLLECTORS as $code => $fullName) {
            Collector::query()->firstOrCreate(['code' => $code], ['full_name' => $fullName, 'is_active' => true]);
        }
    }
}
