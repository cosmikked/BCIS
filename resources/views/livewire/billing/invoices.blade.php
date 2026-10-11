<div class="space-y-4">
    <section class="rounded-md border border-line bg-surface p-4" aria-label="Search and filters">
        @if ($filteredSubscriber)
            <p class="mb-3 text-sm">Showing invoices of <a href="{{ route('subscribers.show', $filteredSubscriber->id) }}" wire:navigate class="font-medium underline">{{ $filteredSubscriber->full_name }}</a>
                <button type="button" wire:click="$set('subscriber', '')" class="ml-2 text-muted underline">Show all</button></p>
        @endif
        @if ($cycle !== '')
            <p class="mb-3 text-sm">Showing one billing cycle, drafts included.
                <button type="button" wire:click="$set('cycle', '')" class="ml-2 text-muted underline">Show all</button></p>
        @endif
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]">
            <div>
                <label for="search" class="label">Search</label>
                <input id="search" type="search" wire:model.live.debounce.300ms="search" autocomplete="off" placeholder="Invoice no., subscriber, phone or address" class="field">
            </div>
            <div>
                <label for="period" class="label">Billing month</label>
                <input id="period" type="month" wire:model.live="period" class="field">
            </div>
            <div>
                <label for="status" class="label">Status</label>
                <select id="status" wire:model.live="status" class="field">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                    <option value="OVERDUE">Overdue</option>
                </select>
            </div>
            <div>
                <label for="type" class="label">Type</label>
                <select id="type" wire:model.live="type" class="field">
                    <option value="">All</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-3 flex items-center justify-between gap-3">
            <p class="text-sm text-muted" aria-live="polite">{{ number_format($invoices->total()) }} {{ \Illuminate\Support\Str::plural('invoice', $invoices->total()) }}</p>
            <button type="button" wire:click="clear" class="btn btn-secondary btn-sm">Clear filters</button>
        </div>
    </section>

    <section class="overflow-x-auto rounded-md border border-line bg-surface" wire:loading.class="opacity-60">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th scope="col" class="px-4 py-2">Invoice no.</th>
                    <th scope="col" class="px-4 py-2">Subscriber</th>
                    <th scope="col" class="px-4 py-2">Period</th>
                    <th scope="col" class="px-4 py-2">Due</th>
                    <th scope="col" class="px-4 py-2 text-right">Total</th>
                    <th scope="col" class="px-4 py-2 text-right">Balance</th>
                    <th scope="col" class="px-4 py-2">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    @php($invoice = $row->invoice)
                    <tr wire:key="invoice-{{ $invoice->id }}">
                        <td class="whitespace-nowrap px-4 py-2 font-medium text-ink"><a href="{{ route('billing.invoices.show', $invoice->id) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $invoice->invoice_no ?? 'Draft' }}</a></td>
                        <td class="px-4 py-2">
                            <a href="{{ route('subscribers.show', $invoice->subscriber_id) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $invoice->subscriber->full_name }}</a>
                            <span class="block text-xs text-muted">{{ $invoice->serviceAccount->account_no }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2">{{ $invoice->billing_period?->format('M Y') ?? 'One-off' }}</td>
                        <td class="whitespace-nowrap px-4 py-2">
                            {{ $invoice->due_date->format('M j, Y') }}
                            @if ($row->overdue) <span class="block text-xs text-danger">{{ $row->daysPastDue }} {{ \Illuminate\Support\Str::plural('day', $row->daysPastDue) }} past due</span> @endif
                        </td>
                        <td class="money px-4 py-2 text-right">{{ $invoice->total_centavos->format() }}</td>
                        <td class="money px-4 py-2 text-right">{{ $invoice->status->isFinalized() ? $row->balance->format() : '-' }}</td>
                        <td class="px-4 py-2">
                            <x-ui.status-badge :status="$row->overdue ? 'OVERDUE' : $invoice->status" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-muted">No invoices match. Try fewer filters or a different search.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $invoices->links() }}
</div>
