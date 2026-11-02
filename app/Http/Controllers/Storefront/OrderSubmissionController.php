<?php

namespace App\Http\Controllers\Storefront;

use App\Exceptions\StorefrontCartException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\StorefrontCartService;
use App\Services\StorefrontOrderSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderSubmissionController extends Controller
{
    public function store(Request $request, StorefrontCartService $carts, StorefrontOrderSubmissionService $orders): JsonResponse
    {
        if (! config('storefront.order_submission_enabled')) {
            throw new StorefrontCartException('ORDER_SUBMISSION_DISABLED', 'Order submission is not available.', 503);
        }
        if (strlen($request->getContent()) > 1024) {
            throw new StorefrontCartException('INVALID_REQUEST', 'The request is too large.', 413);
        }
        $input = Validator::make($request->all(), [
            'offer_reference' => ['required', 'string', 'ulid'],
            'accepted_terms_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'accepted' => ['required', 'accepted'],
        ])->validate();
        if (count($request->all()) !== 3) {
            throw new StorefrontCartException('INVALID_REQUEST', 'Unexpected order fields were supplied.', 422);
        }
        $submissionKey = $request->header('Idempotency-Key');
        if (! is_string($submissionKey) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $submissionKey)) {
            throw new StorefrontCartException('INVALID_IDEMPOTENCY_KEY', 'A valid UUID Idempotency-Key is required.', 422);
        }
        $business = Business::query()->where('setup_key', 'initial')->sole();
        $cartToken = $request->header('X-Storefront-Cart-Token');
        $cart = $carts->findForOrderReplay($business, (string) $cartToken);
        $result = $orders->submit($business, $cart, (string) $cartToken, strtolower($submissionKey),
            $input['offer_reference'], $input['accepted_terms_digest']);

        return response()->json(['data' => $result['data']], $result['replayed'] ? 200 : 201)
            ->header('X-Storefront-Order-Credential', $result['credential'])
            ->header('Cache-Control', 'private, no-store');
    }
}
