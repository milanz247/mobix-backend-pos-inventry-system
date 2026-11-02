<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\WebOrderFulfillmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffWebOrderHandoverController extends Controller
{
    public function pickup(Request $request, string $reference, WebOrderFulfillmentService $handover): RedirectResponse
    {
        $input = $request->validate([
            'operation_key' => ['required', 'uuid'],
            'cash_tendered' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);
        $result = $handover->collectPickup($request->user(), $reference,
            $input['operation_key'], (string) $input['cash_tendered']);

        return to_route('storefront-orders.show', $reference)->with('success',
            $result['replayed'] ? 'Pickup handover already recorded.' : 'Cash collected and pickup handover recorded.');
    }
}
