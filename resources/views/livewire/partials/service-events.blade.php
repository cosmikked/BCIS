{{-- Read-only list of service_events (FR-SUB-008). Expects $events (a paginator) and $showAccount. --}}
@if ($events->isEmpty())
    <x-ui.empty-state title="No service events" message="Nothing matches. Events are recorded whenever a service account is created, activated, suspended, reconnected, terminated or reassigned to a collector." />
@else
    <div class="overflow-x-auto rounded-md border border-line bg-surface">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                <tr>
                    <th scope="col" class="px-4 py-2">When</th>
                    @if ($showAccount)
                        <th scope="col" class="px-4 py-2">Service account</th>
                    @endif
                    <th scope="col" class="px-4 py-2">Event</th>
                    <th scope="col" class="px-4 py-2">Status change</th>
                    <th scope="col" class="px-4 py-2">By</th>
                    <th scope="col" class="px-4 py-2">Reason / note</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($events as $event)
                    <tr wire:key="event-{{ $event->id }}">
                        <td class="whitespace-nowrap px-4 py-2 text-muted">{{ $event->occurred_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                        @if ($showAccount)
                            <td class="px-4 py-2">
                                <span class="font-medium text-ink">{{ $event->serviceAccount->account_no }}</span>
                                <span class="text-muted">· {{ $event->serviceAccount->subscriber->full_name }}</span>
                            </td>
                        @endif
                        <td class="px-4 py-2">{{ $event->event_type->label() }}</td>
                        <td class="px-4 py-2">
                            @if ($event->from_status && $event->to_status && $event->from_status !== $event->to_status)
                                <x-ui.status-badge :status="$event->from_status" /> <span aria-label="to">→</span> <x-ui.status-badge :status="$event->to_status" />
                            @elseif ($event->to_status)
                                <x-ui.status-badge :status="$event->to_status" />
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted">{{ $event->actor->full_name }}</td>
                        <td class="px-4 py-2">{{ $event->notes ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
