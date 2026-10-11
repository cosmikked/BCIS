<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
    <div class="min-w-0 space-y-6">
        @if ($message !== '')
            <p role="status" class="alert alert-success">{{ $message }}</p>
        @endif

        <section class="overflow-x-auto rounded-md border border-line bg-surface" aria-labelledby="pending-title">
            <h2 id="pending-title" class="border-b border-line px-4 py-2 text-sm font-semibold text-ink">Waiting for a decision ({{ $pending->count() }})</h2>
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">No.</th>
                        <th scope="col" class="px-4 py-2">Invoice</th>
                        <th scope="col" class="px-4 py-2">Kind</th>
                        <th scope="col" class="px-4 py-2 text-right">Amount</th>
                        <th scope="col" class="px-4 py-2">Requested by</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($pending as $adjustment)
                        <tr wire:key="pending-{{ $adjustment->id }}" @class(['bg-selected' => $selected?->id === $adjustment->id])>
                            <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $adjustment->adjustment_no }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ route('billing.invoices.show', $adjustment->invoice_id) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $adjustment->invoice->invoice_no }}</a>
                                <span class="block text-xs text-muted">{{ $adjustment->invoice->subscriber->full_name }}</span>
                            </td>
                            <td class="px-4 py-2">{{ $adjustment->kind->label() }}</td>
                            <td class="money px-4 py-2 text-right">{{ $adjustment->amount_centavos->format() }}</td>
                            <td class="px-4 py-2">
                                {{ $adjustment->requester->full_name }}
                                <span class="block text-xs text-muted">{{ $adjustment->requested_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="select({{ $adjustment->id }})" aria-label="Review {{ $adjustment->adjustment_no }}" aria-pressed="{{ $selected?->id === $adjustment->id ? 'true' : 'false' }}" class="btn btn-secondary btn-sm">Review</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No requests are waiting.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="overflow-x-auto rounded-md border border-line bg-surface" aria-labelledby="recent-title">
            <h2 id="recent-title" class="border-b border-line px-4 py-2 text-sm font-semibold text-ink">Recently decided</h2>
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">No.</th>
                        <th scope="col" class="px-4 py-2">Invoice</th>
                        <th scope="col" class="px-4 py-2">Kind</th>
                        <th scope="col" class="px-4 py-2 text-right">Amount</th>
                        <th scope="col" class="px-4 py-2">Result</th>
                        <th scope="col" class="px-4 py-2">Decided by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($recent as $adjustment)
                        <tr wire:key="recent-{{ $adjustment->id }}">
                            <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $adjustment->adjustment_no }}</td>
                            <td class="px-4 py-2"><a href="{{ route('billing.invoices.show', $adjustment->invoice_id) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $adjustment->invoice->invoice_no }}</a></td>
                            <td class="px-4 py-2">{{ $adjustment->kind->label() }}</td>
                            <td class="money px-4 py-2 text-right">{{ $adjustment->amount_centavos->format() }}</td>
                            <td class="px-4 py-2"><x-ui.status-badge :status="$adjustment->status" /></td>
                            <td class="px-4 py-2">
                                {{ $adjustment->approver?->full_name }}
                                @if ($adjustment->decision_note) <span class="block text-xs text-muted">{{ $adjustment->decision_note }}</span> @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-muted">Nothing decided yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <div class="space-y-6">
        @if ($selected)
            <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="review-title">
                <h2 id="review-title" class="text-base font-semibold text-ink">{{ $selected->adjustment_no }}</h2>
                <p class="mb-3 text-xs text-muted">{{ $selected->kind->label() }} on {{ $selected->invoice->invoice_no }}</p>
                <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1.5 text-sm">
                    <dt class="text-muted">Subscriber</dt><dd>{{ $selected->invoice->subscriber->full_name }}</dd>
                    <dt class="text-muted">Requested amount</dt><dd class="money">{{ $selected->amount_centavos->format() }}</dd>
                    <dt class="text-muted">Balance now</dt><dd class="money">{{ $currentBalance->format() }}</dd>
                    <dt class="text-muted">Reason</dt><dd>{{ $selected->reason }}</dd>
                    <dt class="text-muted">Requested by</dt><dd>{{ $selected->requester->full_name }}</dd>
                </dl>

                @if ($selected->requested_by === $me)
                    <p class="mt-4 rounded-md bg-canvas px-3 py-2 text-sm text-muted">You asked for this, so another authorized user has to decide it.</p>
                @else
                    <form class="mt-4 space-y-3 border-t border-line pt-4" novalidate>
                        @error('adjustment') <p role="alert" class="alert alert-danger">{{ $message }}</p> @enderror
                        @error('amount') <p role="alert" class="alert alert-danger">{{ $message }}</p> @enderror
                        @error('invoice') <p role="alert" class="alert alert-danger">{{ $message }}</p> @enderror
                        <div>
                            <label for="note" class="label">Note (required to reject)</label>
                            <input id="note" type="text" wire:model="note" maxlength="500" class="field">
                            @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="approve" wire:loading.attr="disabled" wire:target="approve,reject"
                                    wire:confirm="Approve {{ $selected->adjustment_no }}? This posts a ledger entry that cannot be edited or deleted." class="btn btn-primary btn-sm">Approve</button>
                            <button type="button" wire:click="reject" wire:loading.attr="disabled" wire:target="approve,reject" class="btn btn-secondary btn-sm">Reject</button>
                        </div>
                    </form>
                @endif
            </section>
        @else
            <section class="rounded-md border border-dashed border-line bg-surface p-4 text-sm text-muted">Review a request to see the invoice balance as it is now and to approve or reject it.</section>
        @endif
    </div>
</div>
