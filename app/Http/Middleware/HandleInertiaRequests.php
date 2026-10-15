<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\PermissionName;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'roles' => fn (): array => $user?->getRoleNames()->values()->all() ?? [],
                'permissions' => fn (): array => $this->permissionsFor($user),
            ],
            'businessPreferences' => $request->attributes->get('businessPreferences'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** @return list<string> */
    private function permissionsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        if ($user->ownedBusiness()->where('setup_key', 'initial')->exists()) {
            return array_map(
                fn (PermissionName $permission): string => $permission->value,
                PermissionName::cases(),
            );
        }

        $permissions = [];

        foreach ($user->getAllPermissions() as $permission) {
            $permissions[] = $permission->name;
        }

        return $permissions;
    }
}
