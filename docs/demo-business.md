# Mobix Mobile demo business

The local demo includes 30 products, 44 sellable variants, seven categories,
four locations, opening stock, sample purchases and sales, 30 published web
listings and two homepage banners (one active). Prices and product specifications
are illustrative fixtures in LKR. AI-generated photographs are illustrative,
not evidence of authentic branded stock.

Set these values in your untracked `.env` before creating the business:

```dotenv
DEMO_OWNER_EMAIL=milanmadusankamms@gmail.com
DEMO_MILAN_PASSWORD=<your local demo password>
```

The owner password has no committed default. An existing account's password,
customized prices, images and publication settings are preserved on repeat runs.

For an empty local database:

```bash
php artisan migrate --seed
php artisan storage:link
```

To replace the existing local business and all its data, first take a database
backup, then run the following **only against the disposable demo database**.
This removes every table, including existing sales, users and inventory history:

```bash
php artisan migrate:fresh --seed
php artisan storage:link
```

To add missing demo fixtures without deleting existing data:

```bash
php artisan db:seed --class=DemoDataSeeder
```

DemoDataSeeder and DemoStorefrontSeeder refuse to run in production.

## Images

Committed WebP assets live in `public/demo/`. DemoStorefrontSeeder installs copies
under `storage/app/public/product-images/demo/` and
`storage/app/public/storefront-banners/demo/` so existing POS, gallery and
storefront APIs use their normal `/storage/` URLs. Keep `public/storage` linked
to `storage/app/public`. Product images use the same neutral studio style;
banners leave space for the site's HTML headline and button.

Images were generated using the built-in imagegen tool. The complete prompt set
is recorded in `demo-image-prompts.json`. One image was generated separately for
each of the 30 products and each banner, then encoded as WebP for the repository.

## Verification

```bash
php artisan test --compact tests/Feature/DemoCatalogSeederTest.php tests/Feature/DemoDataSeederTest.php
vendor/bin/pint --dirty
```

Tests require a dedicated MySQL test database (`nalaka_mobile_test` by default)
and permission to create/drop its tables. SQLite is not a substitute because
the existing migrations use MySQL generated expressions and constraints.
