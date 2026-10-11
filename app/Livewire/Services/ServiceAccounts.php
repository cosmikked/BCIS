<?php

declare(strict_types=1);

namespace App\Livewire\Services;

use App\Domain\Services\Actions\AssignCollector;
use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Actions\CreateServiceAccount;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\ServiceAccountFilters;
use App\Domain\Services\ServiceAccountListQuery;
use App\Domain\Services\ServiceHistoryQuery;
use App\Domain\Subscribers\Enums\AddressType;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Domain\Subscribers\Search\GlobalSearch;
use App\Models\CollectionArea;
use App\Models\Collector;
use App\Models\ServiceAccount;
use App\Models\ServicePlan;
use App\Models\ServiceType;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Service Accounts (FR-SUB-002, FR-SUB-007, FR-SUB-008, FR-SUB-011): the list,
 * a create form and a side panel to change the status or the collector of the
 * selected account. Thin: CreateServiceAccount, ChangeServiceStatus and
 * AssignCollector hold the rules; ServiceAccountListQuery holds the search.
 *
 * The list shows the monthly rate, so the route needs subscriber.view. Each
 * action checks its own permission again. Ids from the browser are #[Locked]
 * or re-fetched and re-validated inside the Action.
 */
#[Title('Service Accounts')]
class ServiceAccounts extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const PER_PAGE = 20;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $collector = '';

    #[Url]
    public string $area = '';

    /** Limits the list to one subscriber (the link from the profile). */
    #[Url]
    public string $subscriber = '';

    /** Opens the create form for ?subscriber= (the link from the profile). */
    #[Url]
    public bool $new = false;

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public bool $creating = false;

    #[Locked]
    public string $message = '';

    // Create form.
    public string $subscriberLookup = '';

    public string $newSubscriberId = '';

    public string $planId = '';

    public string $serviceAddressId = '';

    public string $billingStartDate = '';

    public string $billingDueDay = '';

    public string $newCollectorId = '';

    /** Pesos as typed; billed once on the account's first invoice (PRD 19 #6). */
    public string $installationFee = '';

    // Side panel.
    public string $statusTo = '';

    public string $reason = '';

    public string $assignCollectorId = '';

    public string $effectiveFrom = '';

    public function boot(): void
    {
        $this->authorize('subscriber.view');
    }

    public function mount(): void
    {
        $this->authorize('subscriber.view');

        if ($this->new && ctype_digit($this->subscriber) && $this->actor()->hasPermission('service.manage')) {
            $this->openCreate((int) $this->subscriber);
        }
    }

    public function updated(): void
    {
        $this->authorize('subscriber.view');

        $this->resetPage();
    }

    public function clear(): void
    {
        $this->authorize('subscriber.view');

        $this->reset(['search', 'status', 'type', 'collector', 'area', 'subscriber']);
        $this->resetPage();
    }

    public function select(int $serviceAccountId): void
    {
        $this->authorize('subscriber.view');

        $this->selectedId = ServiceAccount::query()->findOrFail($serviceAccountId)->id;
        $this->creating = false;
        $this->reset(['statusTo', 'reason', 'assignCollectorId', 'effectiveFrom', 'message']);
        $this->resetErrorBag();
    }

    public function startCreate(): void
    {
        $this->authorize('service.manage');

        $this->openCreate(ctype_digit($this->subscriber) ? (int) $this->subscriber : null);
    }

    public function cancelCreate(): void
    {
        $this->authorize('subscriber.view');

        $this->creating = false;
        $this->new = false;
        $this->reset(['newSubscriberId', 'planId', 'serviceAddressId', 'billingDueDay', 'newCollectorId', 'subscriberLookup', 'installationFee']);
        $this->resetErrorBag();
    }

    public function chooseSubscriber(int $subscriberId): void
    {
        $this->authorize('service.manage');

        $chosen = Subscriber::query()->findOrFail($subscriberId);
        abort_unless($chosen->status === SubscriberStatus::Active, 422, 'Only an active subscriber can get a service account.');

        $this->newSubscriberId = (string) $chosen->id;
        $this->subscriberLookup = '';
        $this->serviceAddressId = '';
        $this->billingDueDay = '';
        $this->resetErrorBag();
    }

    public function create(CreateServiceAccount $createAccount): void
    {
        $this->authorize('service.manage');

        $account = $createAccount->handle($this->actor(), [
            'subscriber_id' => $this->newSubscriberId,
            'plan_id' => $this->planId,
            'service_address_id' => $this->serviceAddressId,
            'billing_start_date' => $this->billingStartDate,
            'billing_due_day' => $this->billingDueDay,
            'collector_id' => $this->newCollectorId,
            'installation_fee' => $this->installationFee,
        ]);

        $this->creating = false;
        $this->selectedId = $account->id;
        $this->message = "Service account {$account->account_no} created. It stays Pending until it is activated.";
        $this->reset(['newSubscriberId', 'planId', 'serviceAddressId', 'billingStartDate', 'billingDueDay', 'newCollectorId', 'subscriberLookup', 'installationFee']);
        $this->resetErrorBag();
    }

    public function changeStatus(ChangeServiceStatus $changeStatus): void
    {
        $this->authorize('service.manage');
        abort_if($this->selectedId === null, 422, 'Choose a service account first.');

        $to = ServiceStatus::tryFrom($this->statusTo);
        if ($to === null) {
            throw ValidationException::withMessages(['statusTo' => 'Choose the new status.']);
        }

        $account = $changeStatus->handle($this->actor(), $this->selectedId, $to, $this->reason);

        $this->message = "{$account->account_no} is now {$to->label()}.";
        $this->reset(['statusTo', 'reason']);
        $this->resetErrorBag();
    }

    public function assignCollector(AssignCollector $assign): void
    {
        $this->authorize('collection.manage');
        abort_if($this->selectedId === null, 422, 'Choose a service account first.');

        if (! ctype_digit($this->assignCollectorId)) {
            throw ValidationException::withMessages(['assignCollectorId' => 'Choose a collector.']);
        }

        $assign->forServiceAccount($this->actor(), $this->selectedId, (int) $this->assignCollectorId, $this->effectiveFrom === '' ? null : $this->effectiveFrom);

        $this->message = 'Collector assigned.';
        $this->reset(['assignCollectorId', 'effectiveFrom']);
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        $this->authorize('subscriber.view');
        $actor = $this->actor();

        $filters = new ServiceAccountFilters(
            search: $this->search,
            status: ServiceStatus::tryFrom($this->status),
            serviceTypeId: ctype_digit($this->type) ? (int) $this->type : null,
            collectorId: ctype_digit($this->collector) ? (int) $this->collector : null,
            areaId: ctype_digit($this->area) ? (int) $this->area : null,
            subscriberId: ctype_digit($this->subscriber) ? (int) $this->subscriber : null,
        );

        $selected = $this->selectedId !== null
            ? ServiceAccount::query()->with(['subscriber', 'plan.serviceType', 'serviceAddress.collectionArea', 'collector'])->find($this->selectedId)
            : null;

        $newSubscriber = $this->creating && ctype_digit($this->newSubscriberId)
            ? Subscriber::query()->with(['addresses' => fn ($q) => $q->where('address_type', AddressType::Service->value)->orderBy('id')])->find((int) $this->newSubscriberId)
            : null;

        return view('livewire.services.service-accounts', [
            'accounts' => app(ServiceAccountListQuery::class)->build($actor, $filters)->paginate(self::PER_PAGE),
            'filteredSubscriber' => $filters->subscriberId !== null ? Subscriber::query()->find($filters->subscriberId) : null,
            'statuses' => ServiceStatus::cases(),
            'types' => ServiceType::query()->orderBy('id')->get(['id', 'name']),
            'collectors' => Collector::query()->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name']),
            'filterCollectors' => Collector::query()->orderBy('full_name')->get(['id', 'full_name']),
            'areas' => CollectionArea::query()->orderBy('name')->get(['id', 'name']),
            'selected' => $selected,
            'events' => $selected !== null
                ? app(ServiceHistoryQuery::class)->build($actor, serviceAccountId: $selected->id)->limit(8)->get()
                : collect(),
            'newSubscriber' => $newSubscriber,
            'plans' => $this->creating ? ServicePlan::query()->with('serviceType')->where('is_active', true)->orderBy('service_type_id')->orderBy('price_centavos')->get() : collect(),
            'lookup' => $this->creating && $this->newSubscriberId === '' ? app(GlobalSearch::class)->search($actor, $this->subscriberLookup, 8) : [],
            'canCreate' => $actor->hasPermission('service.manage'),
            'canChangeStatus' => $actor->hasPermission('service.manage'),
            'canAssign' => $actor->hasPermission('collection.manage'),
        ]);
    }

    private function openCreate(?int $subscriberId): void
    {
        $this->creating = true;
        $this->selectedId = null;
        $this->reset(['planId', 'serviceAddressId', 'billingDueDay', 'newCollectorId', 'subscriberLookup', 'message', 'installationFee']);
        $this->newSubscriberId = '';
        $this->billingStartDate = now()->toDateString();

        if ($subscriberId !== null) {
            $chosen = Subscriber::query()->find($subscriberId);

            if ($chosen !== null && $chosen->status === SubscriberStatus::Active) {
                $this->newSubscriberId = (string) $chosen->id;
            }
        }

        $this->resetErrorBag();
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
