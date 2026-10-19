<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreUserRequest;
use App\Http\Requests\Management\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\LocationAccessService;
use App\Services\UserAdministrationService;
use App\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UserController extends Controller
{
    public function __construct(private readonly LocationAccessService $locations, private readonly UserAdministrationService $users) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ]);

        $users = $this->locations->manageableUsersQuery($request->user())
            ->with(['roles.permissions', 'permissions', 'ownedBusiness', 'locations:id,name,code,status'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['role'] ?? null, fn ($query, int|string $roleId) => $query->whereHas('roles', fn ($query) => $query->whereKey($roleId)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user): array => [
                ...$user->only(['id', 'name', 'email', 'phone', 'status']),
                'roles' => $user->roles->map(fn ($role): array => $role->only(['id', 'name'])),
                'locations' => $user->locations->map(fn ($location): array => $location->only(['id', 'name', 'code', 'status'])),
                'can_manage' => $this->users->canManagePrivileges($request->user(), $user),
            ]);

        return Inertia::render('management/users/Index', [
            'users' => $users,
            ...$this->formOptions($request->user()),
            'statuses' => $this->statuses(),
            'filters' => $filters,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('management/users/Create', [
            ...$this->formOptions($request->user()),
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request, UserAdministrationService $service): RedirectResponse
    {
        $attributes = $request->validated();
        $sendInvite = (bool) ($attributes['send_invite'] ?? false);
        unset($attributes['send_invite']);

        if ($sendInvite) {
            $attributes['password'] = Str::random(64);
        }

        $user = $service->create($request->user(), $attributes);
        $message = __('User created.');
        $status = null;

        if ($sendInvite) {
            try {
                $status = Password::sendResetLink(['email' => $user->email]);
            } catch (Throwable $exception) {
                report($exception);
            }

            $message = $status === Password::RESET_LINK_SENT
                ? __('User created and a password setup link was sent by email.')
                : __('User created, but the password setup link could not be sent. Use the password reset flow to resend it.');
        }

        Inertia::flash('toast', [
            'type' => $sendInvite && $status !== Password::RESET_LINK_SENT ? 'error' : 'success',
            'message' => $message,
        ]);

        return to_route('users.index');
    }

    /**
     * Display the specified resource.
     */
    public function edit(Request $request, User $user): Response
    {
        $this->users->ensureUserMayBeManaged($request->user(), $user);
        $user->load(['roles:id,name', 'locations:id,name,code,status']);

        return Inertia::render('management/users/Edit', [
            'managedUser' => $user,
            ...$this->formOptions($request->user()),
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user, UserAdministrationService $service): RedirectResponse
    {
        $service->update($request->user(), $user, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('users.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user, UserAdministrationService $service): RedirectResponse
    {
        $service->delete($request->user(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('users.index');
    }

    /** @return list<array{value: string, label: string}> */
    private function statuses(): array
    {
        return array_map(
            fn (UserStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            UserStatus::cases(),
        );
    }

    /** @return array<string, mixed> */
    private function formOptions(User $actor): array
    {
        $isOwner = $this->locations->hasAllLocationAccess($actor, $this->locations->business());

        return [
            'roles' => Role::query()->where('guard_name', 'web')->with('permissions:id,name')->orderBy('name')->get()
                ->filter(fn (Role $role): bool => $isOwner || ($role->name !== 'Owner' && $role->permissions->every(fn ($permission): bool => $actor->can($permission->name))))
                ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->values(),
            'locations' => $actor->can('locations.assign-users')
                ? $this->locations->accessibleQuery($actor)->orderBy('name')->get(['id', 'name', 'code', 'status'])
                : [],
        ];
    }
}
