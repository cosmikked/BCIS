<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
    <section class="min-w-0 overflow-x-auto rounded-md border border-line bg-surface" aria-label="Billing cycles">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th scope="col" class="px-4 py-2">Month</th>
                    <th scope="col" class="px-4 py-2">Status</th>
                    <th scope="col" class="px-4 py-2 text-right">Invoices</th>
                    <th scope="col" class="px-4 py-2 text-right">Billed</th>
                    <th scope="col" class="px-4 py-2">Finalized</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($cycles as $c)
                    <tr wire:key="cycle-{{ $c->id }}" @class(['bg-selected' => $selected?->id === $c->id])>
                        <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $c->period_month->format('F Y') }}</td>
                        <td class="px-4 py-2"><x-ui.status-badge :status="$c->status" /></td>
                        <td class="px-4 py-2 text-right">{{ number_format($c->invoice_count) }}</td>
                        <td class="money px-4 py-2 text-right">{{ $c->total_centavos->format() }}</td>
                        <td class="px-4 py-2 text-muted">{{ $c->finalized_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? '-' }}</td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" wire:click="$set('cycle', '{{ $c->id }}')" aria-label="Show {{ $c->period_month->format('F Y') }}" aria-pressed="{{ $selected?->id === $c->id ? 'true' : 'false' }}" class="btn btn-secondary btn-sm">Show</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-muted">
                        No month has been billed yet.
                        @if ($canGenerate) <a href="{{ route('billing.generate') }}" wire:navigate class="underline">Generate billing</a> @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div class="space-y-6">
        @if ($selected)
            <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="cycle-title">
                <h2 id="cycle-title" class="text-base font-semibold text-ink">{{ $selected->period_month->format('F Y') }}</h2>
                <p class="mb-3 text-xs text-muted">
                    @if ($selected->status === \App\Domain\Billing\Enums\BillingCycleStatus::Finalized)
                        Finalized {{ $selected->finalized_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}
                    @else
                        Draft: invoices are not numbered or posted to the ledger until the month is finalized.
                    @endif
                </p>

                <dl class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1.5 text-sm">
                    <dt class="text-muted">Invoices</dt><dd>{{ number_format($selected->invoice_count) }}</dd>
                    <dt class="text-muted">Billed</dt><dd class="money">{{ $selected->total_centavos->format() }}</dd>
                    @if ($selected->status === \App\Domain\Billing\Enums\BillingCycleStatus::Finalized)
                        <dt class="text-muted">Still owed</dt><dd class="money">{{ $outstanding?->format() }}</dd>
                        <dt class="text-muted">Overdue</dt><dd>{{ number_format($overdue) }}</dd>
                    @endif
                </dl>

                <h3 class="mt-4 border-t border-line pt-4 text-sm font-semibold text-ink">Invoices by status</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($statusCounts as $status => $count)
                        @if ($count > 0)
                            <li class="flex items-center justify-between" wire:key="status-{{ $status }}">
                                <x-ui.status-badge :status="$status" />
                                <span>{{ number_format($count) }}</span>
                            </li>
                        @endif
                    @endforeach
                </ul>

                <div class="mt-4 flex gap-2 border-t border-line pt-4">
                    <a href="{{ route('billing.invoices', ['cycle' => $selected->id]) }}" wire:navigate class="btn btn-secondary btn-sm">View invoices</a>
                    @if ($canGenerate && $selected->status === \App\Domain\Billing\Enums\BillingCycleStatus::Draft)
                        <a href="{{ route('billing.generate', ['period' => $selected->period_month->format('Y-m')]) }}" wire:navigate class="btn btn-primary btn-sm">Review and finalize</a>
                    @endif
                </div>
            </section>
        @endif
    </div>
</div>
