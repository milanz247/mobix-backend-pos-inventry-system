<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\StorefrontCartException;
use App\Http\Controllers\Controller;
use App\Models\WebBankTransferAttempt;
use App\Models\WebOrder;
use App\PermissionName;
use App\Services\LocationAccessService;
use App\Services\WebBankTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffBankTransferController extends Controller
{
    public function download(Request $request, string $reference, int $attempt, LocationAccessService $locations): StreamedResponse
    {
        abort_unless($request->user()->can(PermissionName::StorefrontViewPaymentProofs->value), 403);
        $business = $locations->business();
        $order = WebOrder::query()->where('business_id', $business->id)->where('reference', $reference)->firstOrFail();
        $locations->accessibleQuery($request->user())->findOrFail($order->location_id);
        $proof = WebBankTransferAttempt::query()->where('business_id', $business->id)
            ->where('web_order_id', $order->id)->findOrFail($attempt);
        $prefix = 'web-bank-transfer-proofs/'.$business->id.'/'.$order->id.'/';
        abort_unless(str_starts_with($proof->evidence_path, $prefix)
            && preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D', substr($proof->evidence_path, strlen($prefix))), 404);
        abort_unless(Storage::disk('local')->exists($proof->evidence_path), 404);
        $extension = pathinfo($proof->evidence_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($proof->evidence_path, 'bank-transfer-proof.'.$extension, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, string $reference, int $attempt, WebBankTransferService $payments): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'underpaid', 'rejected'])],
            'received_amount' => ['required', 'decimal:0,2', 'gte:0', 'max:99999999999999.99'],
            'internal_note' => ['nullable', 'string', 'max:1000', 'required_if:decision,underpaid,rejected'],
        ]);
        try {
            $result = $payments->review($request->user(), $reference, $attempt,
                $data['decision'], (string) $data['received_amount'], $data['internal_note'] ?? null);
        } catch (StorefrontCartException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        return to_route('storefront-orders.show', $reference)->with('success',
            $result['decision'] === 'overpayment_review'
                ? 'Overpayment recorded for manual review. Payment remains unverified.'
                : 'Bank transfer decision recorded.');
    }
}
