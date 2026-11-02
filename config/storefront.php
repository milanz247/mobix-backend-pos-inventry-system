<?php

return [
    // Null disables order-ready offers until an approved operational lifetime is configured.
    'offer_ttl_minutes' => env('STOREFRONT_OFFER_TTL_MINUTES'),
    'order_submission_enabled' => env('STOREFRONT_ORDER_SUBMISSION_ENABLED', false),
    'reservation_ttl_minutes' => env('STOREFRONT_RESERVATION_TTL_MINUTES'),
    // Null means the applicable operational deadline is not approved; do not auto-expire.
    'cod_confirmation_ttl_minutes' => env('STOREFRONT_COD_CONFIRMATION_TTL_MINUTES'),
    'bank_proof_ttl_minutes' => env('STOREFRONT_BANK_PROOF_TTL_MINUTES'),
    'order_access_key' => env('STOREFRONT_ORDER_ACCESS_KEY'),
    'proof_max_kb' => env('STOREFRONT_PROOF_MAX_KB', 5120),
];
