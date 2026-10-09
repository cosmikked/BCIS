<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="space-y-6">
        @if ($status !== '')
            <p role="status" class="alert alert-success">{{ $status }}</p>
        @endif

        <section class="overflow-x-auto rounded-md border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">Setting</th>
                        <th scope="col" class="px-4 py-2 text-right">Value</th>
                        <th scope="col" class="px-4 py-2 text-right">Default</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $row)
                        <tr wire:key="setting-{{ $row['key'] }}" @class(['bg-selected' => $selected && $selected['key'] === $row['key']])>
                            <td class="px-4 py-2">
                                <div class="font-medium text-ink">{{ $row['label'] }}</div>
                                <div class="font-mono text-xs text-muted">{{ $row['key'] }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $row['value'] }} <span class="text-muted">{{ $row['unit'] }}</span></td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted">{{ $row['default'] }}</td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="select('{{ $row['key'] }}')" aria-label="Change {{ $row['label'] }}" aria-pressed="{{ $selected && $selected['key'] === $row['key'] ? 'true' : 'false' }}" class="btn btn-secondary btn-sm">Change</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="overflow-x-auto rounded-md border border-line bg-surface" aria-labelledby="history-title">
            <h2 id="history-title" class="px-4 pt-4 text-base font-semibold text-ink">
                Change history @if ($selected) <span class="font-normal text-muted">for {{ $selected['label'] }}</span> @endif
            </h2>
            <table class="mt-2 w-full text-left text-sm">
                <thead class="border-y border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">When</th>
                        <th scope="col" class="px-4 py-2">Setting</th>
                        <th scope="col" class="px-4 py-2 text-right">Old</th>
                        <th scope="col" class="px-4 py-2 text-right">New</th>
                        <th scope="col" class="px-4 py-2">Changed by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($history as $change)
                        <tr wire:key="change-{{ $change->id }}">
                            <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $change->changed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $change->key }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ is_scalar($change->old_value) ? $change->old_value : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $change->new_value }}</td>
                            <td class="px-4 py-2">{{ $change->changed_by_name ?? 'System' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-muted">No changes yet. Settings use their defaults.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <div>
        @if ($selected)
            <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="change-title">
                <h2 id="change-title" class="text-base font-semibold text-ink">{{ $selected['label'] }}</h2>
                <p class="mb-3 text-xs text-muted">Whole number from {{ $selected['min'] }} to {{ $selected['max'] }} ({{ $selected['unit'] }}).</p>

                @error('form') <p role="alert" class="alert alert-danger mb-3">{{ $message }}</p> @enderror

                <form wire:submit="save" class="space-y-3" novalidate>
                    <div>
                        <label for="setting-value" class="label">New value <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="setting-value" type="text" inputmode="numeric" wire:model="value" required class="field tabular-nums">
                        @error('value') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="setting-reason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="setting-reason" type="text" wire:model="reason" maxlength="500" placeholder="Kept in the audit log" class="field">
                        @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary w-full">Save setting</button>
                </form>
            </section>
        @else
            <p class="rounded-md border border-line bg-surface p-4 text-sm text-muted">Choose a setting to change it. Every change keeps the old value, the new value, who made it and when.</p>
        @endif
    </div>
</div>
