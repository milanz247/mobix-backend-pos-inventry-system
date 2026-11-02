<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\WebOrderPreparationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffWebOrderPreparationController extends Controller
{
    public function start(Request $request, string $reference, WebOrderPreparationService $service): RedirectResponse
    {
        $service->start($request->user(), $reference);

        return to_route('storefront-orders.show', $reference)->with('success', 'Preparation started. Physical stock remains reserved.');
    }

    public function check(Request $request, string $reference, int $reservation, WebOrderPreparationService $service): RedirectResponse
    {
        $data = $request->validate([
            'checked_quantity' => ['required', 'numeric', 'gte:0', 'decimal:0,3'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
            'observed_condition' => ['nullable', 'string', 'max:24'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $result = $service->check($request->user(), $reference, $reservation, (string) $data['checked_quantity'],
            $data['device_identifier'] ?? null, $data['observed_condition'] ?? null, $data['internal_note'] ?? null);

        return to_route('storefront-orders.show', $reference)->with('success',
            $result['result'] === 'matched' ? 'Pick check recorded.' : 'Picking discrepancy recorded for manual review.');
    }

    public function ready(Request $request, string $reference, WebOrderPreparationService $service): RedirectResponse
    {
        $service->markReady($request->user(), $reference);

        return to_route('storefront-orders.show', $reference)->with('success', 'Order marked ready. Inventory is still reserved, not deducted.');
    }
}
