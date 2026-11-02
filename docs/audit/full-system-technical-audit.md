# NalakaMobile full system technical audit

Audit date: 2026-11-02. Scope: current `NalakaPos` and sibling `Ecommerce` trees, including existing untracked files. This is a read-only audit of application code and business data; only these audit documents were added. The historical [stabilization report](../current-project-stabilization-report.md) is context, not a current-state assertion. Status terms are defined in [the module matrix](module-completion-matrix.md).

## Evidence and limits

| Evidence | Current result | Limit |
|---|---|---|
| Source | 215 Laravel routes from `php artisan route:list --json`; service, controller, migration, Next route and component inspection | Presence does not establish runtime correctness |
| Database | Connected to MariaDB `nalaka_mobile` by `php artisan db:show` and read-only SQL; 74 tables including framework tables and latest `2026_11_02_000007` migration present | Counts and selected integrity probes, not a full financial reconciliation |
| Working data | 1 business, 20 products, 6 published listing rows, 52 stock rows, 72 serialized devices, 5 sales, 1 purchase, 0 web orders, 0 web reservations; no negative stock rows or duplicate `(business_id, identifier)` groups in the sampled checks | Snapshot only; no order/checkout/handover live transaction to inspect |
| Live HTTP | Laravel `/up` 200; Next `/shop` 200; Next catalog proxy 200 with 6 products and 4 non-null image URLs; the formerly failing JPEG returned 200 `image/jpeg` (40,669 bytes) | No browser interaction or responsive visual inspection; historical seven listings/all null images are stale |
| Automated checks | Next Node suite: 8 test files pass; Next ESLint and TypeScript pass; POS Vue type check passes; PHP syntax: 335 files, zero failures; Laravel Pint test fails in 4 import-order files; PHPStan reports 20 errors in 4 files | Laravel feature suite not run: `RefreshDatabase` would migrate/reset a test database; no production build pass |
| Build | Prior and current-session `next build` attempts hit Turbopack CSS worker port binding `Operation not permitted`, including escalated execution | Environment/worker restriction; does not prove an application build defect or a successful production build |

The application and its untracked code were not edited in this phase. The six current listings and four image URLs supersede the seven/all-null historical snapshot. Read-only runtime configuration inspection found order submission disabled and offer, reservation, COD confirmation, bank proof and order-access-key settings all unconfigured; no secret value was displayed. No working database row was changed.

## Executive assessment

The existing Laravel + Vue/Inertia + MySQL + Next architecture is viable for a phone shop. The strongest assets are an explicit inventory ledger and stock balance, scoped business/location relations, IMEI identifier uniqueness, idempotent sale and web-order keys, and a transactional COD pickup handover path. Rewriting all of that would carry more data-integrity risk than a targeted refactor. The main gaps are operational proof and completeness: zero working web orders, a disabled/unconfigured order-ready flow, no full repair/warranty workflow, incomplete accounting/reporting, no browser E2E suite, and failing global quality gates.

### Highest priority findings

