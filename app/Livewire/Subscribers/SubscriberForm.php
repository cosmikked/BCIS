<?php

declare(strict_types=1);

namespace App\Livewire\Subscribers;

use App\Domain\Subscribers\Actions\ChangeSubscriberStatus;
use App\Domain\Subscribers\Actions\RegisterSubscriber;
use App\Domain\Subscribers\Actions\UpdateSubscriber;
use App\Domain\Subscribers\DuplicateMatch;
use App\Domain\Subscribers\DuplicateSubscriberFinder;
use App\Domain\Subscribers\Enums\SubscriberStatus;
use App\Domain\Subscribers\SubscriberInput;
use App\Models\CollectionArea;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * New and Edit Subscriber (FR-SUB-001, FR-SUB-012). Thin: SubscriberInput holds
 * every validation rule, the Actions do the writing, and DuplicateSubscriberFinder
 * finds probable duplicates. This component only keeps the form state, shows the
 * errors of fields the user has already left (inline validation) and asks for
 * confirmation before registering a probable duplicate.
 *
 * Property names are the input keys (full_name, contacts.0.value, ...) so error
 * keys from the Action line up with the fields on screen. The subscriber id is
 * #[Locked]; the Action re-fetches and re-validates everything.
 */
#[Title('Subscriber')]
class SubscriberForm extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $subscriberId = null;

    /**
     * Field names the user has left, so errors appear only for those.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $touched = [];

    public string $full_name = '';

    public string $collection_area_id = '';

    public string $billing_due_day = '5';

    public string $notes = '';

    /** @var list<array{id: ?int, contact_type: string, value: string}> */
    public array $contacts = [];

    public int $primaryContact = 0;

    /** @var list<array{id: ?int, address_type: string, line1: string, barangay: string, city: string, landmark: string, collection_area_id: string}> */
    public array $addresses = [];

    public bool $confirm_duplicate = false;

    public function boot(): void
    {
        $this->authorize('subscriber.manage');
    }

    public function mount(?int $subscriber = null): void
    {
        $this->authorize('subscriber.manage');

        if ($subscriber === null) {
            $this->contacts = [$this->blankContact()];
            $this->addresses = [$this->blankAddress()];

            return;
        }

        $model = Subscriber::query()->with(['contacts' => fn ($q) => $q->orderBy('id'), 'addresses' => fn ($q) => $q->orderBy('id')])->findOrFail($subscriber);

        $this->subscriberId = $model->id;
        $this->full_name = $model->full_name;
        $this->collection_area_id = (string) ($model->collection_area_id ?? '');
        $this->billing_due_day = (string) $model->billing_due_day;
        $this->notes = (string) ($model->notes ?? '');

        foreach ($model->contacts as $i => $contact) {
            $this->contacts[] = ['id' => $contact->id, 'contact_type' => $contact->contact_type->value, 'value' => $contact->value];
            if ($contact->is_primary) {
                $this->primaryContact = $i;
            }
        }

        foreach ($model->addresses as $address) {
            $this->addresses[] = [
                'id' => $address->id,
                'address_type' => $address->address_type->value,
                'line1' => $address->line1,
                'barangay' => (string) ($address->barangay ?? ''),
                'city' => $address->city,
                'landmark' => (string) ($address->landmark ?? ''),
                'collection_area_id' => (string) ($address->collection_area_id ?? ''),
            ];
        }
    }

    // Inline validation: after a field changes (blur), show the errors of the
    // fields the user has already left. The rules are the Action's own.
    public function updated(string $name): void
    {
        $this->authorize('subscriber.manage');

        // Ticking "Create anyway" is not an edit of the form.
        if ($name === 'confirm_duplicate') {
            return;
        }

        $this->touched[$name] = true;
        // Any change to the details means the user must confirm again.
        $this->confirm_duplicate = false;
        $this->showErrorsForTouchedFields();
    }

    public function addContact(): void
    {
        $this->authorize('subscriber.manage');

        if (count($this->contacts) < 10) {
            $this->contacts[] = $this->blankContact();
        }
    }

    public function removeContact(int $index): void
    {
        $this->authorize('subscriber.manage');

        if (count($this->contacts) <= 1 || ! isset($this->contacts[$index])) {
            return;
        }

        array_splice($this->contacts, $index, 1);
        $this->primaryContact = $this->primaryContact === $index ? 0 : ($this->primaryContact > $index ? $this->primaryContact - 1 : $this->primaryContact);
        $this->forgetRowErrors();
    }

    public function addAddress(): void
    {
        $this->authorize('subscriber.manage');

        if (count($this->addresses) < 10) {
            $this->addresses[] = $this->blankAddress();
        }
    }

    public function removeAddress(int $index): void
    {
        $this->authorize('subscriber.manage');

        if (count($this->addresses) <= 1 || ! isset($this->addresses[$index])) {
            return;
        }

        array_splice($this->addresses, $index, 1);
        $this->forgetRowErrors();
    }

    public function save(RegisterSubscriber $register, UpdateSubscriber $update): void
    {
        $this->authorize('subscriber.manage');
        $this->resetErrorBag();

        // A probable duplicate needs a deliberate "create anyway" (FR-SUB-012).
        if ($this->subscriberId === null && $this->duplicates() !== [] && ! $this->confirm_duplicate) {
            $this->addError('form', 'This looks like a subscriber who is already registered. Open or reactivate them below, or tick "Create anyway".');

            return;
        }

        $subscriber = $this->subscriberId === null
            ? $register->handle($this->actor(), $this->input())
            : $update->handle($this->actor(), $this->subscriberId, $this->input());

        session()->flash('status', "Subscriber {$subscriber->account_no} saved.");
        $this->redirectRoute('subscribers.show', ['subscriber' => $subscriber->id], navigate: true);
    }

    /** Offers the existing, inactive subscriber instead of a second record. */
    public function reactivate(int $subscriberId, ChangeSubscriberStatus $changeStatus): void
    {
        $this->authorize('subscriber.manage');

        $changeStatus->handle($this->actor(), $subscriberId, SubscriberStatus::Active, 'Reactivated from the new subscriber form');

        session()->flash('status', 'Subscriber reactivated.');
        $this->redirectRoute('subscribers.show', ['subscriber' => $subscriberId], navigate: true);
    }

    public function render(): mixed
    {
        $this->authorize('subscriber.manage');

        return view('livewire.subscribers.subscriber-form', [
            'areas' => CollectionArea::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'duplicates' => $this->duplicates(),
            'isEdit' => $this->subscriberId !== null,
        ])->title($this->subscriberId !== null ? 'Edit Subscriber' : 'New Subscriber');
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $contacts = [];
        foreach ($this->contacts as $i => $contact) {
            $contacts[] = [...$contact, 'is_primary' => $i === $this->primaryContact];
        }

        return [
            'full_name' => $this->full_name,
            'collection_area_id' => $this->collection_area_id,
            'billing_due_day' => $this->billing_due_day,
            'notes' => $this->notes,
            'contacts' => $contacts,
            'addresses' => $this->addresses,
        ];
    }

    private function showErrorsForTouchedFields(): void
    {
        $this->resetErrorBag();

        try {
            SubscriberInput::validate($this->input());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                if (isset($this->touched[$key])) {
                    $this->addError($key, $messages[0]);
                }
            }
        }
    }

    /** Row numbers shift after a removal, so errors tied to old numbers are dropped. */
    private function forgetRowErrors(): void
    {
        $this->touched = array_filter($this->touched, fn ($flag, $key) => ! str_starts_with((string) $key, 'contacts.') && ! str_starts_with((string) $key, 'addresses.'), ARRAY_FILTER_USE_BOTH);
        $this->resetErrorBag();
    }

    /**
     * Probable duplicates, but only once the user has typed something that can
     * match (a name, a contact or a street), so a fresh form shows no warning.
     *
     * @return list<DuplicateMatch>
     */
    private function duplicates(): array
    {
        $typed = array_filter(array_keys($this->touched), fn ($key) => $key === 'full_name' || str_starts_with((string) $key, 'contacts.') || str_starts_with((string) $key, 'addresses.'));

        if ($typed === []) {
            return [];
        }

        return app(DuplicateSubscriberFinder::class)->find(
            $this->actor(),
            $this->full_name,
            array_map(fn (array $c) => ['contact_type' => $c['contact_type'], 'value' => $c['value']], $this->contacts),
            array_map(fn (array $a) => $a['line1'], $this->addresses),
            $this->subscriberId,
        );
    }

    /** @return array{id: null, contact_type: string, value: string} */
    private function blankContact(): array
    {
        return ['id' => null, 'contact_type' => 'MOBILE', 'value' => ''];
    }

    /** @return array{id: null, address_type: string, line1: string, barangay: string, city: string, landmark: string, collection_area_id: string} */
    private function blankAddress(): array
    {
        return ['id' => null, 'address_type' => 'SERVICE', 'line1' => '', 'barangay' => '', 'city' => '', 'landmark' => '', 'collection_area_id' => ''];
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
