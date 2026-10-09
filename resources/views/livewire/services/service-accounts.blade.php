<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
    <div class="min-w-0 space-y-4">
        @if ($message !== '')
            <p role="status" class="alert alert-success">{{ $message }}</p>
        @endif

        <section class="rounded-md border border-line bg-surface p-4" aria-label="Search and filters">
            @if ($filteredSubscriber)
                <p class="mb-3 text-sm">Showing accounts of <a href="{{ route('subscribers.show', $filteredSubscriber->id) }}" wire:navigate class="font-medium underline">{{ $filteredSubscriber->full_name }}</a>
                    <button type="button" wire:click="$set('subscriber', '')" class="ml-2 text-muted underline">Show all</button></p>
            @endif
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))]">
                <div>
                    <label for="search" class="label">Search</label>
                    <input id="search" type="search" wire:model.live.debounce.300ms="search" autocomplete="off" placeholder="Account no., name, phone or address" class="field">
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
                    <label for="type" class="label">Service type</label>
                    <select id="type" wire:model.live="type" class="field">
                        <option value="">All</option>
                        @foreach ($types as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="collector" class="label">Collector</label>
                    <select id="collector" wire:model.live="collector" class="field">
                        <option value="">All</option>
                        @foreach ($filterCollectors as $c)
                            <option value="{{ $c->id }}">{{ $c->full_name }}</option>
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
            </div>
            <div class="mt-3 flex items-center justify-between gap-3">
                <p class="text-sm text-muted" aria-live="polite">{{ number_format($accounts->total()) }} {{ \Illuminate\Support\Str::plural('service account', $accounts->total()) }}</p>
                <div class="flex gap-2">
                    <button type="button" wire:click="clear" class="btn btn-secondary btn-sm">Clear filters</button>
                    @if ($canCreate)
                        <button type="button" wire:click="startCreate" class="btn btn-primary btn-sm">New service account</button>
                    @endif
                </div>
            </div>
        </section>

        <section class="overflow-x-auto rounded-md border border-line bg-surface" wire:loading.class="opacity-60">
            <table class="w-full text-left text-sm">
                <thead class="sticky top-0 border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">Account no.</th>
                        <th scope="col" class="px-4 py-2">Subscriber</th>
                        <th scope="col" class="px-4 py-2">Plan</th>
                        <th scope="col" class="px-4 py-2 text-right">Rate</th>
                        <th scope="col" class="px-4 py-2">Status</th>
                        <th scope="col" class="px-4 py-2">Collector</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($accounts as $account)
                        <tr wire:key="account-{{ $account->id }}" @class(['bg-selected' => $selected?->id === $account->id])>
                            <td class="whitespace-nowrap px-4 py-2 font-medium text-ink">{{ $account->account_no }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ route('subscribers.show', $account->subscriber_id) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $account->subscriber->full_name }}</a>
                                <span class="block text-xs text-muted">{{ $account->serviceAddress->oneLine() }}</span>
                            </td>
                            <td class="px-4 py-2">{{ $account->plan->name }}</td>
                            <td class="money px-4 py-2 text-right">{{ $account->current_rate_centavos->format() }}</td>
                            <td class="px-4 py-2"><x-ui.status-badge :status="$account->status" /></td>
                            <td class="px-4 py-2 text-muted">{{ $account->collector?->full_name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="select({{ $account->id }})" aria-label="Open {{ $account->account_no }}" aria-pressed="{{ $selected?->id === $account->id ? 'true' : 'false' }}" class="btn btn-secondary btn-sm">Open</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-muted">No service accounts match. Try fewer filters or a different search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{ $accounts->links() }}
    </div>

    <div class="space-y-6">
        @if ($creating)
            <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="create-title">
                <h2 id="create-title" class="mb-3 text-base font-semibold text-ink">New service account</h2>
                @error('form') <p role="alert" class="alert alert-danger mb-3">{{ $message }}</p> @enderror

                @if (! $newSubscriber)
                    <label for="subscriberLookup" class="label">Find the subscriber <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="subscriberLookup" type="search" wire:model.live.debounce.300ms="subscriberLookup" autocomplete="off" placeholder="Name, account no., phone or address" class="field">
                    @error('subscriber_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    @if (count($lookup) > 0)
                        <ul class="mt-2 divide-y divide-line rounded-md border border-line text-sm">
                            @foreach ($lookup as $hit)
                                <li wire:key="hit-{{ $hit->subscriberId }}" class="flex items-center justify-between gap-2 px-3 py-2">
                                    <span>{{ $hit->fullName }} <span class="text-muted">· {{ $hit->accountNo }}</span></span>
                                    @if ($hit->status === \App\Domain\Subscribers\Enums\SubscriberStatus::Active)
                                        <button type="button" wire:click="chooseSubscriber({{ $hit->subscriberId }})" class="btn btn-secondary btn-sm">Choose</button>
                                    @else
                                        <x-ui.status-badge :status="$hit->status" />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($subscriberLookup)) >= 2)
                        <p class="mt-2 text-sm text-muted">No subscriber found.</p>
                    @endif
                @else
                    <form wire:submit="create" class="space-y-3" novalidate>
                        <p class="rounded-md bg-canvas px-3 py-2 text-sm"><span class="font-medium text-ink">{{ $newSubscriber->full_name }}</span> <span class="text-muted">· {{ $newSubscriber->account_no }}</span></p>
                        <div>
                            <label for="planId" class="label">Plan <span class="text-danger" aria-hidden="true">*</span></label>
                            <select id="planId" wire:model="planId" class="field">
                                <option value="">Choose...</option>
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name }} ({{ $plan->serviceType->name }}) · {{ $plan->price_centavos->format() }}</option>
                                @endforeach
                            </select>
                            @error('plan_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-muted">The rate is copied from the plan now and does not change when the plan price changes.</p>
                        </div>
                        <div>
                            <label for="serviceAddressId" class="label">Service address <span class="text-danger" aria-hidden="true">*</span></label>
                            <select id="serviceAddressId" wire:model="serviceAddressId" class="field">
                                <option value="">Choose...</option>
                                @foreach ($newSubscriber->addresses as $address)
                                    <option value="{{ $address->id }}">{{ $address->oneLine() }}</option>
                                @endforeach
                            </select>
                            @error('service_address_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            @if ($newSubscriber->addresses->isEmpty())
                                <p class="mt-1 text-xs text-danger">This subscriber has no service address. Add one by editing the subscriber.</p>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="billingStartDate" class="label">Billing starts <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="billingStartDate" type="date" wire:model="billingStartDate" class="field">
                                @error('billing_start_date') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="billingDueDay" class="label">Due day</label>
                                <input id="billingDueDay" type="number" min="1" max="31" wire:model="billingDueDay" placeholder="{{ $newSubscriber->billing_due_day }}" class="field">
                                @error('billing_due_day') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        @if ($canAssign)
                            <div>
                                <label for="newCollectorId" class="label">Collector</label>
                                <select id="newCollectorId" wire:model="newCollectorId" class="field">
                                    <option value="">Assign later</option>
                                    @foreach ($collectors as $c)
                                        <option value="{{ $c->id }}">{{ $c->full_name }}</option>
                                    @endforeach
                                </select>
                                @error('collector_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div class="flex gap-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="create" class="btn btn-primary">Create account</button>
                            <button type="button" wire:click="cancelCreate" class="btn btn-secondary">Cancel</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @if ($selected)
            <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="account-title">
                <h2 id="account-title" class="text-base font-semibold text-ink">{{ $selected->account_no }}</h2>
                <p class="mb-3 text-xs text-muted">{{ $selected->subscriber->full_name }} · {{ $selected->plan->name }}</p>
                <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5 text-sm">
                    <dt class="text-muted">Status</dt><dd><x-ui.status-badge :status="$selected->status" /></dd>
                    <dt class="text-muted">Monthly rate</dt><dd class="money">{{ $selected->current_rate_centavos->format() }}</dd>
                    <dt class="text-muted">Address</dt><dd>{{ $selected->serviceAddress->oneLine() }}</dd>
                    <dt class="text-muted">Area</dt><dd>{{ $selected->serviceAddress->collectionArea?->name ?? '-' }}</dd>
                    <dt class="text-muted">Collector</dt><dd>{{ $selected->collector?->full_name ?? 'Unassigned' }}</dd>
                    <dt class="text-muted">Billing starts</dt><dd>{{ $selected->billing_start_date->format('M j, Y') }}, due day {{ $selected->billing_due_day }}</dd>
                    <dt class="text-muted">Activated</dt><dd>{{ $selected->activation_date?->format('M j, Y') ?? '-' }}</dd>
                </dl>

                @if ($canChangeStatus && count($selected->status->allowedNext()) > 0)
                    <form wire:submit="changeStatus" class="mt-4 space-y-3 border-t border-line pt-4" novalidate>
                        <h3 class="text-sm font-semibold text-ink">Change status</h3>
                        @error('status') <p role="alert" class="alert alert-danger">{{ $message }}</p> @enderror
                        <div>
                            <label for="statusTo" class="label">New status <span class="text-danger" aria-hidden="true">*</span></label>
                            <select id="statusTo" wire:model="statusTo" class="field">
                                <option value="">Choose...</option>
                                @foreach ($selected->status->allowedNext() as $next)
                                    <option value="{{ $next->value }}">{{ $next->label() }}</option>
                                @endforeach
                            </select>
                            @error('statusTo') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="reason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="reason" type="text" wire:model="reason" maxlength="500" placeholder="Kept in the service history" class="field">
                            @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" wire:loading.attr="disabled" wire:target="changeStatus"
                                wire:confirm="Change the status of {{ $selected->account_no }}? This is recorded in the service history and cannot be edited." class="btn btn-primary btn-sm">Change status</button>
                    </form>
                @endif

                @if ($canAssign && $selected->status !== \App\Domain\Services\Enums\ServiceStatus::Terminated)
                    <form wire:submit="assignCollector" class="mt-4 space-y-3 border-t border-line pt-4" novalidate>
                        <h3 class="text-sm font-semibold text-ink">Assign collector</h3>
                        <div>
                            <label for="assignCollectorId" class="label">Collector <span class="text-danger" aria-hidden="true">*</span></label>
                            <select id="assignCollectorId" wire:model="assignCollectorId" class="field">
                                <option value="">Choose...</option>
                                @foreach ($collectors as $c)
                                    <option value="{{ $c->id }}">{{ $c->full_name }}</option>
                                @endforeach
                            </select>
                            @error('assignCollectorId') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                            @error('collector_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="effectiveFrom" class="label">From (blank = today)</label>
                            <input id="effectiveFrom" type="date" wire:model="effectiveFrom" class="field">
                            @error('effective_from') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" wire:loading.attr="disabled" wire:target="assignCollector" class="btn btn-secondary btn-sm">Assign collector</button>
                        <p class="text-xs text-muted">Earlier assignments stay on record with their dates.</p>
                    </form>
                @endif

                <div class="mt-4 border-t border-line pt-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-ink">Recent history</h3>
                        <a href="{{ route('service-history.index', ['account' => $selected->id]) }}" wire:navigate class="text-xs underline">Full history</a>
                    </div>
                    <ol class="space-y-2 text-sm">
                        @foreach ($events as $event)
                            <li wire:key="recent-{{ $event->id }}">
                                <span class="font-medium text-ink">{{ $event->event_type->label() }}</span>
                                <span class="text-xs text-muted">· {{ $event->occurred_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $event->actor->full_name }}</span>
                                @if ($event->notes) <span class="block text-muted">{{ $event->notes }}</span> @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>
        @elseif (! $creating)
            <section class="rounded-md border border-dashed border-line bg-surface p-4 text-sm text-muted">Open a service account to see its details, change its status or assign a collector.</section>
        @endif
    </div>
</div>
