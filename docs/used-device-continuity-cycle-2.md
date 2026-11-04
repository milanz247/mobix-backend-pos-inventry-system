# Used-device continuity — improvement cycle 2

## Result and limits

The backend now preserves an explicitly selected used serialized device through
cart, quote, offer and acceptance. This is implemented and locally checked, but
MySQL feature, migration/rollback and independent-process contention tests could
not run. Do not infer production readiness or enable order submission from these
static/unit results. The frontend selector remains outside this cycle.

The starting audit was read before changes and the actual code/Git status was
rechecked. Existing uncommitted selection/cart, brand/catalog, storefront and
Cycle 1 proxy fixes were preserved. No credentials, grants, environment files,
working records or existing accepted orders were changed. No migration was run.

## Existing behavior retained

Discovery already used encrypted authenticated option keys and random, expiring,
hashed selection tokens. Cart insertion consumed a token after inserting a unique
physical-device line. The cart had an opaque public key and allowed multiple
devices per variant. Selection and cart insertion did not reserve inventory.
Public resources already used explicit output allowlists. Submission already
held the business/cart/offer locks, validated allocation, locked devices in sorted
order and created reservations transactionally. Active-device uniqueness and
idempotent replay were already implemented. Order private inventory snapshots
already came from the offered allocation. These mechanisms were retained.

## Confirmed missing pieces

- Cart had no server-validated selected-price snapshot.
- Quote used variant starting price and lost selected device and source-line identity.
- Offer allocated serialized devices from a price-ordered pool.
- Submission keyed quote items by variant, conflating two devices of one variant.
- Offer digest covered private allocations when generated but was not recomputed
  from persisted terms on acceptance.

## Implemented identity contract

| Stage | Identity, price and validation | Reservations |
| --- | --- | --- |
| Discovery/selection | Existing encrypted device option and hashed selection token; eligible used stock and effective backend price | None |
| Cart insert | Existing exact ID plus `selected_unit_price` copied from validated token snapshot, never browser price; quantity one; unique cart/device | None |
| Cart view/update | Shared validator checks selected device, snapshot price, variant/business/product, used condition, in-stock status, active business location and no active reservation. Invalid view has null line price/subtotal and cannot quote | None |
| Quote | Lock exact device in existing transaction, revalidate, intersect its actual location with other lines, retain aggregate demand checks. Snapshot source key, ID, condition, location and existing decimal unit price | None |
| Offer | Match quote by cart key; require exact ID, price and condition; selected device must remain at quoted location and chosen single fulfillment location. Pin that ID and exclude it from automatic pools, including shared combo components | None |
| Acceptance | Require exact cart/quote/offer identity and price; require version-2 digest for used lines; recompute commercial terms; existing sorted physical locks revalidate availability, condition, price and reservation state | Only here, within successful existing transaction |
| Order | Existing offered allocation drives private inventory snapshot and reservation, after continuity checks. No alternative-device lookup is introduced | Exact selected device |

`StorefrontSelectedDeviceService` is the shared internal identity/quote-matching
boundary. It reuses existing catalog eligibility and effective-price functions.
Catalog publication remains validated by each caller. Locking calls require a
transaction; quote and offer process selected IDs in ascending order. Neither
acquires a new business mutex. Acceptance retains its existing business mutex,
which participating POS/catalog writers also acquire, and its existing sorted
physical-device locks. Temporary row locks are not inventory reservations.

`StorefrontOfferTermsService` computes version-2 SHA-256 terms from explicitly
selected persisted fields, including cart/quote/principal, source lines, exact
allocations, device conditions/prices, fulfillment location, contact/address,
expiry and monetary totals. Generation reloads persisted snapshots before hashing
so MySQL decimal/JSON normalization is consistent with recomputation. Internal
placeholder digest exists only inside the offer transaction. No placeholder offer
can be committed if final hashing fails.

## Migration and compatibility

One additive migration was added:
`2026_11_04_000003_add_selected_device_quote_snapshots.php`.

- `web_cart_items.selected_unit_price`: nullable DECIMAL(16,2).
- `web_quote_items`: nullable `source_cart_item_key`, `serialized_device_id`,
  `selected_location_id`, `selected_condition`; unique quote/source key.
- `web_order_offers.terms_digest_version`: nullable unsigned tiny integer.
- Existing quote `unit_price` is reused, rather than duplicated.
- Existing cart IDs, unique selected-device constraints, offer private allocation,
  order inventory snapshots and reservation constraints are reused unchanged.
- Historical quote inventory IDs are snapshots, deliberately without foreign keys
  to mutable/deletable device/location rows. Application checks enforce scope;
  deleting/changing current inventory must not rewrite historical identity.
- No backfill infers devices or prices. Legacy used cart lines missing ID or price
  must be removed and selected again. Old used quotes/offers without snapshots or
  digest version 2 cannot be newly accepted. Existing committed idempotent order
  replay still returns before these new validation gates.
- Legacy ordinary quotes retain unambiguous singleton variant matching when their
  source key is null and they do not require used-device confirmation. Legacy
  ordinary offers keep their original digest validation. New ordinary offers use
  version 2 while retaining their existing allocation behavior.
- Rollback refuses before DDL if selected cart prices, used quote snapshots or
  versioned offers exist. Archival/reconciliation is an operator decision, not an
  automatic deletion step. MySQL DDL is not atomic across these table changes;
  stage/backup and verify the migration in disposable MySQL before deployment.

Deploy schema before application code in a coordinated release. This cycle did
not run the migration or alter rollout flags. Previously accepted used orders
are not rewritten; any historical review remains a separate staff task.

## Privacy/security review

