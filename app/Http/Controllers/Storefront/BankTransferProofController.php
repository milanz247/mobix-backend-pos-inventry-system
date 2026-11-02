<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\StorefrontOrderTrackingService;
use App\Services\WebBankTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BankTransferProofController extends Controller
{
    public function store(Request $request, string $reference, StorefrontOrderTrackingService $tracking, WebBankTransferService $payments): JsonResponse
    {
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $credential = $request->header('X-Storefront-Order-Credential');
        $tracking->authenticateOrder($business, $reference, $credential);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            return response()->json(['error' => ['code' => 'INVALID_IDEMPOTENCY_KEY',
                'message' => 'A valid upload request key is required.', 'fields' => new \stdClass],
                'request_id' => (string) Str::uuid()], 422)->header('Cache-Control', 'private, no-store');
        }
        $maxKb = (int) config('storefront.proof_max_kb', 5120);
        $data = $request->validate([
            'transfer_reference' => ['required', 'string', 'min:2', 'max:180'],
            'amount_transferred' => ['nullable', 'decimal:0,2', 'gt:0', 'max:99999999999999.99'],
            'transfer_date' => ['nullable', 'date_format:Y-m-d'],
            'proof' => ['required', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:'.max(1, min($maxKb, 10240))],
        ]);
        $attempt = $payments->submit($business, $reference, $credential, $key, $data['proof'], $data);

        return response()->json(['data' => [
            'status' => $attempt['status'], 'submitted_at' => $attempt['submitted_at'],
            'payment_status' => $attempt['payment_status'],
        ]], $attempt['idempotent'] === 'true' ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }
}
