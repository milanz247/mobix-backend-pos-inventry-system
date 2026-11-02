# NalakaMobile Ecommerce architecture — Phase 1

Status: architecture proposal; no application behavior is implemented by this document. Phase 2 requires explicit approval.

## 1. Existing system and ownership

`NalakaPos/` is the Laravel 13, Vue/Inertia and MySQL business application. It owns `products`, `product_variants`, `categories`, `brands`, `inventory_stocks`, `serialized_devices`, `customers`, `sales`, `sale_payments`, locations and related operations. `Ecommerce/` is a separate Next.js 16 customer frontend. Its current catalog, cart and order preview are demo-only browser data. Next.js must not own product or inventory tables or update stock or IMEIs.

The existing staff catalog and POS routes are authenticated web/Inertia routes. `bootstrap/app.php` currently registers no API route file. Staff product responses may contain costs and minimum prices. The public storefront needs separate queries and allowlisted resources. `StockAvailabilityService` already calculates location-specific quantity, serialized and bundle availability. `SaleService::checkout` records a completed sale and posts inventory; it is not a COD or unverified-transfer order endpoint. Preserve both services' POS behavior.

## 2. Laravel Ecommerce module in the existing database

Use dedicated `app/Models/Web/*`, `app/Services/Web/*`, `app/Http/Controllers/Storefront/*` and API resource namespaces (actual naming may follow repository conventions). Place web-specific records in the **same MySQL database** under `web_*` tables with business-scoped foreign keys to authoritative POS catalog, customer, location and device records. The module is logically separate, not a separate database or stock ledger. Web order and reservation records are business transactions; existing product, stock and device records are referenced, never copied as live balances.

The reviewed migrations contain `customers`, `sales`, `sale_payments`, `products`, `product_variants`, `inventory_stocks`, `serialized_devices`, `locations`, and staff `users`/sessions. No `web_*` tables were found in the inspected migrations. Existing POS `customers` has name, phone, email and credit settings; it has no password. Existing products have one `image_path`, description and POS active/inactive status, but no web publication or public slug. Existing sale source values do not include a web order source.

### Entity decisions

| Proposed entity | Purpose and relationships | Decision / timing |
| --- | --- | --- |
| `web_product_listings` | `business_id`, unique `product_id`, stable business-scoped `slug`, publication status, public copy and SEO metadata. Product remains authoritative for name, category, brand and variants. | **Required in Phase 2.** Keep publication independent of POS active/inactive; default all existing products unpublished. |
| `web_product_images` | Optional ordered public gallery tied to a listing and possibly variant/device-safe presentation. Paths point to Laravel-owned media. | **Defer.** Phase 2 may expose the existing product image when the listing is published; add a gallery only when media requirements are approved. |
| `web_customer_accounts` | Separate login identity, hashed password, email verification and optional `customer_id` FK to POS `customers`; business scoped. | **Required for registered checkout later**, not Phase 2. Never place credentials in `customers` or staff `users`. |
| `web_customer_addresses` | Saved addresses belonging to a web account; may reference a delivery area. | **Optional later.** Guest and registered orders need their own immutable address snapshots regardless of this table. |
| `web_orders` | Durable order, public reference, optional account/customer links, one fulfillment `location_id`, delivery-area/fee snapshot, payment method, separate order/payment status, totals and unique submission key. | **Required before live checkout**, not Phase 2. Must not be stored as a completed POS `sale`. |
| `web_order_items` | Order lines referring to authoritative product/variant IDs, with agreed name, option, unit-price, quantity, discount and tax snapshots. Internal device reference only when allocated. | **Required with orders.** Snapshots are historical facts, not a second product catalog. |
| `web_order_status_histories` | Append-only order transition, actor, reason and timestamp records. | **Required with order operations** for staff/customer audit and support. |
| `web_order_payments` | COD obligation or bank-transfer attempt, amount, method and payment status; linked to a web order. | **Required with orders.** A customer-provided transfer reference never sets paid status. |
| `web_payment_verifications` | Staff decision for each bank-transfer evidence/attempt, verifier, reason and timestamp. | **Required when bank transfer launches** if attempts can be resubmitted; preserves a reliable review trail. Evidence storage and retention need approval. |
| `web_order_reservations` | Order-line and location allocation referencing variant, quantity and optional private serialized-device ID; status and expiry. | **Required before live order submission.** Unique constraints prevent a device being reserved twice and an order line being allocated twice. POS availability and sale transactions must honor these records. |
| `web_order_price_approvals` | Proposed replacement device/price, prior and proposed totals, customer decision, token/actor and timestamps. | **Required if post-order price/condition changes are allowed.** Do not silently alter agreed totals. |
| `web_delivery_areas` | Supported area, active status and Laravel-managed fee configuration. | **Required before live quotes and checkout**, not Phase 2. Store the accepted fee on each order. |
| `web_location_delivery_areas` | Eligible location-to-area relationship and possible priority/fee override. | **Required before automatic fulfillment selection.** Keep one selected location per order. |
| `web_idempotency_keys` | General request/result ledger for multiple idempotent operations. | **Not automatically required.** A unique `(business_id, submission_key)` plus payload hash on `web_orders` can make order creation idempotent. Add a separate table only if quote/payment/other commands need their own durable request ledger. |

