<?php

declare(strict_types=1);

namespace App\Livewire\Services;

use App\Domain\Services\Actions\ChangeServiceStatus;
use App\Domain\Services\Enums\ServiceStatus;
use App\Domain\Services\OperationalServiceQuery;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Services: the operational list for technicians (FR-SUB-013). It shows what a
 * job needs and nothing financial. The data comes from OperationalServiceQuery
 * as OperationalServiceRow objects, and this component keeps no model with a
 * rate in a public property, so nothing financial can appear in the page or in
 * the Livewire snapshot sent to the browser.
 *
 * Whoever holds service.execute (Technician, Administrator, Owner) can mark a
 * Pending installation as done. ChangeServiceStatus enforces that a Technician
 * can do nothing else.
 */
#[Title('Services')]
class ServiceJobs extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public string $message = '';

    public string $note = '';

    public function boot(): void
    {
        $this->authorize('service.view');
    }

    public function mount(): void
    {
        $this->authorize('service.view');
    }

    public function updated(): void
    {
        $this->authorize('service.view');

        $this->resetPage();
    }

    public function clear(): void
    {
        $this->authorize('service.view');

        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    public function select(int $serviceAccountId): void
    {
        $this->authorize('service.execute');

        abort_unless(ServiceAccount::query()->whereKey($serviceAccountId)->exists(), 404);

        $this->selectedId = $serviceAccountId;
        $this->reset(['note', 'message']);
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->authorize('service.view');

        $this->reset(['selectedId', 'note']);
        $this->resetErrorBag();
    }

    public function markInstalled(ChangeServiceStatus $changeStatus): void
    {
        $this->authorize('service.execute');
        abort_if($this->selectedId === null, 422, 'Choose a service account first.');

        $note = trim($this->note);
        $account = $changeStatus->handle(
            $this->actor(),
            $this->selectedId,
            ServiceStatus::Active,
            $note === '' ? 'Installation completed' : 'Installation completed: '.$note,
        );

        $this->message = "{$account->account_no} is now active.";
        $this->reset(['selectedId', 'note']);
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        $this->authorize('service.view');

        return view('livewire.services.service-jobs', [
            'rows' => app(OperationalServiceQuery::class)->paginate($this->actor(), $this->search, ServiceStatus::tryFrom($this->status)),
            'statuses' => ServiceStatus::cases(),
            'canExecute' => $this->actor()->hasPermission('service.execute'),
        ]);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
