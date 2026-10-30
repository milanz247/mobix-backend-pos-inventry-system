<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Support\PermissionCatalog;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('management/permissions/Index', [
            'permissionGroups' => app(PermissionCatalog::class)->groups(),
        ]);
    }
}
