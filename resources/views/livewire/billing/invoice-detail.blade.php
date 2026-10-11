<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
    <div class="min-w-0 space-y-6">
        @if ($message !== '')
            <p role="status" class="alert alert-success">{{ $message }}</p>
        @endif

        <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="invoice-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="invoice-title" class="text-base font-semibold text-ink">{{ $invoice->invoice_no ?? 'Draft invoice' }}</h2>
                <x-ui.status-badge :status="$overdue ? 'OVERDUE' : $invoice->status" />
            </div>
            <dl class="mt-3 grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1.5 text-sm">
                <dt class="text-muted">Subscriber</dt>
                <dd><a href="{{ route('subscribers.show', $invoice->subscriber_id) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $invoice->subscriber->full_name }}</a></dd>
                <dt class="text-muted">Service account</dt><dd>{{ $invoice->serviceAccount->account_no }} · {{ $invoice->serviceAccount->plan->name }}</dd>
                <dt class="text-muted">Type</dt><dd>{{ $invoice->invoice_type->label() }}</dd>
                <dt class="text-muted">Billing period</dt><dd>{{ $invoice->billing_period?->format('F Y') ?? 'One-off, not tied to a month' }}</dd>
                <dt class="text-muted">Invoice date</dt><dd>{{ $invoice->invoice_date->format('M j, Y') }}</dd>
                <dt class="text-muted">Due date</dt>
                <dd>{{ $invoice->due_date->format('M j, Y') }}
                    @if ($overdue) <span class="text-danger">· {{ $daysPastDue }} {{ \Illuminate\Support\Str::plural('day', $daysPastDue) }} past due</span> @endif
                </dd>
                @if ($invoice->status === \App\Domain\Billing\Enums\InvoiceStatus::Void)
                    <dt class="text-muted">Voided</dt>
                    <dd>{{ $invoice->voided_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $invoice->void_reason }}</dd>
                @endif
            </dl>
        </section>

        <section class="overflow-x-auto rounded-md border border-line bg-surface" aria-label="Invoice items">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">Item</th>
                        <th scope="col" class="px-4 py-2">Type</th>
                        <th scope="col" class="px-4 py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($invoice->items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td class="px-4 py-2">{{ $item->description }}</td>
                            <td class="px-4 py-2 text-muted">{{ $item->item_type->label() }}</td>
                            <td class="money px-4 py-2 text-right">{{ $item->amount_centavos->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-line text-sm">
                    <tr><th scope="row" colspan="2" class="px-4 py-2 text-right font-medium text-muted">Invoice total</th><td class="money px-4 py-2 text-right font-semibold text-ink">{{ $invoice->total_centavos->format() }}</td></tr>
                    <tr><th scope="row" colspan="2" class="px-4 py-2 text-right font-medium text-muted">Approved adjustments</th><td class="money px-4 py-2 text-right">{{ $figures->adjustments->format() }}</td></tr>
                    <tr><th scope="row" colspan="2" class="px-4 py-2 text-right font-medium text-muted">Payments applied</th><td class="money px-4 py-2 text-right">{{ $figures->allocated->negate()->format() }}</td></tr>
                    <tr><th scope="row" colspan="2" class="px-4 py-2 text-right font-medium text-ink">Balance</th><td class="money px-4 py-2 text-right text-base font-semibold text-ink">{{ $invoice->status->isFinalized() ? $figures->balance()->format() : 'Not posted yet' }}</td></tr>
                </tfoot>
            </table>
        </section>

        <section class="overflow-x-auto rounded-md border border-line bg-surface" aria-labelledby="adjustments-title">
            <h3 id="adjustments-title" class="border-b border-line px-4 py-2 text-sm font-semibold text-ink">Adjustments and voids</h3>
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">No.</th>
                        <th scope="col" class="px-4 py-2">Kind</th>
                        <th scope="col" class="px-4 py-2 text-right">Amount</th>
                        <th scope="col" class="px-4 py-2">Status</th>
                        <th scope="col" class="px-4 py-2">Requested</th>
                        <th scope="col" class="px-4 py-2">Decision</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($adjustments as $adjustment)
                        <tr wire:key="adjustment-{{ $adjustment->id }}">
                            <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $adjustment->adjustment_no }}</td>
                            <td class="px-4 py-2">{{ $adjustment->kind->label() }}</td>
                            <td class="money px-4 py-2 text-right">{{ $adjustment->kind->increasesBalance() ? '' : '-' }}{{ $adjustment->amount_centavos->format() }}</td>
                            <td class="px-4 py-2"><x-ui.status-badge :status="$adjustment->status" /></td>
                            <td class="px-4 py-2">
                                {{ $adjustment->requester->full_name }}
                                <span class="block text-xs text-muted">{{ $adjustment->requested_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</span>
                                <span class="block text-xs text-muted">{{ $adjustment->reason }}</span>
                            </td>
                            <td class="px-4 py-2">
                                @if ($adjustment->approver)
                                    {{ $adjustment->approver->full_name }}
                                    <span class="block text-xs text-muted">{{ $adjustment->decided_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</span>
                                    @if ($adjustment->decision_note) <span class="block text-xs text-muted">{{ $adjustment->decision_note }}</span> @endif
                                @else
                                    <span class="text-muted">Waiting for approval</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-muted">No adjustments or voids on this invoice.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        @if ($ledger !== null)
            <section class="overflow-x-auto rounded-md border border-line bg-surface" aria-labelledby="ledger-title">
                <h3 id="ledger-title" class="border-b border-line px-4 py-2 text-sm font-semibold text-ink">Ledger postings for this invoice</h3>
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-2">Date</th>
                            <th scope="col" class="px-4 py-2">Description</th>
                            <th scope="col" class="px-4 py-2 text-right">Debit</th>
                            <th scope="col" class="px-4 py-2 text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($ledger as $entry)
                            <tr wire:key="ledger-{{ $entry->id }}">
                                <td class="whitespace-nowrap px-4 py-2">{{ $entry->entry_date->format('M j, Y') }}</td>
                                <td class="px-4 py-2">{{ $entry->description }}</td>
                                <td class="money px-4 py-2 text-right">{{ $entry->debit_centavos->isPositive() ? $entry->debit_centavos->format() : '' }}</td>
                                <td class="money px-4 py-2 text-right">{{ $entry->credit_centavos->isPositive() ? $entry->credit_centavos->format() : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-muted">Nothing is posted to the ledger for a draft invoice.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @endif
    </div>

    <div class="space-y-6">
        @if ($canRequest)
            <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="request-title">
                <h2 id="request-title" class="mb-1 text-base font-semibold text-ink">Request a correction</h2>
                <p class="mb-3 text-xs text-muted">A request changes nothing. A different authorized user must approve it before the ledger is touched.</p>
                <form wire:submit="requestAdjustment" class="space-y-3" novalidate>
                    @error('invoice') <p role="alert" class="alert alert-danger">{{ $message }}</p> @enderror
                    <div>
                        <label for="kind" class="label">Kind <span class="text-danger" aria-hidden="true">*</span></label>
                        <select id="kind" wire:model.live="kind" class="field">
                            <option value="">Choose...</option>
                            @foreach ($kinds as $k)
                                <option value="{{ $k->value }}">{{ $k->label() }}</option>
                            @endforeach
                        </select>
                        @error('kind') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    @if ($kind === \App\Domain\Billing\Enums\AdjustmentKind::Void->value)
                        <p class="rounded-md bg-canvas px-3 py-2 text-sm">Voids the whole outstanding balance of <span class="money font-medium">{{ $figures->balance()->format() }}</span>. The invoice keeps its number.
                            @if ($hasPendingVoid) <span class="block text-danger">A void request is already waiting.</span> @endif
                        </p>
                        @error('amount') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    @elseif ($kind !== '')
                        <div>
                            <label for="amount" class="label">Amount (pesos) <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="amount" type="text" inputmode="decimal" wire:model="amount" placeholder="0.00" class="field">
                            @error('amount') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label for="reason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="reason" type="text" wire:model="reason" maxlength="500" placeholder="Kept with the request and in the audit log" class="field">
                        @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="requestAdjustment" class="btn btn-primary">Send request</button>
                </form>
            </section>
        @elseif ($invoice->status === \App\Domain\Billing\Enums\InvoiceStatus::Void)
            <section class="rounded-md border border-dashed border-line bg-surface p-4 text-sm text-muted">This invoice is void and cannot be changed.</section>
        @elseif (! $invoice->status->isFinalized())
            <section class="rounded-md border border-dashed border-line bg-surface p-4 text-sm text-muted">A draft cannot be adjusted. Discard it on the Generate Billing screen instead.</section>
        @endif
    </div>
</div>
