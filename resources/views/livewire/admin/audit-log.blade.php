<div class="space-y-4">
    <form wire:submit="apply" class="grid gap-3 rounded-md border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-6" novalidate>
        <div>
            <label for="f-from" class="mb-1 block text-xs font-medium text-muted">From</label>
            <input id="f-from" type="date" wire:model="from" class="block w-full rounded-md border border-line px-2 py-1.5 text-sm">
            @error('from') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="f-to" class="mb-1 block text-xs font-medium text-muted">To</label>
            <input id="f-to" type="date" wire:model="to" class="block w-full rounded-md border border-line px-2 py-1.5 text-sm">
            @error('to') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="f-actor" class="mb-1 block text-xs font-medium text-muted">User</label>
            <select id="f-actor" wire:model="actorId" class="block w-full rounded-md border border-line px-2 py-1.5 text-sm">
                <option value="">Anyone</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->id }}">{{ $actor->full_name }} ({{ $actor->username }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-action" class="mb-1 block text-xs font-medium text-muted">Event</label>
            <select id="f-action" wire:model="action" class="block w-full rounded-md border border-line px-2 py-1.5 text-sm">
                <option value="">Any event</option>
                @foreach ($actions as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-entity" class="mb-1 block text-xs font-medium text-muted">Record type</label>
            <select id="f-entity" wire:model="entityType" class="block w-full rounded-md border border-line px-2 py-1.5 text-sm">
                <option value="">Any</option>
                @foreach ($entityTypes as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-request" class="mb-1 block text-xs font-medium text-muted">Request id</label>
            <input id="f-request" type="text" wire:model="requestId" maxlength="64" class="block w-full rounded-md border border-line px-2 py-1.5 font-mono text-sm">
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-6">
            <button type="submit" wire:loading.attr="disabled" wire:target="apply" class="rounded-md bg-accent px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Apply filters</button>
            <button type="button" wire:click="clear" class="rounded-md border border-line px-4 py-1.5 text-sm hover:bg-canvas">Clear</button>
            <span class="ml-auto text-xs text-muted">Read-only. Audit rows cannot be edited or deleted.</span>
        </div>
    </form>

    <section class="overflow-x-auto rounded-md border border-line bg-surface">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th scope="col" class="px-4 py-2">When (Manila)</th>
                    <th scope="col" class="px-4 py-2">User</th>
                    <th scope="col" class="px-4 py-2">Event</th>
                    <th scope="col" class="px-4 py-2">Record</th>
                    <th scope="col" class="px-4 py-2">Reason</th>
                    <th scope="col" class="px-4 py-2">Details</th>
                    <th scope="col" class="px-4 py-2">Request</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line align-top">
                @forelse ($logs as $log)
                    <tr wire:key="log-{{ $log->id }}">
                        <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $log->occurred_at->timezone('Asia/Manila')->format('M j, Y g:i:s A') }}</td>
                        <td class="px-4 py-2">
                            @if ($log->actor_name)
                                {{ $log->actor_name }} <span class="text-xs text-muted">({{ $log->actor_username }})</span>
                            @else
                                <span class="text-muted">No user</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $log->action }}</td>
                        <td class="px-4 py-2">{{ $log->entity_type ? $log->entity_type.($log->entity_id ? ' #'.$log->entity_id : '') : '—' }}</td>
                        <td class="max-w-xs px-4 py-2">{{ $log->reason ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($log->old_values || $log->new_values)
                                <details>
                                    <summary class="cursor-pointer text-accent">Show</summary>
                                    @if ($log->old_values)
                                        <p class="mt-1 text-xs font-semibold text-muted">Before</p>
                                        <pre class="whitespace-pre-wrap break-words text-xs">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @endif
                                    @if ($log->new_values)
                                        <p class="mt-1 text-xs font-semibold text-muted">After</p>
                                        <pre class="whitespace-pre-wrap break-words text-xs">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @endif
                                </details>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 font-mono text-xs text-muted">{{ $log->request_id ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted">No audit records match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $logs->links() }}
</div>