| Severity | Finding and evidence | Consequence / action |
|---|---|---|
| High, business | `config/storefront.php` defaults `order_submission_enabled` false and requires offer/reservation/confirmation lifetimes; the working DB has zero web orders. `StorefrontOrderOfferService::create` returns `OFFER_NOT_CONFIGURED` without a positive TTL. | Checkout UI exists, but live order, payment, preparation and handover behavior is unverified and may be unavailable. Keep the gate closed pending policy and end-to-end acceptance. |
| High, business | `WebOrderFulfillmentService::collectPickup` explicitly rejects bank transfer and home delivery and only posts a COD shop-pickup sale. `WebOrderStaffService::confirmBank` and proof review exist, but no bank delivery sale/fulfillment bridge is present. | Bank-transfer confirmation is not a completed accounting/fulfillment workflow. Design its posting and reconciliation before enabling. |
| Medium, security/operations | Guest order tracking is protected by a bearer credential in an HttpOnly Next cookie (`lib/order-proxy.ts`, `lib/order-tracking-proxy.ts`), but cookie persistence/recovery and account-independent customer support are not proven in a browser. `OrderConfirmationProvider` keeps the immediate submission receipt fallback only in React memory. | A refresh loses that fallback; `OrderTrackingClient` can still load details while the order cookie survives. Recovery on another browser needs an approved process. Test access, expiry and lost-cookie scenarios. |
| Medium, quality | PHPStan reports 20 errors in `SaveProduct`, `StockOverviewReportController`, `CreditSalesReportController`, `InventoryService`; Pint test reports import ordering in `config/permission.php` and three route files. | CI quality gate is not clean. Treat report/export stream handling and report type issues as correctness review items. |
| Medium, performance | `StorefrontCatalogService::availability` queries each active location per variant, while `ProductResource` maps variants and calls availability; `StorefrontCartService::view` performs catalog/stock work per item. | Catalog and cart read cost grows with variant/location count. Profile before batching and keep exact stock checks in transactional writes. |
| Medium, product | `Ecommerce/app/layout.tsx` sets site-wide `robots: { index: false, follow: false }`; `app/page.tsx` still says checkout/payment will be enabled in later phases. | SEO and customer copy require launch review; current wording does not match the implemented screens. |
| Medium, development | Next `lib/storefront-api.ts` reads through its own same-origin API proxy for server-rendered catalog data; public image origin and server API origin are configured separately. | Extra local HTTP hop and origin configuration increase latency and deployment failure modes. Retain browser same-origin proxy, evaluate direct server-only catalog reads. |

## Laravel architecture and module boundaries

`routes/web.php` includes management, catalog, inventory, purchasing, sales, shifts, settings and staff storefront route files. `routes/api.php` provides public catalog, cart/quote/offer/order, tracking and proof APIs with separate throttle groups. Web routes use `auth` and named permission middleware; public APIs use anonymous guest tokens and throttles. `AppServiceProvider` supplies rate limiters and owner permission override; `bootstrap/app.php` defines web middleware and structured storefront error rendering.

The service layer owns important transactions: `InventoryService`, `SaleService`, `PurchaseService`, `CashierShiftService`, `WebReservationService`, `StorefrontOrderSubmissionService`, `WebOrderStaffService`, `WebOrderPreparationService` and `WebOrderFulfillmentService`. Controllers usually validate or delegate, but `ProductController` and some report controllers combine query shaping and large Inertia payload construction. The very large `InventoryService` (over 63 KB), `SaleService` (52 KB), `StorefrontOrderSubmissionService` (30 KB) and Next `checkout-client.tsx` (29 KB) are maintainability hotspots. Split by use case only after preserving transactional boundaries in tests.

`LocationAccessService::business()` selects the `setup_key=initial` business globally, while many write paths scope by its ID and check accessible locations. This is a workable single-business architecture, not a proven arbitrary multi-tenant platform. Do not advertise tenant isolation beyond the initial business without auditing every query, route binding and background command. `LocationAccessService::hasAllLocationAccess` also grants all locations to any `Owner` role; that role must be strictly controlled if multiple businesses are introduced.

No application jobs, events or notification workflows were found beyond Laravel queue tables and scheduled storefront expiry. `routes/console.php` schedules `storefront:expire-orders` each minute; `ExpireStorefrontOrders` bounds scans, resumes with a cache cursor and logs aggregate results. A scheduler process and cache durability are operational requirements, not verified here.

### Inventory and POS correctness

`InventoryService::changeQuantity` locks stock, rejects negative balances and checks `WebReservationService::assertQuantityFloor` for deductions. Device sale/transfer paths call `assertDeviceFree`; `serialized_device_identifiers` has unique `(business_id, identifier)` and reservations have a unique active-device slot plus a MySQL check constraint. `SaleService`, `PurchaseService` and web handover use transactions and idempotency keys. `WebOrderFulfillmentService::collectPickup` links web order, sale, payment, stock operation and fulfillment in one transaction. These are strong source-level controls, not proof against every race. Existing `tests/Concurrency/CodPickupConcurrencyTest.php` provides dedicated MySQL concurrency coverage, but it was not rerun in this read-only phase.

