<form wire:submit="save" class="mx-auto max-w-4xl space-y-6" novalidate>
    @error('form') <p role="alert" class="alert alert-danger">{{ $message }}</p> @enderror
    @error('status') <p role="alert" class="alert alert-danger">{{ $message }}</p> @enderror

    @if (count($duplicates) > 0)
        <section role="alert" class="alert alert-warning p-4" aria-labelledby="dup-title">
            <h2 id="dup-title" class="text-base font-semibold text-ink">Possible duplicate</h2>
            <p class="mt-1 text-sm">Someone with matching details is already registered. Reuse them instead of creating a second record.</p>
            <ul class="mt-3 divide-y divide-line rounded-md border border-line bg-surface text-ink">
                @foreach ($duplicates as $match)
                    <li wire:key="dup-{{ $match->subscriber->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                        <span>
                            <span class="font-medium">{{ $match->subscriber->full_name }}</span>
                            <span class="text-muted">· {{ $match->subscriber->account_no }} · {{ $match->reason }}</span>
                            <x-ui.status-badge :status="$match->subscriber->status" class="ml-1" />
                        </span>
                        <span class="flex gap-2">
                            <a href="{{ route('subscribers.show', $match->subscriber->id) }}" wire:navigate class="btn btn-secondary btn-sm">Open</a>
                            @if ($match->subscriber->status === \App\Domain\Subscribers\Enums\SubscriberStatus::Inactive)
                                <button type="button" wire:click="reactivate({{ $match->subscriber->id }})" wire:loading.attr="disabled" class="btn btn-primary btn-sm">Reactivate</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @unless ($isEdit)
                <label class="mt-3 flex items-center gap-2 text-sm text-ink">
                    <input type="checkbox" wire:model="confirm_duplicate">
                    Create anyway. This is a different person.
                </label>
            @endunless
        </section>
    @endif

    <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="identity-title">
        <h2 id="identity-title" class="mb-3 text-base font-semibold text-ink">Subscriber</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="full_name" class="label">Full name <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="full_name" type="text" wire:model.blur="full_name" maxlength="150" autocomplete="off" required class="field">
                @error('full_name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="collection_area_id" class="label">Collection area</label>
                <select id="collection_area_id" wire:model.blur="collection_area_id" class="field">
                    <option value="">None yet</option>
                    @foreach ($areas as $area)
                        <option value="{{ $area->id }}">{{ $area->name }}</option>
                    @endforeach
                </select>
                @error('collection_area_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="billing_due_day" class="label">Billing due day (1-31) <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="billing_due_day" type="number" min="1" max="31" wire:model.blur="billing_due_day" required class="field">
                @error('billing_due_day') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-muted">The default for this subscriber's new service accounts.</p>
            </div>
        </div>
    </section>

    <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="contacts-title">
        <div class="mb-3 flex items-center justify-between">
            <h2 id="contacts-title" class="text-base font-semibold text-ink">Contacts</h2>
            <button type="button" wire:click="addContact" class="btn btn-secondary btn-sm">Add contact</button>
        </div>
        @error('contacts') <p class="mb-2 text-xs text-danger">{{ $message }}</p> @enderror
        <div class="space-y-3">
            @foreach ($contacts as $i => $contact)
                <div wire:key="contact-{{ $i }}" class="grid items-start gap-3 md:grid-cols-[9rem_minmax(0,1fr)_auto_auto]">
                    <div>
                        <label for="contact-type-{{ $i }}" class="label">Type</label>
                        <select id="contact-type-{{ $i }}" wire:model.blur="contacts.{{ $i }}.contact_type" class="field">
                            <option value="MOBILE">Mobile</option>
                            <option value="EMAIL">Email</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                    <div>
                        <label for="contact-value-{{ $i }}" class="label">Number or address <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="contact-value-{{ $i }}" type="text" wire:model.blur="contacts.{{ $i }}.value" maxlength="190" autocomplete="off" class="field">
                        @error("contacts.{$i}.value") <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <label class="mt-6 flex items-center gap-2 whitespace-nowrap text-sm">
                        <input type="radio" wire:model="primaryContact" value="{{ $i }}" name="primaryContact">
                        Primary
                    </label>
                    <button type="button" wire:click="removeContact({{ $i }})" @disabled(count($contacts) <= 1) aria-label="Remove contact {{ $i + 1 }}" class="btn btn-secondary btn-sm mt-6">Remove</button>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="addresses-title">
        <div class="mb-3 flex items-center justify-between">
            <h2 id="addresses-title" class="text-base font-semibold text-ink">Addresses</h2>
            <button type="button" wire:click="addAddress" class="btn btn-secondary btn-sm">Add address</button>
        </div>
        @error('addresses') <p class="mb-2 text-xs text-danger">{{ $message }}</p> @enderror
        <div class="space-y-5">
            @foreach ($addresses as $i => $address)
                <fieldset wire:key="address-{{ $i }}" class="grid gap-3 rounded-md border border-line p-3 md:grid-cols-6">
                    <legend class="px-1 text-xs font-medium uppercase tracking-wide text-muted">Address {{ $i + 1 }}</legend>
                    <div class="md:col-span-2">
                        <label for="address-type-{{ $i }}" class="label">Used for</label>
                        <select id="address-type-{{ $i }}" wire:model.blur="addresses.{{ $i }}.address_type" class="field">
                            <option value="SERVICE">Service (installation)</option>
                            <option value="BILLING">Billing</option>
                        </select>
                    </div>
                    <div class="md:col-span-4">
                        <label for="address-line1-{{ $i }}" class="label">Street / purok <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="address-line1-{{ $i }}" type="text" wire:model.blur="addresses.{{ $i }}.line1" maxlength="190" class="field">
                        @error("addresses.{$i}.line1") <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label for="address-barangay-{{ $i }}" class="label">Barangay</label>
                        <input id="address-barangay-{{ $i }}" type="text" wire:model.blur="addresses.{{ $i }}.barangay" maxlength="100" class="field">
                    </div>
                    <div class="md:col-span-2">
                        <label for="address-city-{{ $i }}" class="label">City / municipality <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="address-city-{{ $i }}" type="text" wire:model.blur="addresses.{{ $i }}.city" maxlength="100" class="field">
                        @error("addresses.{$i}.city") <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label for="address-area-{{ $i }}" class="label">Collection area</label>
                        <select id="address-area-{{ $i }}" wire:model.blur="addresses.{{ $i }}.collection_area_id" class="field">
                            <option value="">Same as subscriber</option>
                            @foreach ($areas as $area)
                                <option value="{{ $area->id }}">{{ $area->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-5">
                        <label for="address-landmark-{{ $i }}" class="label">Landmark</label>
                        <input id="address-landmark-{{ $i }}" type="text" wire:model.blur="addresses.{{ $i }}.landmark" maxlength="190" class="field">
                    </div>
                    <div class="flex items-end justify-end md:col-span-1">
                        <button type="button" wire:click="removeAddress({{ $i }})" @disabled(count($addresses) <= 1) aria-label="Remove address {{ $i + 1 }}" class="btn btn-secondary btn-sm">Remove</button>
                    </div>
                </fieldset>
            @endforeach
        </div>
    </section>

    <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="notes-title">
        <h2 id="notes-title" class="mb-3 text-base font-semibold text-ink">Notes</h2>
        <label for="notes" class="sr-only">Notes</label>
        <textarea id="notes" wire:model.blur="notes" rows="3" maxlength="2000" class="field"></textarea>
        @error('notes') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
    </section>

    <div class="flex items-center justify-end gap-3">
        <a href="{{ $isEdit ? route('subscribers.show', $subscriberId) : route('subscribers.index') }}" wire:navigate class="btn btn-secondary">Cancel</a>
        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary">{{ $isEdit ? 'Save changes' : 'Register subscriber' }}</button>
    </div>
</form>
