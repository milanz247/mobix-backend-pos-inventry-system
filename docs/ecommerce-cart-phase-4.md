# Phase 4: guest cart and indicative quotation

## Ownership and storage

The Laravel `web_carts` table owns guest carts for the existing `initial` business. A 32-byte random token is issued only after a valid add operation. Laravel stores its SHA-256 hash, never the raw token. `web_cart_items` references existing products and variants and stores only quantity plus an opaque public item key. The key is a routing handle, not an authorization credential. Every item mutation resolves the cart from the token and checks that the item belongs to it. Cart writes lock the cart row in a database transaction. Cart tokens expire 30 days after the last successful mutation; expired tokens return `410 CART_EXPIRED`.

Next.js owns the guest cookie. In production it is `__Host-nm_cart` with `Secure`, `HttpOnly`, `SameSite=Lax`, path `/`, and no domain. HTTP development uses `nm_cart_dev` without `Secure`. The browser calls only same-origin Next.js route handlers. Mutations require the configured storefront `Origin` and `X-Storefront-Action: 1` header. Next.js forwards the token to Laravel in `X-Storefront-Cart-Token`; it is never put in JSON or browser JavaScript. Configure `STOREFRONT_SITE_ORIGIN` to the exact HTTPS Next.js origin in production. The Laravel API does not use the staff guard or an ambient cookie for cart authorization.

## API contract

All routes are under `/api/storefront/v1`. JSON responses use `{ "data": ... }`; errors use `{ "error": { "code", "message", "fields" }, "request_id" }`.
Cart and quote responses set `Cache-Control: private, no-store` and vary by cart token.

| Method | Path | Body | Result |
| --- | --- | --- | --- |
| GET | `/cart` | none | Current cart or empty cart |
| POST | `/cart/items` | `product_id`, `variant_id`, `quantity` | Current cart; first successful response also carries the token in a response header for the Next.js proxy |
| PATCH | `/cart/items/{public_key}` | `quantity` | Current cart |
| DELETE | `/cart/items/{public_key}` | none | Current cart |
| DELETE | `/cart` | none | Empty current cart |
| POST | `/quotes` | none | New 15-minute indicative quotation |

`quantity` is a whole number from 1 to 10. Used serialized options are limited to one because the exact device has not been selected. A cart is limited to 30 distinct variants. The current public product must still be active and published, and its variant must be active. The service uses `StorefrontCatalogService` and `StockAvailabilityService` at active locations to reject quantities with no eligible location. A quotation also requires at least one common eligible location for every cart line. Carts where two lines draw on the same bundle component are rejected as uncertain, rather than quoting stock twice. These checks remain advisory; simultaneous POS sales must be checked again when an order is eventually reserved.

Cart item prices are current indicative prices, read from Laravel. The browser cannot set them. Unpublished or inactive products already in a cart remain removable but display as unavailable and cannot be quoted. Public responses omit token hashes, cart IDs, IMEIs, serialized device IDs, cost and minimum prices, supplier and staff details, and stock counts.

## Quotation rules

`web_quotes` and `web_quote_items` keep decimal price and line snapshots. Each quotation receives a unique ULID public reference, creation time, expiry time, currency, `indicative` status, product subtotal and total. The effective status is expired after `expires_at`, even though the stored issue status remains `indicative`. A new request always reads current publication, pricing and availability; it never reuses a prior snapshot. Existing POS selling prices already include any applicable tax. POS discounts are staff-authorized and there is no public promotion rule in this phase, so public quote discounts are `0.00`. Delivery fees are excluded because delivery-area configuration is a later phase.

For used serialized phones the quoted unit price is the eligible device starting price from the existing catalog service. The quote sets `requires_device_confirmation` and `total_is_final: false`. It does not name or reserve a device. The later order phase must lock a specific device, calculate its price and obtain customer agreement before accepting the order. Quotes do not create orders, sales, payments, stock movements, or reservations.

The API applies a per-token limit and a broader source-IP limit. Because a server-side Next.js proxy may aggregate many users behind one IP, production should add a gateway or WAF limit per real visitor and monitor token creation. No direct browser-to-Laravel CORS permission is needed for the current flow.
