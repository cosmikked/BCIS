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

                @if ($canManage)
                    <form wire:submit="uploadDocument" class="rounded-md border border-line bg-surface p-4" novalidate>
                        <label for="upload" class="label">Add a document</label>
                        <div class="flex flex-wrap items-center gap-3">
                            <input id="upload" type="file" wire:model="upload" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="field max-w-md">
                            <button type="submit" wire:loading.attr="disabled" wire:target="upload,uploadDocument" class="btn btn-primary btn-sm">Upload</button>
                            <span wire:loading wire:target="upload" class="text-sm text-muted">Reading the file...</span>
                        </div>
                        @error('upload') <p role="alert" class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
                        <p class="mt-2 text-xs text-muted">PDF, JPG or PNG, up to {{ $maxUpload }}. The file content is checked, not its name.</p>
                    </form>
                @endif

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
                                    <th scope="col" class="px-4 py-2">By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($documents as $document)
                                    <tr wire:key="document-{{ $document->id }}">
                                        <td class="px-4 py-2"><a href="{{ route('subscriber-documents.show', $document->id) }}" class="underline-offset-2 hover:underline">{{ $document->original_name }}</a></td>
                                        <td class="px-4 py-2 text-muted">{{ $document->mime_type }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ number_format($document->size_bytes / 1024, 0) }} KB</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $document->uploaded_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                                        <td class="px-4 py-2 text-muted">{{ $document->uploader?->full_name ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            @break

        @case('billing')
            <section class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-base font-semibold text-ink">Invoices</h3>
                    <a href="{{ route('billing.invoices', ['subscriber' => $subscriber->id]) }}" wire:navigate class="text-sm underline">Open in Invoices</a>
                </div>

                @if ($invoicePage['rows']->isEmpty())
                    <x-ui.empty-state title="No invoices yet" message="Invoices appear here once a billing month is finalized for this subscriber's active service accounts." />
                @else
                    <div class="overflow-x-auto rounded-md border border-line bg-surface">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Invoice no.</th>
                                    <th scope="col" class="px-4 py-2">Account</th>
                                    <th scope="col" class="px-4 py-2">Period</th>
                                    <th scope="col" class="px-4 py-2">Due</th>
                                    <th scope="col" class="px-4 py-2 text-right">Total</th>
                                    <th scope="col" class="px-4 py-2 text-right">Balance</th>
                                    <th scope="col" class="px-4 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($invoicePage['rows'] as $row)
                                    @php
                                        $invoice = $row->invoice;
                                    @endphp
                                    <tr wire:key="inv-{{ $invoice->id }}">
                                        <td class="whitespace-nowrap px-4 py-2 font-medium text-ink"><a href="{{ route('billing.invoices.show', $invoice->id) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $invoice->invoice_no ?? 'Draft' }}</a></td>
                                        <td class="px-4 py-2 text-muted">{{ $invoice->serviceAccount->account_no }}</td>
                                        <td class="whitespace-nowrap px-4 py-2">{{ $invoice->billing_period?->format('M Y') ?? 'One-off' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2">{{ $invoice->due_date->format('M j, Y') }}</td>
                                        <td class="money px-4 py-2 text-right">{{ $invoice->total_centavos->format() }}</td>
                                        <td class="money px-4 py-2 text-right">{{ $invoice->status->isFinalized() ? $row->balance->format() : '-' }}</td>
                                        <td class="px-4 py-2"><x-ui.status-badge :status="$row->overdue ? 'OVERDUE' : $invoice->status" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $invoicePage['paginator']->links() }}
                @endif
            </section>
            @break

        @case('ledger')
            <section class="space-y-3">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h3 class="text-base font-semibold text-ink">Ledger</h3>
                    <p class="text-sm">Owed now: <span class="money text-base font-semibold text-ink">{{ $ledger['balance']->format() }}</span></p>
                </div>

                <div class="flex flex-wrap items-end gap-3 rounded-md border border-line bg-surface p-4">
                    <div>
                        <label for="ledgerFrom" class="label">From</label>
                        <input id="ledgerFrom" type="date" wire:model.live="ledgerFrom" class="field">
                    </div>
                    <div>
                        <label for="ledgerTo" class="label">To</label>
                        <input id="ledgerTo" type="date" wire:model.live="ledgerTo" class="field">
                    </div>
                    <button type="button" wire:click="clearLedgerFilter" class="btn btn-secondary btn-sm">Clear dates</button>
                    @if ($ledger['filterError'])
                        <p role="alert" class="text-sm text-danger">Enter valid dates, with From not after To. Showing the whole ledger.</p>
                    @endif
                </div>

                @if ($ledger['statement']->totalRows === 0 && $ledger['statement']->openingBalance->isZero())
                    <x-ui.empty-state title="No ledger entries yet" message="Every invoice, payment and correction is recorded here and never edited or deleted." />
                @else
                    <div class="overflow-x-auto rounded-md border border-line bg-surface">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Date</th>
                                    <th scope="col" class="px-4 py-2">Type</th>
                                    <th scope="col" class="px-4 py-2">Description</th>
                                    <th scope="col" class="px-4 py-2 text-right">Debit</th>
                                    <th scope="col" class="px-4 py-2 text-right">Credit</th>
                                    <th scope="col" class="px-4 py-2 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @if ($ledger['statement']->openingBalance->isZero() === false)
                                    <tr class="bg-canvas">
                                        <td colspan="5" class="px-4 py-2 text-muted">Balance before {{ \Carbon\CarbonImmutable::parse($ledgerFrom)->format('M j, Y') }}</td>
                                        <td class="money px-4 py-2 text-right">{{ $ledger['statement']->openingBalance->format() }}</td>
                                    </tr>
                                @endif
                                @foreach ($ledger['statement']->rows as $entry)
                                    <tr wire:key="ledger-{{ $entry->id }}">
                                        <td class="whitespace-nowrap px-4 py-2">{{ $entry->entryDate->format('M j, Y') }}</td>
                                        <td class="px-4 py-2 text-muted">{{ $entry->type->label() }}</td>
                                        <td class="px-4 py-2">
                                            @if ($entry->referenceType === 'invoice' && $entry->referenceId)
                                                <a href="{{ route('billing.invoices.show', $entry->referenceId) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $entry->description }}</a>
                                            @else
                                                {{ $entry->description }}
                                            @endif
                                        </td>
                                        <td class="money px-4 py-2 text-right">{{ $entry->debit->isPositive() ? $entry->debit->format() : '' }}</td>
                                        <td class="money px-4 py-2 text-right">{{ $entry->credit->isPositive() ? $entry->credit->format() : '' }}</td>
                                        <td class="money px-4 py-2 text-right font-medium text-ink">{{ $entry->runningBalance->format() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-line">
                                <tr>
                                    <th scope="row" colspan="5" class="px-4 py-2 text-right font-medium text-muted">Balance at the end of this range</th>
                                    <td class="money px-4 py-2 text-right font-semibold text-ink">{{ $ledger['statement']->closingBalance->format() }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    {{ $ledger['paginator']->links() }}
                    <p class="text-xs text-muted">A positive balance is what the subscriber owes. Entries are never edited or deleted; a mistake is corrected by a new entry.</p>
                @endif
            </section>
            @break

        @default
            {{-- Payments, collection and audit: filled in by their phases (FR-UI-002). --}}
            @php
                $later = [
                    'payments' => ['No payments yet', 'Posted payments and receipts appear here once payments are built.'],
                    'collection' => ['No collection activity yet', 'Collector batches and remittances appear here once collections are built.'],
                    'audit' => ['Audit trail not shown here yet', 'A per-subscriber audit trail arrives in a later phase. The full Audit Log is under Administration.'],
                ];
            @endphp
            <x-ui.empty-state :title="$later[$activeTab][0]" :message="$later[$activeTab][1]" />
    @endswitch
</div>
