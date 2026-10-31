# Customer tables, inventory, reports and activity logs

## Navigation and daily work

- **Customers:** a searchable, paginated table with View, Edit and Delete actions. Add customer opens a dialog at the top; Edit opens the same populated form. Delete is available only with `customers.delete` and only for customers without invoices. Historical invoices and their customer reference are preserved.
- **Customer View:** contact and credit details appear first. **View purchases** lists purchased products, SKU/IMEI, quantities, returned quantities, invoice, branch and total. **Credit bills** lists only credit invoices with due dates, payments, balances and a link to each bill. Both histories retain branch scoping and pagination.
- **User management:** table columns wrap, actions remain visible, and rows adapt to phone widths without a horizontal table scrollbar. Existing user filters and pagination remain available.
- **Roles:** searchable table with pagination. Create role and Edit open dedicated pages with permission checkboxes. Existing permission prerequisites and restrictions on granting privileges remain enforced. The permission matrix also adapts to phone widths.
- **Inventory:** Overview, damaged stock, reorder levels and stock by location are the main views. Stock receiving, opening stock, adjustments, transfers, IMEI lookup and movement history are available from the stock-actions selector. On phones a single inventory-page selector replaces the desktop navigation. Inventory tables use wrapping columns and labeled mobile rows; the overview now has a branch stock table.
- **Settings:** Business, Invoice and POS settings show their own controls, without a repeated Workspace / Personal / Security navigation hub inside each page. Locations remain separate. My profile includes password, 2FA and passkey management. The legacy security URL remains available for existing links.

## Reports

- **Daily sales:** defaults to the current day in the business timezone. The selected shop day uses local midnight boundaries matching the application’s existing shop-local transaction timestamps. Existing invoice timestamps are not rewritten. Summary cards show new invoice count, issued sales, return credits, net sales, payments, refunds and net collections. Tabs expose individual sales/additions/replacements, sold items, payments, returns and refunds. Collections on older invoices appear on their collection day. Staff and branch filters remain scoped to accessible records.
- **All sales report:** defaults to all invoice dates, with search, date, branch, cashier and payment-status filters. Tables and summaries show invoice value after returns, payments after refunds and actual remaining debt. This is separate from the existing aggregate sales summary/export page.
- **Damaged stock:** top summary followed by Item, Category and Branch views. Each view has a table and pagination. Category and branch summaries include every matching record, not only the visible item page. Brand/model/type filters and complete print/CSV output remain available; costs require cost-view permission.
- **Cashier shift sales:** filtered, paginated shifts with net sales, collections, refunds and drawer expected/count/difference figures. Date filters refer to when the shift opened. Staff without `shifts.view-all` can see only their own shifts, and branch restrictions continue to apply.

## Activity records

The new **System activity log** and **Product activity log** record future activity from the rollout onward. Old events that were never recorded are not reconstructed.

- Ordinary application model creates, updates and deletes are recorded, including catalogue, sales, purchasing, stock movements, customers, staff and business settings.
- Catalogue save/delete snapshots include variant and bundle changes made through bulk operations. Role-permission, user-role and user-location assignments, branch user assignments and reorder-level changes are explicitly logged because pivot operations do not emit normal model updates.
- Successful page views and submitted actions are recorded; failed requests are labeled separately. Repeated partial-data polling and lookup requests are omitted. Login/logout events retain the authenticated user's identity; failed sign-in attempts are recorded without credentials.
- Records contain actor, timestamp, subject/product identity, branch context, route, request method, IP and an irreversible session fingerprint. Audit timestamps use UTC and are displayed in the shop timezone. Raw session IDs, passwords, credential material, tokens and two-factor secrets are excluded. Before/after field values are shown in expandable change details. Cost/profit fields are hidden without `sales.costs.view`.
- Audit entries made inside a transaction roll back if that transaction fails. Product IDs and name snapshots are retained after product deletion; logs have no cascading foreign keys that would erase that history. Application-level audit entries cannot be edited or deleted.
- Log viewing is controlled independently by `activity-logs.view` and `product-activity.view` (the latter also requires `products.view`). Viewing is branch scoped; unassigned business-level log records are restricted to the registered owner.

Apply the included migration with `php artisan migrate`. It adds the activity table and new `customers.delete`, `activity-logs.view` and `product-activity.view` permissions without resetting existing role assignments. The registered owner can grant these permissions through Roles. Repair-job management and backup/restore were not added.

## Verification

The final full regression run passed **341 tests with 3,973 assertions**. Project-wide PHPStan passed with zero errors. PHP formatting, frontend formatting/lint, TypeScript and the production build passed. The activity-log/permission migration was applied to the local application database.

Headless Chromium checks exercised 26 production-built pages with synthetic records at 1440 px and 390 px, including every inventory view, customer history tabs, reports and profile security. Customer/user/role tables were additionally checked at 1024 px. Tests checked horizontal overflow, customer add/edit dialogs, a dedicated role creation page, permission checkboxes, and activity before/after details. Browser artifacts are under `/tmp/pos-print-check/artifacts/interface-reports`.

Server regression tests include customer-history deletion protection, item and credit-bill filtering, report branch scope and totals, same-second additions, a real late-evening HTTP checkout, session fingerprints, credential/cost redaction, retained deleted-product history and audit transaction rollback. Existing financial timestamps were not migrated or rewritten.
