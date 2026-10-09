<div class="space-y-4">
    <section class="rounded-md border border-line bg-surface p-4" aria-label="Filters">
        @if ($filteredAccount)
            <p class="mb-3 text-sm">Showing the history of <span class="font-medium text-ink">{{ $filteredAccount->account_no }}</span>
                ({{ $filteredAccount->subscriber->full_name }})
                <button type="button" wire:click="$set('account', '')" class="ml-2 text-muted underline">Show all</button></p>
        @endif
        <div class="grid gap-3 md:grid-cols-3">
            <div>
                <label for="type" class="label">Event</label>
                <select id="type" wire:model.live="type" class="field">
                    <option value="">All events</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="from" class="label">From</label>
                <input id="from" type="date" wire:model.live="from" class="field">
            </div>
            <div>
                <label for="to" class="label">To</label>
                <input id="to" type="date" wire:model.live="to" class="field">
            </div>
        </div>
        <div class="mt-3 flex items-center justify-between gap-3">
            <p class="text-sm text-muted" aria-live="polite">{{ number_format($events->total()) }} {{ \Illuminate\Support\Str::plural('event', $events->total()) }}</p>
            <button type="button" wire:click="clear" class="btn btn-secondary btn-sm">Clear filters</button>
        </div>
    </section>

    @include('livewire.partials.service-events', ['events' => $events, 'showAccount' => ! $filteredAccount])

    {{ $events->links() }}

    <p class="text-xs text-muted">Service history is a permanent record. Rows cannot be edited or deleted.</p>
</div>
