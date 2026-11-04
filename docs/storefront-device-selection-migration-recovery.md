# Storefront device-selection migration recovery

## Verified result

Only constraint/index names changed in
`2026_11_04_000001_create_storefront_device_selections.php`.
Generated variant/device foreign-key names were 67/69 characters, exceeding
MySQL's 64-character limit. The replacement names are:

- `sds_business_fk`
- `sds_product_business_fk`
- `sds_variant_business_fk`
- `sds_device_business_fk`
- `sds_token_hash_unique`
- `sds_expires_at_idx`

The existing explicit `storefront_device_selection_scope_idx` (37 characters)
and PRIMARY key remain unchanged. All columns, types, reference column order,
RESTRICT delete actions, uniqueness and index column order remain unchanged.
Related cart/quote migrations already use short explicit composite names.

Offline compilation with Laravel's MySQL grammar produced eight statements.
Comparing the original and corrected statements after normalizing names proved
that their definitions differ only in identifiers. Compilation used an isolated
connection whose PDO factory throws if invoked; no SQL was sent to a database.
PHP syntax, scoped Pint and `git diff --check` passed; the final diff was reviewed.
No route definitions changed, so route loading is not relevant to this correction.

## Actual database state remains unknown

`php artisan migrate:status` failed with:

```
SQLSTATE[HY000] [2002] Unknown error while connecting
Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: nalaka_mobile
```

An independent read-only information_schema table lookup failed with the same
connection error. Therefore migration ledger status, table existence, row count,
constraints and indexes could not be established. Do not interpret the connection
failure as evidence that the table is absent. No database-changing operation was
executed. No migration success is claimed.

Laravel compiles CREATE TABLE and subsequent constraint/index ALTER statements
separately. Earlier statements can survive this failure on MySQL. A partial table,
some foreign keys, and missing later indexes are possible; none are confirmed.

## Required read-only checks once connectivity is available

From `pos-backend/`:

```sh
php artisan migrate:status
```

Using an authorized MySQL client connected to the same effective Laravel target,
run these read-only queries. Verify the first result is exactly `nalaka_mobile`:

```sql
SELECT DATABASE() AS actual_database;
SELECT migration, batch FROM migrations
WHERE migration = '2026_11_04_000001_create_storefront_device_selections';
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('storefront_device_selections',
                     'storefront_device_selections_failed_20261104');
SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND (TABLE_NAME = 'storefront_device_selections'
       OR REFERENCED_TABLE_NAME = 'storefront_device_selections'
       OR CONSTRAINT_NAME IN ('sds_business_fk', 'sds_product_business_fk',
                              'sds_variant_business_fk', 'sds_device_business_fk'));
```

Only if the original table exists:

```sql
SELECT COUNT(*) AS row_count FROM storefront_device_selections;
SHOW CREATE TABLE storefront_device_selections;
SHOW INDEX FROM storefront_device_selections;
```

Also verify parent indexes `(id, business_id)` on products, product_variants and
serialized_devices, and any unrelated pending migrations. Preserve these results
for review. Do not output selection tokens or individual private inventory rows.

## Proposed recovery by verified state — approval required before writes

Do not execute any command below without explicit approval for `nalaka_mobile`.
Do not run an unrestricted migrate, migrate:fresh, or unrelated rollback.

### Migration pending and table absent

After confirming the target, parent keys and no conflicting explicit FK names,
run only this migration:

```sh
php artisan migrate --path=database/migrations/2026_11_04_000001_create_storefront_device_selections.php
```

Add `--force` only if the deployment environment is production and that exact
production execution has been explicitly approved. Verify migrate status, SHOW
CREATE TABLE and SHOW INDEX afterward. This command intentionally does not apply
the cart/quote migrations.

### Migration pending, partial table exists, exactly zero rows

First quiesce selection writes through the operational maintenance procedure and
recheck row count immediately before recovery. Confirm no incoming foreign keys,
no archive table with the name below, and no retained/schema-global FK conflicts
with the four new `sds_*_fk` names. Preserve a schema backup.

Proposed preservation approach, if all prerequisites hold and explicitly approved:

```sql
RENAME TABLE nalaka_mobile.storefront_device_selections
TO nalaka_mobile.storefront_device_selections_failed_20261104;
```

Then run the single-path migration command above and verify all columns, four FKs,
unique token index, expiry index, scope index and migration ledger entry. Renaming
retains the partial table and its constraints; it does not drop it. If any
prerequisite fails, stop and prepare a state-specific repair instead. Do not drop
the preserved table as part of this recovery.

### Table contains rows, or has incoming foreign keys

Do not drop/rename/recreate it or rerun CREATE TABLE. Review its exact SHOW CREATE
TABLE, indexes, parent keys and integrity before preparing in-place ALTER steps
for only missing constraints/indexes. Existing data must satisfy those constraints;
violations require a separate business/data decision. Migration ledger reconciliation
must be explicitly reviewed after schema equivalence is proven; no unconditional
INSERT into migrations is proposed while state is unknown.

### Migration already recorded

Do not rerun it or remove its ledger entry. Verify the live schema. A recorded
migration with missing/inconsistent schema requires a separately reviewed forward
repair, not rollback of unrelated migrations.

## Stop point

The code correction and conditional recovery procedure are ready. Database state
inspection and a concrete state-specific approval must precede recovery writes.
No business logic, selection architecture, users/grants or existing data changed.
