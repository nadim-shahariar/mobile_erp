<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Models\BusinessUnit;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class StaffIndex extends Component
{
    use WithPagination;

    public bool $modalOpen = false;

    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $role = 'staff';

    public string $designation = '';

    public ?string $password = null;

    public bool $active = true;

    /** @var list<int> */
    public array $unitIds = [];

    public function mount(): void
    {
        $this->authorize('staff.view');
    }

    public function openModal(?int $userId = null): void
    {
        $this->authorize('staff.manage');
        $this->resetErrorBag();
        $this->editingUserId = $userId;

        if ($userId !== null) {
            $user = User::query()->with(['businessUnits', 'staffProfile', 'roles'])->findOrFail($userId);
            $this->name = $user->name;
            $this->email = $user->email;
            $this->phone = (string) $user->phone;
            $this->role = $user->getRoleNames()->first() ?? 'staff';
            $this->designation = (string) $user->staffProfile?->designation;
            $this->active = $user->is_active;
            $this->unitIds = $user->businessUnits->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->password = null;
        } else {
            $this->name = '';
            $this->email = '';
            $this->phone = '';
            $this->role = 'staff';
            $this->designation = '';
            $this->active = true;
            $this->unitIds = [];
            $this->password = null;
        }

        $this->modalOpen = true;
    }

    public function save(): void
    {
        $this->authorize('staff.manage');

        $actor = auth()->user();
        $this->email = mb_strtolower(trim($this->email));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in(array_keys(Permissions::roleMatrix()))],
            'designation' => ['nullable', 'string', 'max:120'],
            'password' => [$this->editingUserId ? 'nullable' : 'required', 'string', 'min:8', 'max:190'],
            'active' => ['boolean'],
            'unitIds' => ['array'],
            'unitIds.*' => ['integer', 'exists:business_units,id'],
        ]);

        if ($this->editingUserId !== null && $this->editingUserId === $actor->getKey()) {
            if ($validated['role'] !== ($actor->getRoleNames()->first() ?? 'owner')) {
                $this->addError('role', 'You cannot change your own role. Ask another owner.');

                return;
            }

            if (! $validated['active']) {
                $this->addError('active', 'You cannot deactivate your own account.');

                return;
            }
        }

        if ($validated['role'] === 'owner' && ! $actor->hasRole('owner')) {
            $this->addError('role', 'Only an owner can grant the owner role.');

            return;
        }

        $target = $this->editingUserId !== null ? User::query()->findOrFail($this->editingUserId) : new User;

        $old = $target->exists ? $target->only(['name', 'email', 'phone', 'is_active']) : [];

        $target->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'is_active' => $validated['active'],
        ]);

        if (! empty($validated['password'])) {
            $target->password = $validated['password'];
        }

        if (! $target->exists) {
            $target->locale = 'en';
        }

        $target->save();

        $target->syncRoles([$validated['role']]);
        $target->businessUnits()->sync($validated['unitIds']);

        StaffProfile::query()->updateOrCreate(
            ['user_id' => $target->getKey()],
            [
                'code' => $target->staffProfile?->code ?? 'EMP-'.str_pad((string) (StaffProfile::query()->max('id') + 1), 4, '0', STR_PAD_LEFT),
                'designation' => $validated['designation'] ?: null,
                'joined_at' => $target->staffProfile?->joined_at ?? now()->toDateString(),
            ],
        );

        AuditLogger::record(
            $this->editingUserId ? 'staff.updated' : 'staff.created',
            $target,
            $old,
            array_merge($target->only(['name', 'email', 'phone', 'is_active']), [
                'role' => $validated['role'],
                'units' => $validated['unitIds'],
            ]),
            null,
            null,
            $actor,
        );

        $this->modalOpen = false;
        session()->flash('success', "Staff account {$target->name} saved.");
    }

    public function render(): View
    {
        return view('livewire.staff.index', [
            'users' => User::query()
                ->with(['roles', 'businessUnits', 'staffProfile'])
                ->orderBy('name')
                ->paginate(25),
            'units' => BusinessUnit::query()->ordered()->get(),
            'canManage' => auth()->user()->can('staff.manage'),
            'roles' => array_keys(Permissions::roleMatrix()),
        ]);
    }
}
