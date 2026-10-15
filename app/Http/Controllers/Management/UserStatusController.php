<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\UserAdministrationService;
use App\UserStatus;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UserStatusController extends Controller
{
    public function update(UpdateUserStatusRequest $request, User $user, UserAdministrationService $service): RedirectResponse
    {
        $service->updateStatus($request->user(), $user, UserStatus::from($request->validated('status')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account status updated.')]);

        return back();
    }
}
