<div class="space-y-6">
    @if ($status !== '')
        <p role="status" class="alert alert-success">{{ $status }}</p>
    @endif

    <section class="overflow-x-auto rounded-md border border-line bg-surface" aria-labelledby="matrix-title">
        <h2 id="matrix-title" class="px-4 pt-4 text-base font-semibold text-ink">Permission matrix</h2>
        <p class="px-4 pb-3 text-xs text-muted">A tick means the role holds the permission. The server checks the permission itself on every action.</p>
        <table class="w-full text-left text-sm">
            <thead class="border-y border-line bg-canvas text-xs text-muted">
                <tr>
                    <th scope="col" class="sticky left-0 bg-canvas px-4 py-2 font-medium">Permission</th>
                    @foreach ($roles as $role)
                        <th scope="col" class="px-3 py-2 text-center font-medium">
                            {{ $role->name }}
                            @if ($role->code !== $ownerCode)
                                <button type="button" wire:click="select({{ $role->id }})" aria-label="Edit {{ $role->name }}" class="btn btn-secondary btn-sm mt-1 w-full">Edit</button>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($permissions as $permission)
                    <tr>
                        <th scope="row" class="sticky left-0 bg-surface px-4 py-1.5 font-mono text-xs font-normal text-ink" title="{{ $permission->description }}">{{ $permission->code }}</th>
                        @foreach ($roles as $role)
                            <td class="px-3 py-1.5 text-center">
                                @if ($role->permissions->contains('id', $permission->id))
                                    <svg class="mx-auto h-4 w-4 text-success-ink" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3.2 3L13 4.5"/></svg>
                                    <span class="sr-only">Granted</span>
                                @else
                                    <span class="mx-auto block h-px w-3 bg-control" aria-hidden="true"></span>
                                    <span class="sr-only">Not granted</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($selected)
        <section class="rounded-md border border-line bg-surface p-4" aria-labelledby="edit-title">
            <h2 id="edit-title" class="mb-1 text-base font-semibold text-ink">Edit {{ $selected->name }}</h2>
            <p class="mb-3 text-xs text-muted">You can only grant permissions you hold yourself. Reseeding restores the original matrix.</p>

            @error('form') <p role="alert" class="alert alert-danger mb-3">{{ $message }}</p> @enderror
            @error('codes') <p role="alert" class="mb-3 text-sm text-danger">{{ $message }}</p> @enderror

            <div class="grid gap-x-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($permissions as $permission)
                    @php($canGrant = in_array($permission->code, $held, true) || $selected->permissions->contains('id', $permission->id))
                    <label class="flex items-center gap-2 py-0.5 text-sm {{ $canGrant ? '' : 'text-muted' }}">
                        <input type="checkbox" wire:model="codes" value="{{ $permission->code }}" @disabled(! $canGrant)>
                        <span class="font-mono text-xs">{{ $permission->code }}</span>
                    </label>
                @endforeach
            </div>

            <div class="mt-4 max-w-xl">
                <label for="role-reason" class="label">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="role-reason" type="text" wire:model="reason" maxlength="500" placeholder="Kept in the audit log" class="field">
                @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <button type="button" wire:click="save" wire:loading.attr="disabled" class="btn btn-primary mt-4">Save permissions</button>
        </section>
    @endif
</div>
