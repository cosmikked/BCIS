<div class="space-y-6">
    @if ($message !== '')
        <p role="status" class="alert alert-success">{{ $message }}</p>
    @endif

    <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="period-title">
        <h2 id="period-title" class="mb-3 text-base font-semibold text-ink">Billing month</h2>
        <form wire:submit="preview" class="flex flex-wrap items-end gap-3" novalidate>
            <div>
                <label for="period" class="label">Month</label>
                <input id="period" type="month" wire:model.live="period" class="field">
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="preview" class="btn btn-secondary">Preview</button>
        </form>
        @error('period') <p role="alert" class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
        <p class="mt-2 text-xs text-muted">Preview shows who will be billed and who is skipped. It saves nothing.</p>
    </section>

    @if ($cycle)
        <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="cycle-title">
            <h2 id="cycle-title" class="mb-3 text-base font-semibold text-ink">{{ $cycle->period_month->format('F Y') }} <x-ui.status-badge :status="$cycle->status" class="ml-2" /></h2>
            <dl class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1.5 text-sm">
                <dt class="text-muted">Invoices</dt><dd>{{ number_format($cycle->invoice_count) }}</dd>
                <dt class="text-muted">Total</dt><dd class="money">{{ $cycle->total_centavos->format() }}</dd>
                @if ($cycle->finalized_at)
                    <dt class="text-muted">Finalized</dt><dd>{{ $cycle->finalized_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</dd>
                @endif
            </dl>
            @if ($cycle->status === \App\Domain\Billing\Enums\BillingCycleStatus::Finalized)
                <p class="mt-3 text-sm text-muted">This month is finalized. It cannot be generated or discarded again; correct an invoice with an adjustment or a void.</p>
            @endif
        </section>
    @endif

    @if ($preview)
        <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="preview-title">
            <h2 id="preview-title" class="mb-3 text-base font-semibold text-ink">Preview for {{ $periodDate->format('F Y') }}</h2>

            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div class="rounded-md bg-canvas px-3 py-2"><dt class="text-muted">To bill</dt><dd class="text-lg font-semibold text-ink">{{ number_format(count($preview->plan->lines)) }}</dd></div>
                <div class="rounded-md bg-canvas px-3 py-2"><dt class="text-muted">Total</dt><dd class="money text-lg font-semibold text-ink">{{ $preview->plan->total()->format() }}</dd></div>
                <div class="rounded-md bg-canvas px-3 py-2"><dt class="text-muted">Skipped</dt><dd class="text-lg font-semibold text-ink">{{ number_format(count($preview->plan->skipped)) }}</dd></div>
            </dl>

            @if ($preview->isFinalized())
                <p role="alert" class="alert alert-danger mt-4">This month is already finalized and cannot be generated again.</p>
            @endif

            @if (count($preview->plan->lines) > 0)
                <div class="mt-4 overflow-x-auto rounded-md border border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                            <tr>
                                <th scope="col" class="px-4 py-2">Account no.</th>
                                <th scope="col" class="px-4 py-2">Subscriber</th>
                                <th scope="col" class="px-4 py-2">Due</th>
                                <th scope="col" class="px-4 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($lines as $line)
                                <tr wire:key="line-{{ $line->account->id }}">
                                    <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $line->account->account_no }}</td>
                                    <td class="px-4 py-2">{{ $line->account->subscriber->full_name }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">{{ $line->dueDate->format('M j, Y') }}</td>
                                    <td class="money px-4 py-2 text-right">
                                        {{ $line->total()->format() }}
                                        @if ($line->hasInstallationFee()) <span class="block text-xs text-muted">includes installation {{ $line->installationFee->format() }}</span> @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (count($preview->plan->lines) > $shown)
                    <p class="mt-2 text-xs text-muted">Showing the first {{ $shown }} of {{ number_format(count($preview->plan->lines)) }}. Generate creates all of them.</p>
                @endif
            @endif

            @if (count($skippedGroups) > 0)
                <h3 class="mt-4 text-sm font-semibold text-ink">Skipped accounts</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($skippedGroups as $group)
                        <li wire:key="skip-{{ $group['reason']->value }}">
                            <span class="font-medium text-ink">{{ $group['reason']->label() }}</span>: {{ number_format($group['count']) }}
                            <span class="text-muted">({{ implode(', ', $group['names']) }}{{ $group['count'] > count($group['names']) ? ', ...' : '' }})</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (! $preview->isFinalized() && count($preview->plan->lines) > 0)
                <div class="mt-4 border-t border-line pt-4">
                    <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" class="btn btn-primary">Generate {{ number_format(count($preview->plan->lines)) }} draft invoices</button>
                    <p class="mt-2 text-xs text-muted">Drafts have no invoice number and no ledger entry. Running this twice never bills an account twice.</p>
                </div>
            @endif
        </section>
    @endif

    @if ($cycle && $cycle->status === \App\Domain\Billing\Enums\BillingCycleStatus::Draft && $draftCount > 0)
        <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="drafts-title">
            <h2 id="drafts-title" class="mb-3 text-base font-semibold text-ink">Draft invoices</h2>
            <div class="overflow-x-auto rounded-md border border-line">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-2">Account no.</th>
                            <th scope="col" class="px-4 py-2">Subscriber</th>
                            <th scope="col" class="px-4 py-2">Due</th>
                            <th scope="col" class="px-4 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($drafts as $draft)
                            <tr wire:key="draft-{{ $draft->id }}">
                                <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $draft->serviceAccount->account_no }}</td>
                                <td class="px-4 py-2">{{ $draft->subscriber->full_name }}</td>
                                <td class="whitespace-nowrap px-4 py-2">{{ $draft->due_date->format('M j, Y') }}</td>
                                <td class="money px-4 py-2 text-right">{{ $draft->total_centavos->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($draftCount > $shown)
                <p class="mt-2 text-xs text-muted">Showing the first {{ $shown }} of {{ number_format($draftCount) }}.</p>
            @endif

            <div class="mt-4 grid gap-6 border-t border-line pt-4 md:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Finalize</h3>
                    <p class="mb-3 text-xs text-muted">Numbers every invoice, posts each to the subscriber ledger and locks them. This cannot be undone; later corrections are adjustments or voids.</p>
                    @error('cycle') <p role="alert" class="alert alert-danger mb-3">{{ $message }}</p> @enderror
                    <button type="button" wire:click="finalize" wire:loading.attr="disabled" wire:target="finalize"
                            wire:confirm="Finalize {{ $cycle->period_month->format('F Y') }}? {{ number_format($draftCount) }} invoices will be numbered and posted to the ledger. This cannot be undone." class="btn btn-primary">Finalize {{ $cycle->period_month->format('F Y') }}</button>
                </div>
                <form wire:submit="discard" class="space-y-3" novalidate>
                    <h3 class="text-sm font-semibold text-ink">Discard drafts</h3>
                    <div>
                        <label for="discardReason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="discardReason" type="text" wire:model="discardReason" maxlength="500" placeholder="Kept in the audit log" class="field">
                        @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="discard"
                            wire:confirm="Discard all draft invoices for {{ $cycle->period_month->format('F Y') }}? Nothing has been numbered or posted yet." class="btn btn-secondary btn-sm">Discard drafts</button>
                </form>
            </div>
        </section>
    @endif
</div>
