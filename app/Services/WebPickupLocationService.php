<?php

namespace App\Services;

use App\Models\Business;
use App\Models\WebPickupLocation;
use Illuminate\Database\Eloquent\Builder;

class WebPickupLocationService
{
    /** @return Builder<WebPickupLocation> */
    public function publicQuery(Business $business): Builder
    {
        return WebPickupLocation::query()->where('business_id', $business->id)->where('is_active', true)
            ->whereHas('location', fn (Builder $query) => $query->where('business_id', $business->id)
                ->where('type', 'shop')->where('status', 'active'));
    }

    public function resolve(Business $business, string $code, bool $lock = false): ?WebPickupLocation
    {
        $query = $this->publicQuery($business)->where('public_code', $code)->with('location');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @return array{code: string, name: string, address: string, city: string} */
    public function publicSnapshot(WebPickupLocation $pickup): array
    {
        return ['code' => $pickup->public_code, 'name' => $pickup->publicName(),
            'address' => $pickup->location->address, 'city' => $pickup->location->city];
    }
}
