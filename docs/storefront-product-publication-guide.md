# Storefront product publication guide

## Open the administration panel

Open `http://localhost:8000/login` (or the configured POS address) and sign in with your existing staff account. Use **Ecommerce → Storefront Products**. The direct management URL is `/storefront/products`.

The Ecommerce group uses the existing responsive sidebar, including its mobile drawer. Click Ecommerce to expand or collapse it. Its current child page is highlighted; navigating between Ecommerce pages reopens the group. Depending on your permissions it contains:

- **Storefront Products** — `/storefront/products`
- **Online Orders** — `/storefront/orders`, including existing order detail/preparation pages
- **Delivery Areas** — `/settings/storefront-delivery`
- **Pickup Locations** — `/settings/storefront-pickup`

There is no Storefront Settings link because no dedicated settings screen exists. Normal POS catalog, inventory, sales and other settings keep their existing routes. Online Orders and fulfillment configuration have moved into Ecommerce in navigation only; their rules and location access have not changed.

## Permissions

Storefront Products requires **both** `products.view` and the existing dedicated **`storefront.publish`** permission, enforced on every server request. No new permission or schema migration is required. Use the existing role/permission administration if a staff member needs authorized access. A menu entry being hidden is not the security boundary: requests without either permission receive 403. Products are scoped to the configured initial business; foreign-business IDs return 404. Publication is business-wide, like the existing catalog; selecting a stock location does not limit which locations customers can eventually see availability for.

Other menu entries retain `storefront.view-orders`, `storefront.manage-delivery`, and `storefront.manage-pickup-locations`, respectively. Existing order location restrictions and fulfillment configuration authorization remain enforced by their controllers.

## Find a product

Search by POS product name or a variant SKU/barcode, then click **Apply filters**. You can also filter category, brand, actual product type, and publication state. The table loads 15 products per page; use the page links below it. **Clear** removes filters.

The product source is the existing POS catalog. This screen cannot create products, variants, prices, stock or devices. The SKU column shows a representative variant SKU; search checks all variant SKUs/barcodes.

Statuses mean:

- **Published**: a publication is saved and the product satisfies current public catalog eligibility.
- **Not Published**: eligible, but no published listing exists (absent listing or draft).
- **Ineligible**: the existing catalog would hide this product; the actual reasons appear below the status. A previously saved publication may still exist, but the product remains hidden while ineligible. You can unpublish it.

The Published and Not Published filters cover eligible products; Ineligible covers products that fail eligibility regardless of their saved publication status.

## Publish one selected product

1. Locate the exact product and check its name, SKU, category and type.
2. For an eligible unpublished product, review its **Public slug**. A suggestion derives from its name plus product ID; edit it if needed. Existing slugs are reused. Slugs accept lowercase letters/digits separated by single hyphens, up to 180 characters, unique within the business.
3. Click that row's **Publish** button.
4. Read the confirmation naming that product, then choose **Publish product**. Cancel makes no change.
5. A success toast confirms saving. The table shows Published and a publication timestamp. A row may disappear if your current filter excludes its new state. Validation failures appear in the error box with the selected product's name.

Only that parent product is changed. Repeated publishing updates the same existing `web_product_listings` record; it does not create duplicates. Existing public title/description/SEO overrides are preserved by these status actions. Their existing editing controls remain on the normal product detail page; this screen does not duplicate product management.

**Activating a product in POS does not automatically publish it to Ecommerce.**

## Unpublish one product

Find the product (clear filters if necessary), click its **Unpublish** button and confirm **Unpublish product**. The publication becomes draft, its slug and existing public copy remain available for later republication, and subsequent public catalog requests hide it. Orders and their historical snapshots are unchanged. An open browser page must be refreshed to show the new state.

Publication changes are recorded in the existing Product activity history as `updated publication`, with actor and changed publication fields. Repeating an unchanged action does not create a second change entry.

## Eligibility, variants, prices and stock

Eligibility matches the existing catalog query:

- Product active and not a service/labour product.
- Category active.
- Brand active if one is assigned.
- At least one active variant.
- A published listing with a valid unique slug is additionally required for public visibility.

An image is **not** required. Zero stock is **not** a publication rejection. These are not new eligibility conditions.

Publication is at the **parent product** level. All active variants are exposed together; inactive variants remain hidden. Individual variant publication controls do not exist in this model. A saved parent publication becomes hidden if all its variants become inactive. Each returned variant retains its name, condition, public identifying attributes and actual server-derived price.

Images use the existing approved POS product image path. Missing or invalid image paths show the existing storefront placeholder. The Laravel public storage link and file must exist for an uploaded image to load; publishing does not upload or generate images.

The catalog uses current POS variant selling prices. Used serialized devices use the existing lowest sellable device price, including the existing variant-price fallback where applicable; these are starting prices, not an invented final device allocation. Missing used-device prices are shown as requiring confirmation. Existing optional public copy overrides affect display text only.