Combo component deduction is in `InventoryService::consumeBundleComponents` and web accepted allocations are posted through `postExactWebSale`. Returns run through `SaleService` and `InventoryService::returnSale`. Damaged stock can be repaired to available stock or written off; this is inventory disposition, not customer repair-job management. Accounting calculations and tax/discount allocation live in `SaleService`; `CashierShiftService` includes cash movements and expected-drawer calculations. The source has receipt views and browser print/thermal width preferences. No general ledger, standalone payable/expense accounting, or bank settlement reconciliation was found. See the [database audit](database-and-data-integrity-audit.md) for reconciliation tests needed.

## Next architecture and integration

The Next App Router has home, shop, product detail, cart, checkout, order confirmation/tracking and an unavailable preview route. Catalog is server-rendered with Suspense; cart and checkout are client state (`CartProvider`, `CheckoutClient`). Server-only proxy modules separate catalog, cart, order submission, tracking and bank proof. TypeScript types in `lib/*-types.ts` and explicit response guards in proxy modules reduce accidental data exposure. `proxyCatalog` drops Laravel pagination links, and order proxies retain bearer credentials server-side. Mutations check `Origin`, `Sec-Fetch-Site` when present, and `X-Storefront-Action`; the cross-origin Laravel API does not have browser CORS access in the intended flow.

The proxy family is not fully uniform: catalog returns a narrow pagination projection; cart generally forwards parsed JSON and has a pickup-specific projection; order, tracking and proof each implement separate allowlists. This is defensible for differing sensitivity, but contracts, errors and limits should be documented and tested from one specification. `lib/storefront-api.ts` uses `cache: no-store`, so live stock/price is favored over page cache; this adds repeat calls, including a catalog request in `generateMetadata` and another for product content. `next.config.ts` restricts remote images to `/storage/product-images/**`; `lib/storefront-display.ts` validates paths. The current known image returns HTTP 200, but browser visual rendering remains unverified.

### Actual API flow map

All Laravel paths below begin `/api/storefront/v1/`. Next paths are same-origin. Public API route names are currently unnamed; staff routes are named in `routes/storefront-orders.php`.

| Feature | Frontend page | Next proxy/client | Laravel endpoint and owner | Verification | Issue/limit |
|---|---|---|---|---|---|
| Catalog | `/`, `/shop` | `/api/storefront/v1/products`, `lib/storefront-api.ts` | `GET products`, `CatalogController::products` | Live 200, 6 products; `CatalogApiTest` | Per-variant/location query cost; browser visual pending |
| Product details | `/shop/[slug]` | `products/[slug]`, availability route | `GET products/{slug}` and `{slug}/availability`, `CatalogController` | Historical real HTML; current source and proxy tests | Current detail not rechecked; metadata makes second fetch |
| Categories | `/`, `/shop` | `categories/route.ts` | `GET categories`, `CatalogController::categories` | Historical live and `CatalogApiTest` | Current data count not rechecked |
| Brands | `/shop` | `brands/route.ts` | `GET brands`, `CatalogController::brands` | Historical live and `CatalogApiTest` | Current data count not rechecked |
| Cart | `/cart`, product cards | `/api/cart`, `/api/cart/items`, `lib/cart-proxy.ts`, `CartProvider` | `GET/DELETE cart`, `POST/PATCH/DELETE cart/items`, `CartController` | `CartQuoteApiTest`, Next proxy tests | No live populated-cart browser test |
| Quotes | `/cart`, `/checkout` | `/api/quotes`, `lib/cart-proxy.ts` | `POST quotes`, `CartController::quote` | Automated PHP/Node tests | Indicative only; no reservation |
| Delivery areas | `/checkout` | `delivery-areas/route.ts`, `lib/cart-proxy.ts` | `GET delivery-areas`, `OrderOfferController::deliveryAreas` | Historical empty response and tests | Working area/fees not verified |
| Pickup locations | `/checkout` | `pickup-locations/route.ts`, `lib/cart-proxy.ts` | `GET pickup-locations`, `PickupLocationController::index` | Historical empty response and tests | Working enabled location not verified |
| Offers | `/checkout` | `order-offers/route.ts`, `lib/cart-proxy.ts` | `POST order-offers`, `OrderOfferController::store` | `OrderOfferApiTest`, Next tests | Configured TTL required; no live offer |
| Order submission | `/checkout` | `orders/route.ts`, `lib/order-proxy.ts`, `order-submission-client.ts` | `POST orders`, `OrderSubmissionController::store` | `OrderSubmissionApiTest`, Node tests | Disabled by default; zero working orders |
| Order tracking | `/orders/[reference]` | `orders/[reference]/route.ts`, `lib/order-tracking-proxy.ts` | `GET orders/{reference}`, `OrderTrackingController::show` | `OrderTrackingApiTest`, Node tests | No real order/cookie lifecycle verification |
| Bank proof | tracking page | `bank-transfer-proof/route.ts`, `lib/bank-proof-proxy.ts` | `POST orders/{reference}/bank-transfer-proof`, `BankTransferProofController::store` | `BankTransferPaymentTest`, Node tests | Private storage and review implemented; no live proof/payment |

