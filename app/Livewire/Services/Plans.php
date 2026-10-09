<?php

declare(strict_types=1);

namespace App\Livewire\Services;

use App\Domain\Services\Actions\CreatePlan;
use App\Domain\Services\Actions\SetPlanActive;
use App\Domain\Services\Actions\UpdatePlan;
use App\Models\ServicePlan;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Plans list with a create and edit panel (FR-SUB-005, FR-SUB-006). Anyone who
 * can view subscribers can read the list; only plan.manage can change it. Thin:
 * the rules (type fields, money parsing, rate immunity) live in the Actions.
 */
#[Title('Plans')]
class Plans extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public bool $creating = false;

    #[Locked]
    public string $status = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    public string $code = '';

    public string $name = '';

    public string $serviceTypeId = '';

    public string $price = '';

    public string $installationFee = '0.00';

    public string $speedMbps = '';

    public string $channelCount = '';

    public string $description = '';

    public string $reason = '';

    public function boot(): void
    {
        $this->authorize('subscriber.view');
    }

    public function mount(): void
    {
        $this->authorize('subscriber.view');
    }

    public function updated(): void
    {
        $this->authorize('subscriber.view');
    }

    public function select(int $planId): void
    {
        $this->authorize('plan.manage');

        $plan = ServicePlan::query()->findOrFail($planId);

        $this->selectedId = $plan->id;
        $this->creating = false;
        $this->code = $plan->code;
        $this->name = $plan->name;
        $this->serviceTypeId = (string) $plan->service_type_id;
        $this->price = $plan->price_centavos->toDecimalString();
        $this->installationFee = $plan->installation_fee_centavos->toDecimalString();
        $this->speedMbps = (string) ($plan->speed_mbps ?? '');
        $this->channelCount = (string) ($plan->channel_count ?? '');
        $this->description = (string) ($plan->description ?? '');
        $this->reason = '';
        $this->status = '';
        $this->resetErrorBag();
    }

    public function startCreate(): void
    {
        $this->authorize('plan.manage');

        $this->reset(['selectedId', 'code', 'name', 'serviceTypeId', 'price', 'speedMbps', 'channelCount', 'description', 'reason', 'status']);
        $this->installationFee = '0.00';
        $this->creating = true;
        $this->resetErrorBag();
    }

    public function save(CreatePlan $create, UpdatePlan $update): void
    {
        $this->authorize('plan.manage');

        $input = [
            'code' => $this->code,
            'name' => $this->name,
            'service_type_id' => $this->serviceTypeId,
            'price' => $this->price,
            'installation_fee' => $this->installationFee,
            'speed_mbps' => $this->speedMbps,
            'channel_count' => $this->channelCount,
            'description' => $this->description,
        ];

        if ($this->creating) {
            $plan = $create->handle($this->actor(), $input);
            $this->status = "Plan {$plan->code} created.";
        } else {
            abort_if($this->selectedId === null, 422, 'Choose a plan first.');

            $plan = $update->handle($this->actor(), $this->selectedId, $input, $this->reason);
            $this->status = "Plan {$plan->code} saved. Existing service accounts keep their rate.";
        }

        $this->creating = false;
        $this->selectedId = $plan->id;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function toggleActive(SetPlanActive $setActive): void
    {
        $this->authorize('plan.manage');
        abort_if($this->selectedId === null, 422, 'Choose a plan first.');

        $plan = ServicePlan::query()->findOrFail($this->selectedId);
        $setActive->handle($this->actor(), $plan->id, ! $plan->is_active, $this->reason);

        $this->status = $plan->is_active ? "Plan {$plan->code} is now inactive." : "Plan {$plan->code} is active again.";
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        // Every request, including a page left open, needs the permission.
        $this->authorize('subscriber.view');

        $plans = ServicePlan::query()
            ->with('serviceType')
            ->when(ctype_digit($this->typeFilter), fn ($q) => $q->where('service_type_id', (int) $this->typeFilter))
            ->orderBy('service_type_id')
            ->orderBy('price_centavos')
            ->get();

        return view('livewire.services.plans', [
            'plans' => $plans,
            'types' => ServiceType::query()->orderBy('id')->get(),
            'selected' => $this->selectedId !== null ? ServicePlan::query()->with('serviceType')->find($this->selectedId) : null,
            'canManage' => $this->actor()->hasPermission('plan.manage'),
        ]);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
