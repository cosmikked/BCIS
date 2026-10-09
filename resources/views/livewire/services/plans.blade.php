<div class="grid gap-6 {{ $canManage ? 'xl:grid-cols-[minmax(0,1fr)_22rem]' : '' }}">
    <div class="space-y-4">
        @if ($status !== '')
            <p role="status" class="alert alert-success">{{ $status }}</p>
        @endif

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <label for="typeFilter" class="label">Service type</label>
                <select id="typeFilter" wire:model.live="typeFilter" class="field field-sm">
                    <option value="">All types</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canManage)
                <button type="button" wire:click="startCreate" class="btn btn-primary btn-sm">New plan</button>
            @endif
        </div>

        <section class="overflow-x-auto rounded-md border border-line bg-surface">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-line bg-canvas text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2">Code</th>
                        <th scope="col" class="px-4 py-2">Name</th>
                        <th scope="col" class="px-4 py-2">Type</th>
                        <th scope="col" class="px-4 py-2">Speed / channels</th>
                        <th scope="col" class="px-4 py-2 text-right">Monthly price</th>
                        <th scope="col" class="px-4 py-2 text-right">Installation</th>
                        <th scope="col" class="px-4 py-2">Status</th>
                        @if ($canManage)
                            <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($plans as $plan)
                        <tr wire:key="plan-{{ $plan->id }}" @class(['bg-selected' => $selected?->id === $plan->id])>
                            <td class="px-4 py-2 font-medium text-ink">{{ $plan->code }}</td>
                            <td class="px-4 py-2">{{ $plan->name }}</td>
                            <td class="px-4 py-2 text-muted">{{ $plan->serviceType->name }}</td>
                            <td class="px-4 py-2 text-muted">
                                {{ collect([$plan->speed_mbps ? $plan->speed_mbps.' Mbps' : null, $plan->channel_count ? $plan->channel_count.' channels' : null])->filter()->implode(' + ') ?: '-' }}
                            </td>
                            <td class="money px-4 py-2 text-right">{{ $plan->price_centavos->format() }}</td>
                            <td class="money px-4 py-2 text-right">{{ $plan->installation_fee_centavos->format() }}</td>
                            <td class="px-4 py-2">
                                @if ($plan->is_active)
                                    <span class="badge badge-success">Active</span>
                                @else
                                    <span class="badge badge-neutral">Inactive</span>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="px-4 py-2 text-right">
                                    <button type="button" wire:click="select({{ $plan->id }})" aria-label="Edit {{ $plan->code }}" class="btn btn-secondary btn-sm">Edit</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-muted">No plans match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <p class="text-xs text-muted">A price change applies to new service accounts only. Accounts keep the rate they were created with.</p>
    </div>

    @if ($canManage)
        <section class="h-fit rounded-md border border-line bg-surface p-4" aria-labelledby="plan-form-title">
            @if ($creating || $selected)
                <h2 id="plan-form-title" class="mb-3 text-base font-semibold text-ink">{{ $creating ? 'New plan' : 'Edit '.$selected->code }}</h2>

                @error('form') <p role="alert" class="alert alert-danger mb-3">{{ $message }}</p> @enderror

                <form wire:submit="save" class="space-y-3" novalidate>
                    <div>
                        <label for="code" class="label">Code @if ($creating)<span class="text-danger" aria-hidden="true">*</span>@endif</label>
                        <input id="code" type="text" wire:model.blur="code" maxlength="30" @disabled(! $creating) class="field">
                        @error('code') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="name" class="label">Name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input id="name" type="text" wire:model.blur="name" maxlength="100" class="field">
                        @error('name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="serviceTypeId" class="label">Service type <span class="text-danger" aria-hidden="true">*</span></label>
                        <select id="serviceTypeId" wire:model.blur="serviceTypeId" @disabled(! $creating) class="field">
                            <option value="">Choose...</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @error('service_type_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="price" class="label">Monthly price (₱) <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="price" type="text" inputmode="decimal" wire:model.blur="price" placeholder="999.00" class="field money">
                            @error('price') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="installationFee" class="label">Installation (₱)</label>
                            <input id="installationFee" type="text" inputmode="decimal" wire:model.blur="installationFee" class="field money">
                            @error('installation_fee') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="speedMbps" class="label">Speed (Mbps)</label>
                            <input id="speedMbps" type="number" min="1" wire:model.blur="speedMbps" class="field">
                            @error('speed_mbps') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="channelCount" class="label">Channels</label>
                            <input id="channelCount" type="number" min="1" wire:model.blur="channelCount" class="field">
                            @error('channel_count') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-xs text-muted">Internet needs a speed, Cable needs channels, Combo needs both.</p>
                    <div>
                        <label for="description" class="label">Description</label>
                        <textarea id="description" wire:model.blur="description" rows="2" maxlength="1000" class="field"></textarea>
                        @error('description') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    @unless ($creating)
                        <div>
                            <label for="reason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="reason" type="text" wire:model="reason" maxlength="500" placeholder="Required; kept in the audit log" class="field">
                            @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                    @endunless

                    <div class="flex flex-wrap gap-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary btn-sm">{{ $creating ? 'Create plan' : 'Save changes' }}</button>
                        @unless ($creating)
                            <button type="button" wire:click="toggleActive" wire:loading.attr="disabled"
                                    wire:confirm="{{ $selected->is_active ? 'Deactivate this plan? It can no longer be chosen for new service accounts.' : 'Make this plan available again?' }}"
                                    class="btn {{ $selected->is_active ? 'btn-danger' : 'btn-secondary' }} btn-sm">{{ $selected->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                        @endunless
                    </div>
                </form>
            @else
                <h2 id="plan-form-title" class="mb-1 text-base font-semibold text-ink">Plan details</h2>
                <p class="text-sm text-muted">Choose Edit on a plan, or create a new one.</p>
            @endif
        </section>
    @endif
</div>