All proposed `web_*` rows must be business scoped, have intentional foreign-key delete behavior, and avoid public exposure of internal IDs where unnecessary. Schema names and exact columns are design proposals, not migrations. Do not duplicate `products`, `product_variants`, `inventory_stocks`, `serialized_devices`, or IMEI values.

## 3. Customer authentication and POS customer relationship

Keep current staff `web` guard, `users` provider, Fortify staff login and permissions unchanged. `config/auth.php` currently has only the `web` session guard and `users` provider. `config/fortify.php` uses `web` and the `users` password broker; `FortifyServiceProvider` authenticates staff `User` records and permits registration only before a business exists. Reconfiguring that Fortify instance for customers would risk staff authentication.

Later, introduce a dedicated customer provider/model and `web_customer` session guard or an equivalent isolated customer session implementation. Provide customer-only registration, login, logout, password reset, email verification and session endpoints without using the staff login route or dashboard. Use a separate password reset broker/token namespace, secure HttpOnly cookies, session rotation, CSRF protection for cookie-authenticated mutations, throttling and a deployment-specific cookie domain/SameSite policy. Laravel must authorize each account/order read and mutation; Next.js cannot assert ownership by sending an account or customer ID. Guest checkout remains available without an account, using a narrowly scoped, expiring tracking secret stored hashed in Laravel.

`web_customer_accounts.customer_id` may link to `customers.id` after proof of ownership and business match. Do **not** automatically join by matching email or phone alone: staff may have entered shared, stale or mistyped contact details. Define a verified-link or staff-reviewed merge flow. POS `customers` continues to hold business contact/credit information; web credentials live only in the web account table. A web order snapshots contact details even if an account/address is edited later. Account deletion and order retention need a separate policy.

## 4. Product publication and public catalog

Staff with a dedicated permission may manage `web_product_listings`. `draft/unpublished` is the default; `published` is a separate state from `products.status`. A listing is visible only when the listing is published, the product is active, its category and optional brand are active, and at least one eligible active variant is public. Deactivating a POS product immediately removes it from storefront reads. Unpublishing or changing a slug must not expose an old record; stable slugs and redirects for changed public URLs should be planned before launch.

Phase 2 can expose the current product `image_path` through an approved public URL when published. Later media requirements may justify `web_product_images`. Public specifications must be an explicit allowlist from product/variant attributes; never dump arbitrary `variant_attributes`, internal cost, minimum price, barcode, supplier fields, device data, or IMEI. Store public title/description and SEO title/description on the listing only where they differ from core product text. Do not make an empty or misleading specification appear authoritative.

For a used serialized phone, catalog `starting_price` means the lowest **sellable device price** among eligible public locations when device-specific pricing applies; otherwise use the variant price. Availability and starting price are advisory and must be checked again by Laravel during quote/order placement. If fulfillment policy or location eligibility is not yet configured in Phase 2, avoid claiming the price is order-ready; define a bounded read policy and expose `check_at_checkout` where necessary.

## 5. Read-only Phase 2 API contract