The backend uses `ProductResource`, `CartResource` and offer/order response arrays; Next has separate handwritten types and validators. No generated shared schema exists. Catalog and pickup are different projection strategies. `NEXT_PUBLIC_STOREFRONT_API_URL` is used for public images and fallback server API URL; `STOREFRONT_API_URL` is the preferred server-only URL; `STOREFRONT_SITE_ORIGIN` controls self-proxy and mutation origin checks. Wrong host/origin values can break server fetches or valid mutations.

### Source and route anchors

| Area | Exact current files | Representative route names / URI |
|---|---|---|
| Auth and permissions | `app/Providers/FortifyServiceProvider.php`, `app/Providers/AppServiceProvider.php`, `app/PermissionName.php`, `app/Services/LocationAccessService.php`, `routes/management.php` | `users.index`, `roles.index`, `permissions.index` |
| Catalog/admin | `app/Http/Controllers/Catalog/ProductController.php`, `app/Actions/Catalog/SaveProduct.php`, `app/Http/Controllers/Catalog/WebProductListingController.php`, `routes/catalog.php` | `products.index`, `products.store`, `storefront.products.index`, `storefront.products.update` |
| Inventory/POS | `app/Services/InventoryService.php`, `app/Services/StockAvailabilityService.php`, `app/Services/SaleService.php`, `app/Http/Requests/Sales/CheckoutRequest.php`, `routes/inventory.php`, `routes/sales.php` | `inventory.transfers.store`, `inventory.adjustments.store`, `pos.checkout`, `sales.payments.store`, `sales.returns.store` |
| Purchasing/cash | `app/Services/PurchaseService.php`, `app/Services/CashierShiftService.php`, `app/Services/ShiftTransactionService.php`, `routes/purchasing.php`, `routes/shifts.php` | `purchases.post`, `purchases.payments.store`, `shifts.*` |
| Storefront API | `app/Http/Controllers/Storefront/CatalogController.php`, `CartController.php`, `OrderOfferController.php`, `OrderSubmissionController.php`, `OrderTrackingController.php`, `BankTransferProofController.php`, `routes/api.php` | Unnamed `GET/POST /api/storefront/v1/*` routes as listed above |
| Web order staff | `app/Services/WebReservationService.php`, `app/Services/WebOrderStaffService.php`, `app/Services/WebOrderPreparationService.php`, `app/Services/WebOrderFulfillmentService.php`, `routes/storefront-orders.php` | `storefront-orders.confirm-cod`, `.confirm-bank`, `.preparation.ready`, `.handover.pickup`, `.bank-proofs.review` |
| Next pages and proxy | `../Ecommerce/app/shop/page.tsx`, `../Ecommerce/app/shop/[slug]/page.tsx`, `../Ecommerce/components/storefront/checkout-client.tsx`, `../Ecommerce/lib/storefront-api.ts`, `cart-proxy.ts`, `order-proxy.ts`, `order-tracking-proxy.ts`, `bank-proof-proxy.ts` | `/shop`, `/shop/[slug]`, `/checkout`, `/api/cart`, `/api/storefront/v1/orders/[reference]` |

## Security review

