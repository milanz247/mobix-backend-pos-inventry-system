<?php

namespace App\Services;

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Models\WebBankTransferAttempt;
use App\Models\WebBankTransferDecision;
use App\Models\WebOrder;
use App\Models\WebOrderPayment;
use App\PermissionName;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WebBankTransferService
{
    public function __construct(
        private readonly StorefrontOrderTrackingService $tracking,
        private readonly LocationAccessService $locations,
        private readonly WebOrderStaffService $staffOrders,
    ) {}

    /** @param array{transfer_reference: string, amount_transferred?: string, transfer_date?: string} $input
     * @return array<string, string|null>
     */
    public function submit(Business $business, string $reference, string $credential, string $key, UploadedFile $file, array $input): array
    {
        $fileHash = hash_file('sha256', $file->getRealPath());
        if ($fileHash === false) {
            throw new StorefrontCartException('INVALID_PROOF', 'The proof file could not be read.', 422);
        }
        $payloadHash = hash('sha256', json_encode([
            trim($input['transfer_reference']), $input['amount_transferred'] ?? null,
            $input['transfer_date'] ?? null, $fileHash,
        ], JSON_THROW_ON_ERROR));
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf',
            default => throw new StorefrontCartException('INVALID_PROOF', 'The proof file type is not supported.', 422),
        };
        $order = $this->tracking->authenticateOrder($business, $reference, $credential);
        if ($order->payment_method !== 'bank_transfer' || ! in_array($order->status, ['pending_confirmation', 'confirmed'], true)
            || $order->reservation_expires_at === null || $order->reservation_expires_at->isPast()) {
            throw new StorefrontCartException('PAYMENT_UNAVAILABLE', 'Bank transfer proof is unavailable for this order.', 409);
        }
        $path = 'web-bank-transfer-proofs/'.$business->id.'/'.$order->id.'/'.bin2hex(random_bytes(24)).'.'.$extension;
        try {
            $stored = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        } catch (\Throwable) {
            $stored = false;
        }
        if ($stored === false) {
            Storage::disk('local')->delete($path);
            throw new StorefrontCartException('PROOF_STORAGE_UNAVAILABLE', 'The proof could not be stored. Please retry.', 503);
        }
        $retained = false;
        try {
            $result = DB::transaction(function () use ($business, $reference, $credential, $key, $input, $payloadHash, $path, $file): array {
                Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
                $locked = $this->tracking->authenticateOrder($business, $reference, $credential, true);
                $payment = WebOrderPayment::query()->where('business_id', $business->id)
                    ->where('web_order_id', $locked->id)->lockForUpdate()->first();
                if ($payment === null || $payment->method !== 'bank_transfer') {
                    throw new StorefrontCartException('PAYMENT_UNAVAILABLE', 'Bank transfer proof is unavailable for this order.', 409);
                }
                $existing = WebBankTransferAttempt::query()->where('web_order_payment_id', $payment->id)
                    ->where('submission_key', $key)->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_hash, $payloadHash)) {
                        throw new StorefrontCartException('PROOF_IDEMPOTENCY_CONFLICT', 'This upload key was used for different proof details.', 409);
                    }

                    return $this->publicAttempt($existing, $payment->status);
                }
                if (WebBankTransferAttempt::query()->where('web_order_payment_id', $payment->id)
                    ->where('payload_hash', $payloadHash)->exists()) {
                    throw new StorefrontCartException('DUPLICATE_PROOF', 'This proof was already submitted for the order.', 409);
                }
                $this->assertEligible($locked, $payment, ['transfer_awaiting_proof', 'underpaid', 'rejected']);
                $attempt = WebBankTransferAttempt::query()->create([
                    'business_id' => $business->id, 'web_order_id' => $locked->id,
                    'web_order_payment_id' => $payment->id, 'submission_key' => $key,
                    'payload_hash' => $payloadHash, 'transfer_reference' => trim($input['transfer_reference']),
                    'submitted_amount' => $input['amount_transferred'] ?? null,
                    'transfer_date' => $input['transfer_date'] ?? null, 'evidence_path' => $path,
                    'evidence_mime' => $file->getMimeType(), 'evidence_size' => $file->getSize(),
                    'status' => 'awaiting_verification', 'submitted_at' => now(),
                ]);
                $payment->update(['status' => 'transfer_awaiting_verification']);
                $locked->update(['payment_status' => 'transfer_awaiting_verification']);

                return $this->publicAttempt($attempt, 'transfer_awaiting_verification');
            }, 3);
            $retained = $result['idempotent'] === 'false';

            return $result;
        } finally {
            if (! $retained) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /** @return array<string, string|null> */
    private function publicAttempt(WebBankTransferAttempt $attempt, string $paymentStatus): array
    {
        return ['status' => $attempt->status, 'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'payment_status' => $paymentStatus, 'idempotent' => $attempt->wasRecentlyCreated ? 'false' : 'true'];
    }

    /** @return array{decision: string, payment_status: string} */
    public function review(User $actor, string $reference, int $attemptId, string $decision, string $receivedAmount, ?string $note): array
    {
        abort_unless($actor->can(PermissionName::StorefrontVerifyPayments->value)
            && $actor->can(PermissionName::StorefrontViewPaymentProofs->value), 403);

        return DB::transaction(function () use ($actor, $reference, $attemptId, $decision, $receivedAmount, $note): array {
            $business = $this->locations->business();
            Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $order = WebOrder::query()->where('business_id', $business->id)->where('reference', $reference)
                ->lockForUpdate()->firstOrFail();
            $location = Location::query()->where('business_id', $business->id)->findOrFail($order->location_id);
            abort_unless($this->locations->canAccess($actor, $location), 404);
            $payment = WebOrderPayment::query()->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->lockForUpdate()->firstOrFail();
            $attempt = WebBankTransferAttempt::query()->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->where('web_order_payment_id', $payment->id)
                ->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            $this->assertEligible($order, $payment, ['transfer_awaiting_verification']);
            if ($attempt->status !== 'awaiting_verification' || WebBankTransferDecision::query()
                ->where('web_bank_transfer_attempt_id', $attempt->id)->exists()) {
                throw ValidationException::withMessages(['attempt' => 'This payment attempt has already been reviewed.']);
            }
            $amount = BigDecimal::of($receivedAmount)->toScale(2);
            $received = BigDecimal::zero();
            foreach (WebBankTransferDecision::query()->where('business_id', $business->id)
                ->where('web_order_id', $order->id)->whereIn('decision', ['underpaid', 'verified'])
                ->lockForUpdate()->pluck('received_amount') as $previous) {
                $received = $received->plus((string) $previous);
            }
            $remaining = BigDecimal::of((string) $payment->amount_due)->minus($received);
            if (! $remaining->isGreaterThan('0')) {
                throw ValidationException::withMessages(['attempt' => 'The amount due has already been fully verified.']);
            }
            if ($decision === 'rejected') {
                if (! $amount->isEqualTo('0') || trim((string) $note) === '') {
                    throw ValidationException::withMessages(['received_amount' => 'Reject with zero received amount and a reason.']);
                }
                $result = 'rejected';
            } elseif ($amount->isGreaterThan($remaining)) {
                $result = 'overpayment_review';
            } elseif ($decision === 'underpaid' && $amount->isGreaterThan('0') && $amount->isLessThan($remaining) && trim((string) $note) !== '') {
                $result = 'underpaid';
            } elseif ($decision === 'verified' && $amount->isEqualTo($remaining)) {
                $result = 'verified';
            } else {
                throw ValidationException::withMessages(['received_amount' => 'The decision and actual received amount do not match the remaining amount due.']);
            }
            WebBankTransferDecision::query()->create([
                'business_id' => $business->id, 'web_order_id' => $order->id,
                'web_bank_transfer_attempt_id' => $attempt->id,
                'actor_user_id' => $actor->id, 'actor_name_snapshot' => $actor->name,
                'decision' => $result, 'received_amount' => (string) $amount,
                'internal_note' => $note, 'reviewed_at' => now(),
            ]);
            $attempt->update(['status' => $result]);
            $newStatus = $result === 'verified' ? 'paid_verified'
                : ($result === 'overpayment_review' ? 'transfer_awaiting_verification' : $result);
            $payment->update(['status' => $newStatus]);
            $order->update(['payment_status' => $newStatus]);

            return ['decision' => $result, 'payment_status' => $newStatus];
        }, 3);
    }

    /** @param list<string> $allowedStates */
    private function assertEligible(WebOrder $order, WebOrderPayment $payment, array $allowedStates): void
    {
        if ($order->payment_method !== 'bank_transfer' || $payment->method !== 'bank_transfer'
            || ! in_array($order->status, ['pending_confirmation', 'confirmed'], true)
            || ! in_array($payment->status, $allowedStates, true) || $order->payment_status !== $payment->status
            || $order->reservation_expires_at === null || $order->reservation_expires_at->isPast()) {
            throw new StorefrontCartException('PAYMENT_UNAVAILABLE', 'Bank transfer proof or review is unavailable for this order.', 409);
        }
        if ($order->payment_status === 'transfer_awaiting_proof' && $order->bank_proof_deadline_at !== null
            && WebOrder::query()->whereKey($order->id)->where('bank_proof_deadline_at', '<=', DB::raw('CURRENT_TIMESTAMP'))->exists()) {
            throw new StorefrontCartException('PAYMENT_UNAVAILABLE', 'The bank proof deadline has passed.', 409);
        }
        try {
            $this->staffOrders->assertCompleteReservations($order, true);
        } catch (ValidationException) {
            throw new StorefrontCartException('PAYMENT_UNAVAILABLE', 'The order reservation is no longer valid.', 409);
        }
    }
}
