<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\StoreUserRequest;
use App\Http\Requests\Management\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAdministrationService;
use App\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
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

        $users = User::query()
            ->with('roles:id,name')
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
            ->withQueryString();

        return Inertia::render('management/users/Index', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => $this->statuses(),
            'filters' => $filters,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('management/users/Create', [
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => $this->statuses(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request, UserAdministrationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('users.index');
    }

    /**
     * Display the specified resource.
     */
    public function edit(User $user): Response
    {
        $user->load('roles:id,name');

        return Inertia::render('management/users/Edit', [
            'managedUser' => $user,
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
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
}