Prefix: `/api/storefront/v1`. All endpoints below are GET, public and JSON. No staff route is reused. Responses use an explicit allowlist, decimal-string money plus currency, ISO timestamps where present, consistent pagination, stable error codes and request IDs. Query parameters are validated and page size bounded. Unpublished product detail returns `404`.

| Endpoint | Query / path | Public response |
| --- | --- | --- |
| `GET /categories` | Optional pagination if needed | Active category identifier, name and slug if implemented; only categories with published products. |
| `GET /brands` | Optional pagination if needed | Active brand identifier, name and slug if implemented; only brands with published products. |
| `GET /products` | `q`, category, brand, condition, sort, page, per-page limit | Paginated listing summaries: product ID, public slug, name, brand/category, safe image, public condition, `starting_price`, currency, coarse availability. |
| `GET /products/{slug}` | Public product slug | Public description, approved specifications, active public variants, images, starting prices, safe availability and SEO fields. |
| `GET /products/{slug}/availability` | Optional documented delivery-area selector only when area data exists | `in_stock`, `out_of_stock` or `check_at_checkout` by public variant; no IMEI, internal device ID or exact stock count. Phase 2 is pre-reservation and advisory. |

Error envelope proposal: `{"error":{"code":"INVALID_FILTER","message":"...","fields":{}},"request_id":"..."}`. Use `404` for unpublished/missing, `422` for invalid filters, `429` for rate limits, `503` for temporary unavailable data. Do not return Eloquent models directly. The API should be versioned without introducing a new package unless existing framework facilities are insufficient.

Future contracts, outside Phase 2: server quote (`variant_id`, quantity, delivery area and payment method), atomic order submission with idempotency, customer order reads, staff payment verification and order transitions. Browser prices, fees, location IDs and IMEIs are never trusted. Laravel chooses one complete eligible location; if none can fulfill the whole cart, return a structured availability conflict.

## 6. Order, payment and reservation design for later phases

Order state and payment state are independent. An order may progress through pending confirmation, confirmed, preparing, dispatched, delivered, cancelled or expired; payment may be COD due, transfer awaiting verification, verified paid, rejected or refunded. These are proposed names; transition deadlines and staff rules remain business decisions. Bank transfer evidence never marks payment paid without authorized staff verification.

At live order submission, Laravel rechecks product visibility, exact price, delivery fee and whole-cart availability; chooses one eligible location; locks stock/device/reservation rows in a consistent order; creates the order and reservation in one transaction; and returns the same order on an identical idempotent retry. A conflicting retry fails. Reservation release on cancellation/expiry and conversion at fulfillment are explicit transactions. POS availability and **the actual POS sale transaction** must subtract or exclude active web reservations; a display-only change cannot prevent overselling. The existing POS checkout must remain intact while its sellable-stock checks become reservation aware.

For used devices, show a public starting price, then privately select/reserve an eligible device and present its exact server price before the customer agrees to the order. Staff revalidate that device during fulfillment. A substitute device with a different price or advertised condition requires a new customer approval; the agreed total does not change silently. No public response contains an IMEI. If business policy defers device selection until fulfillment, reservation and price-confirmation semantics must be redesigned and explicitly approved before checkout work.

Delivery areas and their eligible locations/fees belong in Laravel. A quote includes a final delivery fee and accepted area; unsupported areas fail validation. One order has one fulfillment location, with no split fulfillment in the initial release.

## 7. Phase 2 implementation checklist and boundaries

1. Review current Laravel route, catalog, stock, permission and test conventions again before coding.
2. Register `routes/api.php` and `/api/storefront/v1` GET routes. Keep staff routes and default staff auth behavior unchanged.
3. Design/migrate `web_product_listings` with business-scoped unique product link and slug, publication status defaulting to unpublished, and approved SEO/public copy fields. Backfill safely and test migration rollback/uniqueness behavior. No other `web_*` transaction tables in Phase 2.
4. Add staff-only publication controls in existing product management, protected by a dedicated permission. This internal staff write is necessary for a read-only **public** API. Preserve POS product status and existing forms.
5. Implement dedicated storefront catalog query service, request validation and API resources. Filter active/published products and variants; business-scope every query; bound page size and search.
6. Return public categories/brands, list/detail, public image URL, public approved specifications and decimal-string prices. For used devices derive a truthful starting price from the safe eligible-device read policy; do not leak device rows.
7. Adapt `StockAvailabilityService` for coarse quantity, serialized and bundle status without reservations. Label its Phase 2 output advisory. Do not modify stock or IMEIs.
8. Configure rate limits and consistent JSON errors. Ensure anonymous users still cannot reach staff product, customer, POS, inventory or sale endpoints.
9. Add API feature tests for publication default, active/inactive combinations, slug uniqueness and direct URL visibility, business scoping, categories/brands, search/filter/sort/pagination, money and image fields, used starting price, quantity/serialized/bundle availability, invalid input, throttling and forbidden internal fields.
10. Run existing product, inventory, sales, customer and location regression tests. Fix Phase 2 regressions only. Report migration impact and test results, then stop.

