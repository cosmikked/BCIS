<div class="space-y-6">
    @if ($status !== '')
        <p role="status" class="rounded-md border border-success bg-emerald-50 px-3 py-2 text-sm text-success">{{ $status }}</p>
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
                                <button type="button" wire:click="select({{ $role->id }})" class="mt-1 block w-full text-accent underline">Edit</button>
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
                                    <span aria-label="Granted" class="font-semibold text-success">✔</span>
                                @else
                                    <span aria-label="Not granted" class="text-muted/50">–</span>
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

            @error('form') <p role="alert" class="mb-3 rounded-md border border-danger bg-red-50 px-3 py-2 text-sm text-danger">{{ $message }}</p> @enderror
            @error('codes') <p role="alert" class="mb-3 text-sm text-danger">{{ $message }}</p> @enderror

            <div class="grid gap-x-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($permissions as $permission)
                    @php($canGrant = in_array($permission->code, $held, true) || $selected->permissions->contains('id', $permission->id))
                    <label class="flex items-center gap-2 py-0.5 text-sm {{ $canGrant ? '' : 'text-muted' }}">
                        <input type="checkbox" wire:model="codes" value="{{ $permission->code }}" class="rounded border-line" @disabled(! $canGrant)>
                        <span class="font-mono text-xs">{{ $permission->code }}</span>
                    </label>
                @endforeach
            </div>

            <div class="mt-4 max-w-xl">
                <label for="role-reason" class="mb-1 block text-sm font-medium text-ink">Reason <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="role-reason" type="text" wire:model="reason" maxlength="500" placeholder="Kept in the audit log"
                       class="block w-full rounded-md border border-line px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30">
                @error('reason') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <button type="button" wire:click="save" wire:loading.attr="disabled" class="mt-4 rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">Save permissions</button>
        </section>
    @endif
</div>