Public cart/quote/offer/order shapes are unchanged. Resources continue explicit
allowlisting; private quote/cart attributes and the digest version are also hidden
on models. Device IDs, location IDs, source quote keys, IMEI, serial, allocation,
suppliers and costs are not added to responses or expected error messages. Public
unit prices remain available as before. The digest includes the secret guest
principal hash alongside commercial fields, rather than exposing an allocation.
Browser price fields are ignored by controller validation; the snapshot is copied
only from the already validated server selection. Payment proof and staff payment
verification behavior are unchanged. No selector UI was modified.

## Exact files changed in this cycle

Application:

- `app/Models/WebCartItem.php`, `WebQuoteItem.php`, `WebOrderOffer.php`.
- `app/Services/StorefrontCartService.php`, `StorefrontOrderOfferService.php`,
  `StorefrontOrderSubmissionService.php`.
- New `app/Services/StorefrontSelectedDeviceService.php` and
  `StorefrontOfferTermsService.php`.
- New migration listed above.

Tests:

- Updated `tests/Feature/Storefront/CartQuoteApiTest.php`, `OrderOfferApiTest.php`,
  `OrderSubmissionApiTest.php`, `StaffWebOrderManagementTest.php` and
  `tests/Support/CodPickupFixture.php` so used fixtures perform real explicit
  selection instead of bypassing the selection-token requirement.
- New `tests/Support/SelectedDeviceSelection.php` and
  `UsedDeviceContinuityFixture.php`.
- New unit and feature `UsedDeviceContinuityTest.php` files.
- New `tests/Concurrency/UsedDeviceAcceptanceConcurrencyTest.php` and
  `tests/Support/used-device-acceptance-worker.php`.
- This document and a Cycle 2 note in the frontend audit/README.

All other dirty files were present at the beginning of this cycle.

## Verification actually executed

| Check | Result |
| --- | --- |
| Read-only connection with MySQL database explicitly set to `nalaka_mobile_test`, URL override disabled, cached config rejected | Failed: `Illuminate\Database\QueryException: SQLSTATE[HY000] [2002] Unknown error while connecting`. PDO did not establish a connection; cause beyond that is unverified. No database mutation followed. |
| `vendor/bin/pest tests/Unit --colors=never` | 16 passed, 63 assertions. Includes resolver, private quote identity, same-variant matching, ordinary legacy matching, digest mutation and resource privacy cases. Uses mocked eligible queries; proves no MySQL locking semantics. |
| `vendor/bin/phpstan analyse --debug --no-progress --memory-limit=512M` | Passed, zero errors. Normal parallel invocation could not bind `tcp://127.0.0.1:0`; serial `--debug` avoids that environment restriction without suppressing analysis. |
| `php artisan route:list --path=api/storefront` | Loaded 23 routes. |
| `php -l` on all modified/untracked PHP files | Passed 34 files, including prior preserved changes. |
| Scoped `vendor/bin/pint` | Completed; formatting checked again after final edits. |
| `npm test` in frontend | Passed 15 test files, including real proof endpoint helper and bank-proof restrictions. |
| Feature/concurrency `--list-tests` | Test discovery succeeded; not execution. |
| `git diff --check` | Passed. |

No public TypeScript contract or frontend code changed, so TypeScript/ESLint/build
were not rerun for this backend-only cycle. Browser QA is unverified and not used
as evidence of correctness here.

## Tests written but blocked

The focused feature file contains 36 discovered cases covering valid guest/authenticated carts, ignored browser
price, legacy missing ID/price, same-variant devices, duplicate insertion, changed
price/condition/status/location/variant, quote/cart mismatch, legacy quote, wrong
pickup shop with cheaper local stock, offer digest/allocation changes, inventory
snapshot equality, reservations created after selection, competing advisory
offers, idempotent retry, no early reservation, rollback refusal, ordinary new
serialized phones, accessories, spare parts and combos, and a combo sharing the
selected used variant.

The contention test uses two independent Laravel/MySQL processes. Both must
first prove the parent's business lock blocks them with NOWAIT; after release,
only one acceptance may succeed and the winner's retry must not add a reservation.
It is guarded to the exact empty disposable database
`nalaka_mobile_device_continuity_test`, checks `SELECT DATABASE()`, uses no
`migrate:fresh`/record deletion, and passes worker inputs through stdin. It leaves
test fixtures in that disposable database; do not point it at working data.

After test infrastructure is available, verify actual database identity before
running the focused suite or migration. Suggested feature command for the existing
isolated `nalaka_mobile_test` environment:

```sh
vendor/bin/pest tests/Feature/Storefront/SelectedDeviceCartTest.php tests/Feature/Storefront/UsedDeviceContinuityTest.php tests/Feature/Storefront/CartQuoteApiTest.php tests/Feature/Storefront/OrderOfferApiTest.php tests/Feature/Storefront/OrderSubmissionApiTest.php tests/Feature/Storefront/BankTransferPaymentTest.php --colors=never
```

Feature tests use RefreshDatabase and may recreate schema; run only after checking
cached config and effective connection/database identity. Also run the rest of the
storefront suite and migration up/guarded-down on disposable MySQL. For the separate
empty contention database configured by an authorized operator:

```sh
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=nalaka_mobile_device_continuity_test DB_URL= vendor/bin/pest tests/Concurrency/UsedDeviceAcceptanceConcurrencyTest.php --colors=never
```

No grants or credentials were requested/changed, and no SQLite substitute was used.
Runtime completion is blocked by MySQL access. Next recommended work is to execute
these database gates, then a separate selector integration cycle; neither is
claimed complete by unit/static checks. This cycle stops here.
