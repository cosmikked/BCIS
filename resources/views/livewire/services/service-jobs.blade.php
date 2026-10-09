<div class="space-y-4">
    @if ($message !== '')
        <p role="status" class="alert alert-success">{{ $message }}</p>
    @endif

    <section class="rounded-md border border-line bg-surface p-4" aria-label="Search and filters">
        <div class="grid gap-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div>
                <label for="search" class="label">Search</label>
                <input id="search" type="search" wire:model.live.debounce.300ms="search" autocomplete="off" placeholder="Account no., name or address" class="field">
            </div>
            <div>
                <label for="status" class="label">Status</label>
                <select id="status" wire:model.live="status" class="field">
                    <option value="">Open jobs (not terminated)</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-3 flex items-center justify-between gap-3">
            <p class="text-sm text-muted" aria-live="polite">{{ number_format($rows->total()) }} {{ \Illuminate\Support\Str::plural('service', $rows->total()) }}</p>
            <button type="button" wire:click="clear" class="btn btn-secondary btn-sm">Clear filters</button>
        </div>
    </section>

    <section class="overflow-x-auto rounded-md border border-line bg-surface" wire:loading.class="opacity-60">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th scope="col" class="px-4 py-2">Account no.</th>
                    <th scope="col" class="px-4 py-2">Subscriber</th>
                    <th scope="col" class="px-4 py-2">Service address</th>
                    <th scope="col" class="px-4 py-2">Plan</th>
                    <th scope="col" class="px-4 py-2">Status</th>
                    <th scope="col" class="px-4 py-2">Since</th>
                    <th scope="col" class="px-4 py-2">Instruction</th>
                    @if ($canExecute)
                        <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($rows as $row)
                    <tr wire:key="job-{{ $row->id }}" @class(['bg-selected' => $selectedId === $row->id])>
                        <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $row->accountNo }}</td>
                        <td class="px-4 py-2">{{ $row->subscriberName }}</td>
                        <td class="px-4 py-2 text-muted">{{ $row->serviceAddress }}</td>
                        <td class="px-4 py-2">{{ $row->planName }} <span class="text-muted">({{ $row->serviceType }})</span></td>
                        <td class="px-4 py-2"><x-ui.status-badge :status="$row->status" /></td>
                        <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $row->statusDate?->timezone('Asia/Manila')->format('M j, Y') ?? '-' }}</td>
                        <td class="px-4 py-2">{{ $row->instruction() }}</td>
                        @if ($canExecute)
                            <td class="px-4 py-2 text-right">
                                @if ($row->status === \App\Domain\Services\Enums\ServiceStatus::Pending)
                                    <button type="button" wire:click="select({{ $row->id }})" aria-label="Mark {{ $row->accountNo }} installed" class="btn btn-primary btn-sm">Mark installed</button>
                                @endif
                            </td>
                        @endif
                    </tr>
                    @if ($canExecute && $selectedId === $row->id)
                        <tr wire:key="job-form-{{ $row->id }}" class="bg-selected">
                            <td colspan="8" class="px-4 py-3">
                                <form wire:submit="markInstalled" class="grid items-end gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto]" novalidate>
                                    <div>
                                        <label for="note" class="label">Installation note (optional)</label>
                                        <input id="note" type="text" wire:model="note" maxlength="400" placeholder="For example: modem fitted, signal checked" class="field">
                                        @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                                        @error('status') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="markInstalled"
                                            wire:confirm="Mark {{ $row->accountNo }} as installed? The service becomes active." class="btn btn-primary">Confirm installed</button>
                                    <button type="button" wire:click="cancel" class="btn btn-secondary">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-muted">No services match. Try a different search or status.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $rows->links() }}
</div>
