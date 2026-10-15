<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\PermissionName;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('management/permissions/Index', [
            'permissionGroups' => collect(PermissionName::cases())->groupBy(fn (PermissionName $permission): string => $permission->module())->map(fn ($permissions) => $permissions->map(fn (PermissionName $permission): array => [
                'name' => $permission->value,
                'label' => $permission->label(),
            ])->values()),
        ]);
    }
}
