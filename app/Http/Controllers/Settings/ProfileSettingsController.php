<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\User;
use App\Services\LocationAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ProfileSettingsController extends Controller
{
    public function edit(Request $request, LocationAccessService $locationAccess): Response
    {
        $user = $request->user();
        $user->load('roles:id,name');

        $locations = $locationAccess->accessibleQuery($user)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status'])
            ->map(fn ($location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code,
                'status' => $location->status->value,
            ]);

        return Inertia::render('settings/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $this->avatarUrl($user),
            ],
            'roles' => $user->roles->pluck('name')->values(),
            'locations' => $locations,
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->safe()->only(['name', 'phone']);
        $previousPhotoPath = $user->profile_photo_path;
        $newPhotoPath = null;

        if ($request->hasFile('profile_photo')) {
            $newPhotoPath = $request->file('profile_photo')->store("profile-photos/{$user->id}");

            if ($newPhotoPath === false) {
                throw new RuntimeException(__('The profile photo could not be stored.'));
            }

            $validated['profile_photo_path'] = $newPhotoPath;
        }

        try {
            $user->update($validated);
        } catch (Throwable $exception) {
            if ($newPhotoPath !== null) {
                Storage::delete($newPhotoPath);
            }

            throw $exception;
        }

        if ($newPhotoPath !== null && $previousPhotoPath !== null) {
            Storage::delete($previousPhotoPath);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return back();
    }

    private function avatarUrl(User $user): ?string
    {
        if ($user->profile_photo_path === null) {
            return null;
        }

        return route('profile-photo.show', ['v' => $user->updated_at?->getTimestamp()]);
    }
}
