<div class="space-y-4">
    <section class="rounded-md border border-line bg-surface p-4" aria-label="Search and filters">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))]">
            <div>
                <label for="search" class="label">Search</label>
                <input id="search" type="search" wire:model.live.debounce.300ms="search" autocomplete="off"
                       placeholder="Account no., name, phone, email or address" class="field">
                @if ($searchTooShort)
                    <p class="mt-1 text-xs text-muted">Type at least 2 characters to search.</p>
                @endif
            </div>
            <div>
                <label for="status" class="label">Status</label>
                <select id="status" wire:model.live="status" class="field">
                    <option value="">All</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="area" class="label">Area</label>
                <select id="area" wire:model.live="area" class="field">
                    <option value="">All</option>
                    @foreach ($areas as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="collector" class="label">Collector</label>
                <select id="collector" wire:model.live="collector" class="field">
                    <option value="">All</option>
                    @foreach ($collectors as $c)
                        <option value="{{ $c->id }}">{{ $c->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type" class="label">Service type</label>
                <select id="type" wire:model.live="type" class="field">
                    <option value="">All</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-3 flex items-center justify-between gap-3">
            <p class="text-sm text-muted" aria-live="polite">{{ number_format($subscribers->total()) }} {{ \Illuminate\Support\Str::plural('subscriber', $subscribers->total()) }}</p>
            <div class="flex gap-2">
                <button type="button" wire:click="clear" class="btn btn-secondary btn-sm">Clear filters</button>
                @if ($canManage)
                    <a href="{{ route('subscribers.create') }}" wire:navigate class="btn btn-primary btn-sm">New subscriber</a>
                @endif
            </div>
        </div>
    </section>

    <section class="overflow-x-auto rounded-md border border-line bg-surface" wire:loading.class="opacity-60">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th scope="col" class="px-4 py-2">Account no.</th>
                    <th scope="col" class="px-4 py-2">Name</th>
                    <th scope="col" class="px-4 py-2">Status</th>
                    <th scope="col" class="px-4 py-2">Area</th>
                    <th scope="col" class="px-4 py-2">Primary contact</th>
                    <th scope="col" class="px-4 py-2 text-right">Services</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">Open</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($subscribers as $subscriber)
                    <tr wire:key="subscriber-{{ $subscriber->id }}">
                        <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $subscriber->account_no }}</td>
                        <td class="px-4 py-2">{{ $subscriber->full_name }}</td>
                        <td class="px-4 py-2"><x-ui.status-badge :status="$subscriber->status" /></td>
                        <td class="px-4 py-2 text-muted">{{ $subscriber->collectionArea?->name ?? '-' }}</td>
                        <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $subscriber->contacts->first()?->value ?? '-' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $subscriber->service_accounts_count }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('subscribers.show', $subscriber->id) }}" wire:navigate aria-label="Open {{ $subscriber->full_name }}" class="btn btn-secondary btn-sm">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-muted">
                            No subscribers match. Try fewer filters or a different search.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $subscribers->links() }}
</div>