| Severity | Finding | Evidence and qualification |
|---|---|---|
| High if multi-business | Single `initial` business assumption and global owner role privilege | `LocationAccessService`, `AppServiceProvider::Gate::before`; source-level design limit, not an observed data leak |
| Medium | Anonymous bearer cart/order access needs rotation, recovery and retention policy | `StorefrontCartService`, `StorefrontOrderTrackingService`, Next HttpOnly cookies; brute-force protection uses throttles, but no browser lifecycle test |
| Medium | Sensitive proof and contact data lifecycle unspecified | Proofs stored on private `local` disk, scoped download in `StaffBankTransferController`; retention/deletion, backup encryption and incident access logging need policy |
| Medium | Web status/amount state duplicated | `web_orders.payment_status`, `web_order_payments.status`, bank decisions, linked sale payment; code checks consistency but reconciliation monitoring is needed |
| Low/operational | Product image publication depends on public storage and approved origin | `config/filesystems.php`, `StorefrontCatalogService::imageUrl`, Next `remotePatterns`; link and known JPEG currently work |

No destructive security probes were run. Laravel web sessions have framework CSRF protection; Next cookie mutations use explicit origin/action checks. Upload MIME/size validation is in `BankTransferProofController` and `WebBankTransferService`; proof download is permission and location scoped. `ProductController` uses Form Requests/validated data for writes; a complete mass-assignment review across every model remains a prelaunch test item.

Authentication is based on Fortify and the existing `users`, password-reset, two-factor and passkey schema (`app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php`, `2024_01_01_000000_create_passkeys_table.php`). `tests/Feature/Auth/*` covers the main sign-in and challenge paths. Session/cookie production settings in `config/session.php` and reverse-proxy HTTPS behavior were inspected in source but not exercised in a production browser. Public storefront routes use separate rate limiters in `AppServiceProvider`; private staff routes rely on session auth, permissions and location checks.

## Test and production readiness

| Area | Existing tests / checks | Current conclusion |
|---|---|---|
| Auth, roles, locations | `tests/Feature/Auth`, `Management`, `LocationAccessTest`, `Settings/Location*` | Implemented and tested; not re-run against a dedicated fresh DB here |
| Catalog, inventory, purchases, POS | `ProductManagementTest`, `InventoryManagementTest`, `PurchaseManagementTest`, `SalesManagementTest`, `CashierShiftTest`, `PosShiftIntegrationTest` | Broad feature tests; concurrency and reconciliation still need dedicated MySQL validation |
| Web catalog/cart/order | `tests/Feature/Storefront/*`, `ReservationFoundationTest`, `WebFulfillmentPolicyTest` | Route/service coverage exists; no working web order |
| COD pickup races | `tests/Concurrency/CodPickupConcurrencyTest.php` | MySQL concurrency test exists, not rerun; inspect its failure/rollback cases before enablement |
| Next proxy/validation | Eight `tests/*.test.mjs` files passed via Node; ESLint and `tsc --noEmit --incremental false` passed | No browser-level E2E or visual test |
| Laravel static/format | PHP syntax: 335 files, zero failures; POS Vue type check passed; PHPStan 20 errors; Pint 4 files fail | CI gate fails; no application files changed during audit |
| Build/deploy | POS build historically passed; Next production build blocked by worker port permission; public storage known JPEG 200 | Repeat Next build on normal CI/host, then test production start and image handling |

Laravel `phpunit.xml` selects MySQL `nalaka_mobile_test` and `tests/Pest.php`/feature tests use database refresh. A full run would alter a test DB, so it was intentionally not run. SQLite cannot prove MySQL locking, composite FK or MySQL CHECK behavior. No measured code coverage percentage is claimed.

For deployment, use separate PHP and Next processes, MySQL, HTTPS, persistent private proof storage, public product media, scheduler, logs, backups and restore drills. `composer.json` setup script runs migrations; it is not a safe audit command. `routes/console.php` expiry schedule and `config/queue.php` need production process configuration. A production smoke test, migration rehearsal on a copy, backup restore, browser tests and business acceptance are outstanding.

## Decision

Preserve the technology stack and the proven data model while refactoring large services and completing accounting, repair and Ecommerce operations. Do not migrate or rewrite production data until golden-master reconciliation and MySQL concurrency tests pass. Module decisions are in [the matrix](module-completion-matrix.md), target boundaries in [the architecture proposal](target-architecture-proposal.md), and staged gates in [the roadmap](rebuild-and-refactoring-roadmap.md).