Availability remains advisory and comes from Laravel's existing active-location stock and reservation-aware device rules. Out-of-stock products can be published; publication does not make them purchasable or bypass checkout checks.

**Publishing a product to Ecommerce does not create new inventory or change its POS stock.** It does not allocate IMEIs, reserve stock, change locations, change selling prices, or create an order/sale/payment. Order submission remains disabled.

## Verify on the public website

After an authorized operator selects and publishes a real product:

1. Open `http://localhost:3000/shop` and refresh. Search its public name and confirm its actual name and selling/starting price against the POS record and variant/device pricing rules.
2. Open the product card; check the detail, active variants, identifying attributes, image or placeholder, and advisory availability.
3. Check category/brand filters and sorting with the published records available.
4. If desired, read `http://localhost:3000/api/storefront/v1/products` or `/api/storefront/v1/products/{slug}`. These same-origin proxies call the existing Laravel `/api/storefront/v1` catalog endpoints. The browser must not use internal Laravel API URLs.
5. Unpublish the selected product, refresh shop/detail, and confirm it disappears from new catalog results and its detail is not found.

Both Next catalog fetches and the proxy use `no-store`, replacing the previous 60-second catalog revalidation. A refresh/new navigation reads current publication eligibility; an already rendered page does not update live automatically. Public pagination responses expose counters without internal Laravel pagination URLs.

## Local configuration and startup

Keep the current Laravel database credentials and application key. Keep `STOREFRONT_ORDER_SUBMISSION_ENABLED=false`; no new database migration is required by this phase.

Ecommerce `.env.local` may override the existing settings, without overwriting unrelated values:

```dotenv
STOREFRONT_API_URL=http://127.0.0.1:8000/api/storefront/v1
STOREFRONT_SITE_ORIGIN=http://localhost:3000
NEXT_PUBLIC_STOREFRONT_API_URL=http://127.0.0.1:8000/api/storefront/v1
```

`STOREFRONT_API_URL` is server-only and is preferred for Laravel calls. The existing public-prefixed variable remains the public image-origin configuration and a compatibility fallback for an unchanged local environment. Never put credentials/private production hosts into it; configure it to the publicly reachable approved media origin. `STOREFRONT_SITE_ORIGIN` is the actual reachable Next origin, required in production and defaulting to localhost:3000 in development. It is also used by existing mutation origin checks. Restart Next if changing environment variables. In production, the Next runtime must be able to reach its own configured site origin as well as Laravel.

Use separate terminals if the applications are not already running:

```bash
cd /home/milan/projects/Pos/NalakaPos
php artisan serve --host=127.0.0.1 --port=8000
```

```bash
cd /home/milan/projects/Pos/Ecommerce
npm run dev -- --hostname localhost --port 3000
```

The admin Vue assets were built with `npm run build` in NalakaPos. For active admin development use its existing `npm run dev` script instead. Do not run setup/seeds/reset commands to start either application.

## Common issues

| Symptom                                                    | Explanation / action                                                                                                                                            |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ecommerce or Storefront Products missing                   | Check both required permissions; do not bypass authorization.                                                                                                   |
| 403 / login redirect                                       | Staff session or permission missing/inactive; sign in and ask the authorized role administrator.                                                                |
| Inactive product/category/brand                            | The table gives the reason; use the existing POS management workflow if the business approves changing it, then retry publication.                              |
| No active variant / service product                        | Catalog excludes it under existing rules; publication cannot override this.                                                                                     |
| Slug already taken or invalid                              | Choose a valid unique slug for the selected product. No automatic unrelated product update occurs.                                                              |
| Foreign product ID                                         | Server returns 404; products must belong to the configured business.                                                                                            |
| Empty catalog                                              | Confirm an operator has actually published an eligible product. Active POS status alone is insufficient.                                                        |
| Catalog unavailable / invalid filters                      | Proxy preserves an error status. Check the configured Next origin, Laravel URL and service availability; correct filters. No fake empty catalog is substituted. |
| Missing image                                              | Check original product image and Laravel public storage configuration; the product itself can still be listed.                                                  |
| Published but unavailable                                  | Publication and stock availability are separate; use existing inventory operations only when business-authorized.                                               |
| No delivery/pickup choices or order submission unavailable | Existing fulfillment data/operational configuration may be absent. Submission is deliberately disabled in this phase.                                           |

This agent did not select or publish working products. During final verification, seven eligible published records appeared independently. Their real shop/detail HTML, product names, price, variant, filters and pagination were checked through Next.js. All seven currently have no image configured and show the placeholder. Browser interaction/console, real uploaded-image loading and a live operator unpublish cycle remain manual acceptance steps; isolated tests do not replace those checks.