Phase 2 excludes customer auth, orders, reservations, payments, checkout, Next.js integration and web order staff management.

### Expected Phase 2 file areas

`bootstrap/app.php`; new `routes/api.php`; new storefront controllers, request classes, API resources and catalog service; `Product` listing relationship/model; one publication migration; staff product controller/request/form and permission definitions; new `tests/Feature/Storefront/*`; existing `tests/Feature/ProductManagementTest.php`, `InventoryManagementTest.php`, `SalesManagementTest.php` and location tests for regression. `Ecommerce/` stays unchanged.

### Phase 2 acceptance

Only explicitly published, active product/variant data appears; anonymous direct access to unpublished slugs returns `404`; internal costs, minimum prices, suppliers, staff data and device identifiers never appear in any public response; pagination and rate limits are bounded; quantity, serialized and bundle availability are correct for current stock; existing POS, inventory and staff catalog tests pass. Phase 2 must not claim reservation-aware availability or accept orders.

## 8. Open business decisions for later phases

- Reservation expiry and staff confirmation time limits for COD and bank transfer.
- Whether transfer verification is required before fulfillment, and evidence/retention rules.
- Delivery area definitions, fee amounts, tax treatment and location priority when several locations qualify.
- Cancellation cutoff, refund procedure and customer approval rules for a substitute used device.
- Whether registered customers must verify email before ordering; guest checkout remains permitted.

These decisions do not block Phase 2 catalog work. They must be resolved before the affected order, reservation, delivery or account phases.

## 9. Phase 2 Codex implementation prompt

> Implement **Phase 2 only** for NalakaMobile in `NalakaPos/`: a versioned, public, read-only Laravel storefront catalog API. Reinspect the current code and repository instructions before editing. Preserve existing staff authentication, POS checkout, inventory and Inertia catalog behavior. Do not modify `Ecommerce/`.
>
> Add `GET /api/storefront/v1/categories`, `/brands`, `/products`, `/products/{slug}`, and `/products/{slug}/availability`. Register API routing, validate and bound search/filter/sort/pagination inputs, add rate limiting and consistent JSON errors. Use dedicated storefront query services and explicit allowlisted API resources. Business-scope every query. Public results must contain only active and explicitly published products with eligible active variants; direct unpublished slugs return `404`.
>
> Add a business-scoped web product listing/publication record with a stable unique public slug and unpublished default for all existing products. Add a permission-controlled publication control to the existing staff product workflow without changing POS active/inactive status. Expose an approved public image URL and public specifications only where sourced from reviewed fields. Return customer-facing decimal-string selling prices and truthful used-device starting prices; never expose costs, minimum selling prices, supplier details, device IDs, IMEIs, staff data or arbitrary model attributes.
>
> Reuse the current stock availability logic for safe, coarse quantity, serialized and bundle availability. Treat it as advisory because reservations do not exist in this phase. Do not create or modify orders, reservations, payments, customer authentication, checkout, web order management, or Next.js integration.
>
> Add Laravel API feature tests covering publication default and staff authorization, slug uniqueness, active/inactive visibility, business scoping, categories/brands, query validation, search/filter/sort/pagination, price and image fields, used starting prices, quantity/serialized/bundle availability, forbidden internal fields, rate limiting and anonymous denial of staff endpoints. Run relevant existing product, inventory, sales, customer and location regression tests. Review migration/backfill safety. Report changed files, migration impact, tests and limitations, then stop and wait for approval before another phase.
