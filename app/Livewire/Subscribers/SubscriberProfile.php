<?php

declare(strict_types=1);

namespace App\Livewire\Subscribers;

use App\Domain\Billing\InvoiceFilters;
use App\Domain\Billing\InvoiceListQuery;
use App\Domain\Billing\InvoiceRow;
use App\Domain\Billing\InvoiceRows;
use App\Domain\Billing\LedgerQuery;
use App\Domain\Billing\LedgerRow;
use App\Domain\Billing\LedgerStatement;
use App\Domain\Money\Money;
use App\Domain\Services\ServiceHistoryQuery;
use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\Actions\UploadSubscriberDocument;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Models\Invoice;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Subscriber profile shell (FR-SUB-009, FR-UI-002): nine tabs. Overview,
 * services, service history and documents show real data. Billing, payments,
 * ledger, collection and audit show an empty state until their phases build
 * them. A tab is offered only to users who hold its permission, and the active
 * tab is checked again on every request.
 */
#[Title('Subscriber Profile')]
class SubscriberProfile extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithPagination;

    private const LEDGER_PER_PAGE = 25;

    /**
     * tab key => [label, permission]. The permission is what the later phase's
     * data will need, so the tab is already hidden from users who could not read it.
     */
    public const TABS = [
        'overview' => ['Overview', 'subscriber.view'],
        'services' => ['Services', 'subscriber.view'],
        'billing' => ['Billing', 'billing.view'],
        'payments' => ['Payments', 'payment.view'],
        'ledger' => ['Ledger', 'receivable.view'],
        'collection' => ['Collection', 'collection.record'],
        'history' => ['Service history', 'subscriber.view'],
        'documents' => ['Documents', 'subscriber.view'],
        'audit' => ['Audit', 'audit.view'],
    ];

    #[Locked]
    public int $subscriberId;

    #[Url]
    public string $tab = 'overview';

    #[Locked]
    public string $message = '';

    public string $statusTo = '';

    public string $reason = '';

    /** Ledger tab date range (Y-m-d). Bad input is ignored, never passed to the query. */
    public string $ledgerFrom = '';

    public string $ledgerTo = '';

    /** The file chosen in the Documents tab; UploadSubscriberDocument judges its content. */
    public ?TemporaryUploadedFile $upload = null;

    public function boot(): void
    {
        $this->authorize('subscriber.view');
    }

    public function mount(int $subscriber): void
    {
        $this->authorize('subscriber.view');

        $this->subscriberId = Subscriber::query()->findOrFail($subscriber)->id;
    }

    public function setTab(string $tab): void
    {
        $this->authorize('subscriber.view');

        $this->tab = $tab;
        $this->resetPage();
        $this->resetPage('invoicesPage');
        $this->resetPage('ledgerPage');
    }

    public function updatedLedgerFrom(): void
    {
        $this->authorize('receivable.view');

        $this->resetPage('ledgerPage');
    }

    public function updatedLedgerTo(): void
    {
        $this->authorize('receivable.view');

        $this->resetPage('ledgerPage');
    }

    public function clearLedgerFilter(): void
    {
        $this->authorize('receivable.view');

        $this->reset(['ledgerFrom', 'ledgerTo']);
        $this->resetPage('ledgerPage');
    }

    public function changeStatus(ChangeSubscriberStatus $changeStatus): void
    {
        $this->authorize('subscriber.manage');

        $to = SubscriberStatus::tryFrom($this->statusTo);
        if ($to === null) {
            throw ValidationException::withMessages(['statusTo' => 'Choose the new status.']);
        }

        $changeStatus->handle($this->actor(), $this->subscriberId, $to, $this->reason);

        $this->message = "Status changed to {$to->label()}.";
        $this->reset(['statusTo', 'reason']);
        $this->resetErrorBag();
    }

    public function uploadDocument(UploadSubscriberDocument $uploadDocument): void
    {
        $this->authorize('subscriber.manage');

        if ($this->upload === null) {
            throw ValidationException::withMessages(['upload' => 'Choose a file to upload.']);
        }

        try {
            $document = $uploadDocument->handle($this->actor(), $this->subscriberId, $this->upload);
        } catch (ValidationException $e) {
            // A refused file is not kept in the temporary upload folder either.
            $this->upload->delete();
            $this->upload = null;

            throw $e;
        }

        $this->upload->delete();
        $this->upload = null;
        $this->message = "{$document->original_name} uploaded.";
        $this->resetErrorBag();
    }

    public function render(): mixed
    {
        $this->authorize('subscriber.view');
        $actor = $this->actor();

        $tabs = collect(self::TABS)
            ->filter(fn (array $tab) => $actor->hasPermission($tab[1]))
            ->map(fn (array $tab) => $tab[0])
            ->all();

        // A tab in the URL that this user may not see falls back to Overview.
        // Named $activeTab, not $tab: Livewire passes the public $tab property to the
        // view too and it would shadow this checked value with the raw URL one.
        $tab = array_key_exists($this->tab, $tabs) ? $this->tab : 'overview';

        $subscriber = Subscriber::query()
            ->with([
                'collectionArea',
                'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('id'),
                'addresses.collectionArea',
            ])
            ->findOrFail($this->subscriberId);

        return view('livewire.subscribers.subscriber-profile', [
            'subscriber' => $subscriber,
            'tabs' => $tabs,
            'activeTab' => $tab,
            'creator' => User::query()->whereKey($subscriber->created_by)->value('full_name'),
            'canManage' => $actor->hasPermission('subscriber.manage'),
            'canCreateService' => $actor->hasPermission('service.manage'),
            'maxUpload' => $this->limitLabel((int) config('bcis.documents.max_kb')),
            'nextStatuses' => $subscriber->status->allowedNext(),
            'accounts' => $tab === 'services'
                ? $subscriber->serviceAccounts()->with(['plan.serviceType', 'serviceAddress', 'collector'])->orderBy('account_no')->get()
                : collect(),
            'events' => $tab === 'history'
                ? app(ServiceHistoryQuery::class)->build($actor, subscriberId: $subscriber->id)->simplePaginate(15)
                : null,
            'documents' => $tab === 'documents'
                ? $subscriber->documents()->with('uploader')->orderByDesc('uploaded_at')->orderByDesc('id')->get()
                : collect(),
            'invoicePage' => $tab === 'billing' ? $this->invoicePage($actor, $subscriber->id) : null,
            'ledger' => $tab === 'ledger' ? $this->ledgerPage($subscriber->id) : null,
        ])->title($subscriber->full_name);
    }

    /** @return array{paginator: Paginator<int, Invoice>, rows: Collection<int, InvoiceRow>} */
    private function invoicePage(User $actor, int $subscriberId): array
    {
        $paginator = app(InvoiceListQuery::class)
            ->build($actor, new InvoiceFilters(subscriberId: $subscriberId))
            ->simplePaginate(10, pageName: 'invoicesPage');

        return ['paginator' => $paginator, 'rows' => app(InvoiceRows::class)->for($paginator->getCollection())];
    }

    /**
     * The ledger tab: the running balance comes from LedgerQuery, computed over the
     * whole ledger and then cut to the dates, so a filter never changes a balance.
     *
     * @return array{statement: LedgerStatement, paginator: LengthAwarePaginator<int, LedgerRow>, balance: Money, filterError: bool}
     */
    private function ledgerPage(int $subscriberId): array
    {
        $from = $this->parseDate($this->ledgerFrom);
        $to = $this->parseDate($this->ledgerTo);
        $filterError = ($this->ledgerFrom !== '' && $from === null) || ($this->ledgerTo !== '' && $to === null)
            || ($from !== null && $to !== null && $from->greaterThan($to));

        if ($filterError) {
            [$from, $to] = [null, null];
        }

        $query = app(LedgerQuery::class);
        $page = max(1, LengthAwarePaginator::resolveCurrentPage('ledgerPage'));
        $statement = $query->statement($subscriberId, $from, $to, self::LEDGER_PER_PAGE, $page);

        return [
            'statement' => $statement,
            'paginator' => new LengthAwarePaginator($statement->rows, $statement->totalRows, self::LEDGER_PER_PAGE, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'ledgerPage',
            ]),
            'balance' => $query->currentBalance($subscriberId),
            'filterError' => $filterError,
        ];
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private function limitLabel(int $kb): string
    {
        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.').' MB' : $kb.' KB';
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
