<div class="space-y-5">
    <section class="flex flex-wrap items-start justify-between gap-4 rounded-md border border-line bg-surface p-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold text-ink">{{ $subscriber->full_name }}</h2>
                <x-ui.status-badge :status="$subscriber->status" />
            </div>
            <p class="mt-1 text-sm text-muted">{{ $subscriber->account_no }} · {{ $subscriber->collectionArea?->name ?? 'No collection area' }} · due day {{ $subscriber->billing_due_day }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('subscribers.index') }}" wire:navigate class="btn btn-secondary btn-sm">All subscribers</a>
            @if ($canManage)
                <a href="{{ route('subscribers.edit', $subscriber->id) }}" wire:navigate class="btn btn-primary btn-sm">Edit</a>
            @endif
        </div>
    </section>

    @if ($message !== '')
        <p role="status" class="alert alert-success">{{ $message }}</p>
    @endif

    <nav aria-label="Subscriber sections" class="overflow-x-auto border-b border-line">
        <ul class="flex min-w-max gap-1">
            @foreach ($tabs as $key => $label)
                <li>
                    <button type="button" wire:click="setTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                            @class([
                                '-mb-px border-b-2 px-4 py-2 text-sm',
                                'border-accent font-semibold text-ink' => $activeTab === $key,
                                'border-transparent text-muted hover:text-ink' => $activeTab !== $key,
                            ])
                            @if ($activeTab === $key) aria-current="page" @endif>{{ $label }}</button>
                </li>
            @endforeach
        </ul>
    </nav>

    @switch($activeTab)
        @case('overview')
            <div class="grid gap-5 lg:grid-cols-2">
                <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="details-title">
                    <h3 id="details-title" class="mb-3 text-base font-semibold text-ink">Details</h3>
                    <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-2 text-sm">
                        <dt class="text-muted">Account number</dt><dd>{{ $subscriber->account_no }}</dd>
                        <dt class="text-muted">Status</dt><dd>{{ $subscriber->status->label() }}</dd>
                        <dt class="text-muted">Collection area</dt><dd>{{ $subscriber->collectionArea?->name ?? '-' }}</dd>
                        <dt class="text-muted">Billing due day</dt><dd>{{ $subscriber->billing_due_day }}</dd>
                        <dt class="text-muted">Registered</dt><dd>{{ $subscriber->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} by {{ $creator ?? 'unknown' }}</dd>
                        <dt class="text-muted">Notes</dt><dd class="whitespace-pre-line">{{ $subscriber->notes ?? '-' }}</dd>
                    </dl>
                </section>

                <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="contacts-title">
                    <h3 id="contacts-title" class="mb-3 text-base font-semibold text-ink">Contacts</h3>
                    <ul class="space-y-1 text-sm">
                        @foreach ($subscriber->contacts as $contact)
                            <li>
                                <span class="text-muted">{{ $contact->contact_type->label() }}:</span>
                                {{ $contact->value }}
                                @if ($contact->is_primary) <span class="badge badge-neutral ml-1">Primary</span> @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="rounded-md border border-line bg-surface p-4 lg:col-span-2" aria-labelledby="addresses-title">
                    <h3 id="addresses-title" class="mb-3 text-base font-semibold text-ink">Addresses</h3>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($subscriber->addresses as $address)
                            <li class="py-2">
                                <span class="badge badge-neutral mr-2">{{ $address->address_type->label() }}</span>
                                {{ $address->oneLine() }}
                                @if ($address->landmark) <span class="text-muted">· {{ $address->landmark }}</span> @endif
                                @if ($address->collectionArea) <span class="text-muted">· {{ $address->collectionArea->name }}</span> @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                @if ($canManage && count($nextStatuses) > 0)
                    <section class="rounded-md border border-line bg-surface p-4 lg:col-span-2" aria-labelledby="status-title">
                        <h3 id="status-title" class="mb-3 text-base font-semibold text-ink">Change subscriber status</h3>
                        @error('status') <p role="alert" class="alert alert-danger mb-3">{{ $message }}</p> @enderror
                        <form wire:submit="changeStatus" class="grid items-end gap-3 md:grid-cols-[12rem_minmax(0,1fr)_auto]" novalidate>
                            <div>
                                <label for="statusTo" class="label">New status <span class="text-danger" aria-hidden="true">*</span></label>
                                <select id="statusTo" wire:model="statusTo" class="field">
                                    <option value="">Choose...</option>
                                    @foreach ($nextStatuses as $next)
                                        <option value="{{ $next->value }}">{{ $next->label() }}</option>
                                    @endforeach
                                </select>
                                @error('statusTo') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="reason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="reason" type="text" wire:model="reason" maxlength="500" placeholder="Required; kept in the audit log" class="field">
                                @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" wire:loading.attr="disabled" wire:target="changeStatus"
                                    wire:confirm="Change this subscriber's status? This is recorded in the audit log." class="btn btn-primary">Change status</button>
                        </form>
                        <p class="mt-2 text-xs text-muted">Subscribers are never deleted. Terminated and archived subscribers cannot be reactivated.</p>
                    </section>
                @endif
            </div>
            @break

        @case('services')
            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-ink">Service accounts</h3>
                    <div class="flex gap-2">
                        <a href="{{ route('service-accounts.index', ['subscriber' => $subscriber->id]) }}" wire:navigate class="btn btn-secondary btn-sm">Manage accounts</a>
                        @if ($canCreateService && $subscriber->status === \App\Domain\Subscribers\Enums\SubscriberStatus::Active)
                            <a href="{{ route('service-accounts.index', ['subscriber' => $subscriber->id, 'new' => 1]) }}" wire:navigate class="btn btn-primary btn-sm">New service account</a>
                        @endif
                    </div>
                </div>
                @if ($accounts->isEmpty())
                    <x-ui.empty-state title="No service accounts yet" message="Create a service account to start the installation." />
                @else
                    <div class="overflow-x-auto rounded-md border border-line bg-surface">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Account no.</th>
                                    <th scope="col" class="px-4 py-2">Plan</th>
                                    <th scope="col" class="px-4 py-2">Service address</th>
                                    <th scope="col" class="px-4 py-2 text-right">Monthly rate</th>
                                    <th scope="col" class="px-4 py-2">Status</th>
                                    <th scope="col" class="px-4 py-2">Collector</th>
                                    <th scope="col" class="px-4 py-2">Activated</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($accounts as $account)
                                    <tr wire:key="account-{{ $account->id }}">
                                        <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $account->account_no }}</td>
                                        <td class="px-4 py-2">{{ $account->plan->name }} <span class="text-muted">({{ $account->plan->serviceType->name }})</span></td>
                                        <td class="px-4 py-2 text-muted">{{ $account->serviceAddress->oneLine() }}</td>
                                        <td class="money px-4 py-2 text-right">{{ $account->current_rate_centavos->format() }}</td>
                                        <td class="px-4 py-2"><x-ui.status-badge :status="$account->status" /></td>
                                        <td class="px-4 py-2 text-muted">{{ $account->collector?->full_name ?? 'Unassigned' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $account->activation_date?->format('M j, Y') ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            @break

        @case('history')
            <section class="space-y-3">
                <h3 class="text-base font-semibold text-ink">Service history</h3>
                @include('livewire.partials.service-events', ['events' => $events, 'showAccount' => true])
                {{ $events->links() }}
            </section>
            @break

        @case('documents')
            <section class="space-y-3">
                <h3 class="text-base font-semibold text-ink">Documents</h3>
                @if ($documents->isEmpty())
                    <x-ui.empty-state title="No documents on file" message="IDs, contracts and installation forms can be attached here." />
                @else
                    <div class="overflow-x-auto rounded-md border border-line bg-surface">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-2">File</th>
                                    <th scope="col" class="px-4 py-2">Type</th>
                                    <th scope="col" class="px-4 py-2 text-right">Size</th>
                                    <th scope="col" class="px-4 py-2">Uploaded</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($documents as $document)
                                    <tr wire:key="document-{{ $document->id }}">
                                        <td class="px-4 py-2">{{ $document->original_name }}</td>
                                        <td class="px-4 py-2 text-muted">{{ $document->mime_type }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($document->size_bytes / 1024, 0) }} KB</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $document->uploaded_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            @break

        @default
            {{-- Billing, payments, ledger, collection and audit: filled in by their phases (FR-UI-002). --}}
            @php
                $later = [
                    'billing' => ['No invoices yet', 'Invoices appear here once monthly billing is built.'],
                    'payments' => ['No payments yet', 'Posted payments and receipts appear here once payments are built.'],
                    'ledger' => ['Ledger not available yet', 'The permanent running-balance ledger arrives with billing and payments.'],
                    'collection' => ['No collection activity yet', 'Collector batches and remittances appear here once collections are built.'],
                    'audit' => ['Audit trail not shown here yet', 'A per-subscriber audit trail arrives in a later phase. The full Audit Log is under Administration.'],
                ];
            @endphp
            <x-ui.empty-state :title="$later[$activeTab][0]" :message="$later[$activeTab][1]" />
    @endswitch
</div>
