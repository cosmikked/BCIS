<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Security\RoleCode;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Actions\CreateServiceAccount;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\Actions\RegisterSubscriber;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 50 synthetic subscribers and 61 service accounts (PRD section 18, NFR-14).
 * Everything goes through the real Actions, as the Owner, so each account has
 * its service events and audit rows exactly as live data would. Names, streets
 * and phone numbers are invented; the phone numbers use the reserved-looking
 * 0999 000 xxxx range. Skipped in production and when subscribers exist.
 *
 * The named people from PRD section 18 that matter before billing exists:
 * Ana Reyes (new Combo, still PENDING), Ben Santos (999 plan), Carlo Mendoza
 * (999 plan, suspended), Dina Flores (1,000 plan, suspended), Elena Cruz and
 * Elaine Cruz (similar names, 1,499 plan), Gil Ramos (799 plan). Their invoices
 * and payments arrive in later phases. Rico Dela Cruz gets the first 41
 * accounts, Mila Santos the rest.
 */
class DemoSubscriberSeeder extends Seeder
{
    /** @var list<array{name: string, plan: string, due: int, start: string, final: string, fee?: string}> */
    private const NAMED = [
        ['name' => 'Ana Reyes', 'plan' => 'CMB-STD', 'due' => 5, 'start' => '2026-10-01', 'final' => 'PENDING', 'fee' => '500.00'],
        ['name' => 'Ben Santos', 'plan' => 'INT-20', 'due' => 5, 'start' => '2026-10-01', 'final' => 'ACTIVE'],
        ['name' => 'Carlo Mendoza', 'plan' => 'INT-20', 'due' => 5, 'start' => '2026-07-01', 'final' => 'SUSPENDED'],
        ['name' => 'Dina Flores', 'plan' => 'CAB-PLUS', 'due' => 10, 'start' => '2026-04-01', 'final' => 'SUSPENDED'],
        ['name' => 'Elena Cruz', 'plan' => 'CMB-STD', 'due' => 15, 'start' => '2026-09-01', 'final' => 'ACTIVE'],
        ['name' => 'Elaine Cruz', 'plan' => 'CMB-STD', 'due' => 15, 'start' => '2026-09-01', 'final' => 'ACTIVE'],
        ['name' => 'Gil Ramos', 'plan' => 'INT-10', 'due' => 5, 'start' => '2026-10-01', 'final' => 'ACTIVE'],
    ];

    private const FIRST_NAMES = ['Marco', 'Lea', 'Jun', 'Rosa', 'Ines', 'Paolo', 'Nena', 'Ramon', 'Tess', 'Dante', 'Gina', 'Lito', 'Mara', 'Nilo', 'Olga', 'Pablo', 'Rita', 'Sonny', 'Tina', 'Vic', 'Wena'];

    private const LAST_NAMES = ['Abad', 'Bautista', 'Castro', 'Dizon', 'Estrada', 'Fabian', 'Galang', 'Herrera', 'Ignacio', 'Javier', 'Lopez', 'Manalo', 'Navarro', 'Ocampo', 'Padilla'];

    private const STREETS = ['Rizal St', 'Mabini St', 'Bonifacio St', 'Luna St', 'Del Pilar St', 'Quezon Ave', 'Aguinaldo St', 'Burgos St'];

    /** @var list<string> */
    private const PLAN_CYCLE = ['INT-10', 'INT-20', 'INT-20', 'INT-50', 'CAB-BASIC', 'CAB-PLUS', 'CMB-STD', 'CMB-PREM'];

    private const AREA_CODES = ['POB', 'SAMP', 'RIV'];

    private const BARANGAYS = ['POB' => 'Poblacion', 'SAMP' => 'Sampaguita', 'RIV' => 'Riverside'];

    /** Index of the generated subscriber whose service ends (demo of TERMINATED / INACTIVE). */
    private const LEAVER_INDEX = 25;

