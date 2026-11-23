<div>
    <x-erp.page-header title="Staff and access" subtitle="Roles are templates; effective access is the role's permissions plus the unit assignments below. Everything is enforced server-side.">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="openModal" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">New staff account</button>
            @endif
        </x-slot:actions>
    </x-erp.page-header>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Staff</th>
                        <th class="px-4 py-2.5 font-semibold">Role</th>
                        <th class="px-4 py-2.5 font-semibold">Units</th>
                        <th class="px-4 py-2.5 font-semibold">Designation</th>
                        <th class="px-4 py-2.5 font-semibold">Last login</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($users as $user)
                        <tr wire:key="staff-{{ $user->id }}" class="hover:bg-slate-50/70">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-800">{{ $user->name }}</p>
                                <p class="text-xs text-slate-500">{{ $user->email }} · {{ $user->staffProfile?->code ?? 'No staff code' }}</p>
                            </td>
                            <td class="px-4 py-2.5"><x-erp.badge tone="indigo">{{ $user->getRoleNames()->first() ?? 'No role' }}</x-erp.badge></td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($user->businessUnits as $unit)
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-700">{{ $unit->name }}</span>
                                    @empty
                                        <span class="text-xs text-rose-600">No unit access</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $user->staffProfile?->designation ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-500">{{ $user->last_login_at?->timezone(config('erp.timezone'))->format('d M Y, H:i') ?? 'Never' }}</td>
                            <td class="px-4 py-2.5"><x-erp.badge :tone="$user->is_active ? 'green' : 'red'">{{ $user->is_active ? 'Active' : 'Suspended' }}</x-erp.badge></td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($canManage)
                                    <button type="button" wire:click="openModal({{ $user->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Edit</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </div>

    @if ($modalOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16">
            <form wire:submit="save" class="w-full max-w-xl rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">{{ $editingUserId ? 'Edit staff account' : 'New staff account' }}</h2>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Name</label>
                        <input type="text" wire:model="name" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Email (login)</label>
                        <input type="email" wire:model="email" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Phone</label>
                        <input type="text" wire:model="phone" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Designation</label>
                        <input type="text" wire:model="designation" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Role</label>
                        <select wire:model="role" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($roles as $roleOption)
                                <option value="{{ $roleOption }}">{{ ucfirst($roleOption) }}</option>
                            @endforeach
                        </select>
                        @error('role') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $editingUserId ? 'New password (optional)' : 'Password' }}</label>
                        <input type="password" wire:model="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Unit access</p>
                        <div class="mt-1 flex flex-wrap gap-3">
                            @foreach ($units as $unit)
                                <label class="flex items-center gap-1.5 text-sm text-slate-700">
                                    <input type="checkbox" value="{{ $unit->id }}" wire:model="unitIds" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    {{ $unit->name }}
                                </label>
                            @endforeach
                        </div>
                        @error('unitIds') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" wire:model="active" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"> Account active
                    </label>
                    @error('active') <p class="text-xs text-rose-600 sm:col-span-2">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('modalOpen', false)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save account</button>
                </div>
            </form>
        </div>
    @endif
</div>