    public function run(): void
    {
        if (app()->environment('production') || Subscriber::query()->exists()) {
            return;
        }

        $owner = User::query()
            ->whereHas('roles', fn ($roles) => $roles->where('code', RoleCode::Owner->value))
            ->orderBy('id')
            ->first();

        if ($owner === null) {
            throw new RuntimeException('Seed the Owner (OwnerSeeder) before the demo subscribers.');
        }

        $areas = CollectionArea::query()->pluck('id', 'code');
        $plans = ServicePlan::query()->pluck('id', 'code');
        $collectors = Collector::query()->pluck('id', 'code');

        if ($areas->isEmpty() || $plans->isEmpty() || $collectors->isEmpty()) {
            throw new RuntimeException('Run CollectionSetupSeeder and ServicePlanSeeder first.');
        }

        $register = app(RegisterSubscriber::class);
        $createAccount = app(CreateServiceAccount::class);
        $changeStatus = app(ChangeServiceStatus::class);
        $changeSubscriber = app(ChangeSubscriberStatus::class);

        $accountCount = 0;

        foreach ($this->people() as $i => $person) {
            $areaCode = self::AREA_CODES[$i % 3];
            $areaId = (int) $areas[$areaCode];
            $barangay = self::BARANGAYS[$areaCode];

            $addresses = [$this->address("{$person['street']}", $barangay, $areaId, 'SERVICE')];
            // Every fourth subscriber (after the named ones) also has a second service address.
            $hasSecond = $i >= count(self::NAMED) && $i % 4 === 0;
            if ($hasSecond) {
                $addresses[] = $this->address('Purok '.(($i % 9) + 1).' '.self::STREETS[($i + 3) % 8], $barangay, $areaId, 'SERVICE');
            }

            $subscriber = $register->handle($owner, [
                'full_name' => $person['name'],
                'collection_area_id' => $areaId,
                'billing_due_day' => $person['due'],
                'notes' => null,
                'contacts' => [['contact_type' => 'MOBILE', 'value' => sprintf('0999000%04d', $i + 1), 'is_primary' => true]],
                'addresses' => $addresses,
            ]);

            $serviceAddresses = $subscriber->addresses()->orderBy('id')->get();

            foreach ($serviceAddresses as $n => $address) {
                $planCode = $n === 0 ? $person['plan'] : self::PLAN_CYCLE[($i + 5) % 8];
                $collectorCode = $accountCount < 41 ? 'COL-001' : 'COL-002';

                $account = $createAccount->handle($owner, [
                    'subscriber_id' => $subscriber->id,
                    'plan_id' => $plans[$planCode],
                    'service_address_id' => $address->id,
                    'billing_start_date' => $person['start'],
                    'billing_due_day' => $person['due'],
                    'collector_id' => $collectors[$collectorCode],
                    // Only the named people with a fee (Ana Reyes) carry one, on their first account.
                    'installation_fee' => $n === 0 ? ($person['fee'] ?? null) : null,
                ]);
                $accountCount++;

                $this->settle($changeStatus, $owner, $account, $n === 0 ? $person['final'] : 'ACTIVE');
            }

            if ($i === count(self::NAMED) + self::LEAVER_INDEX) {
                foreach ($subscriber->serviceAccounts()->get() as $account) {
                    $changeStatus->handle($owner, $account->id, ServiceStatus::Terminated, 'Demo: customer moved away');
                }
                $changeSubscriber->handle($owner, $subscriber->id, SubscriberStatus::Inactive, 'Demo: customer moved away');
            }
        }
    }

    /**
     * Brings a new PENDING account to its demo status through real transitions.
     */
    private function settle(ChangeServiceStatus $changeStatus, User $owner, ServiceAccount $account, string $final): void
    {
        if ($final === 'PENDING') {
            return;
        }

        $changeStatus->handle($owner, $account->id, ServiceStatus::Active, 'Demo: installation complete');

        if ($final === 'SUSPENDED') {
            $changeStatus->handle($owner, $account->id, ServiceStatus::Suspended, 'Demo: unpaid invoices');
        }
    }

    /** @return array<string, mixed> */
    private function address(string $street, string $barangay, int $areaId, string $type): array
    {
        return [
            'address_type' => $type,
            'line1' => $street,
            'barangay' => $barangay,
            'city' => 'Malaybalay City',
            'landmark' => null,
            'collection_area_id' => $areaId,
        ];
    }

    /**
     * The 7 named people followed by 43 generated ones (50 in all).
     *
     * @return list<array{name: string, plan: string, due: int, start: string, final: string, street: string, fee?: string}>
     */
    private function people(): array
    {
        $people = [];

        foreach (self::NAMED as $i => $named) {
            $people[] = [...$named, 'street' => 'Purok '.($i + 1).' '.self::STREETS[$i % 8]];
        }

        $generated = 50 - count(self::NAMED);

        for ($g = 0; $g < $generated; $g++) {
            $i = count(self::NAMED) + $g;
            $people[] = [
                'name' => self::FIRST_NAMES[$g % 21].' '.self::LAST_NAMES[($g * 4 + intdiv($g, 15)) % 15],
                'plan' => self::PLAN_CYCLE[$g % 8],
                'due' => [5, 10, 15, 20, 25][$g % 5],
                'start' => '2026-10-01',
                // A few installations are still waiting.
                'final' => $g % 9 === 8 ? 'PENDING' : 'ACTIVE',
                'street' => 'Purok '.(($i % 9) + 1).' '.self::STREETS[($i + 1) % 8],
            ];
        }

        return $people;
    }
}
