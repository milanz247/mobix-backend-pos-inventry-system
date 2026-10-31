# Interface and reporting work

Implementation is complete. See [usage and access details](interface-and-report-usage-2026-10-31.md).

1. Customers: paginated table with View, Edit and Delete; top Add customer dialog; customer details followed by item purchases and credit-bill tabs. Retain financial history and prevent deleting customers referenced by invoices.
2. Users and roles: readable tables without horizontal scrolling, pagination, and a separate role creation/edit page with permission checkboxes.
3. Inventory: consistent simple page headings, navigation, filters and responsive tables across damaged stock, overview, reorder, branch stock, receiving, opening stock, adjustments, transfers, IMEI and movement history.
4. Reports: daily sales with the shop day's individual sales, items, collections, returns, refunds and totals; a separate all-sales report; damaged stock with item, category and branch views; cashier-shift sales with filters and summaries.
5. Audit: dedicated system and product activity pages recording future changes with actor, timestamp, branch and session fingerprint. Preserve deleted product identification, scope access, redact secrets and protect cost visibility. Historical events cannot be recreated if they were not recorded.
6. Settings: standalone invoice, POS and business settings pages. Keep locations separate. Put password, 2FA and passkeys within My profile, without the repeated Workspace/Personal/Security settings hub.

Verify authorization, customer deletion/history, pagination, report date boundaries and totals, activity recording/redaction and transaction rollback. Run frontend checks, static analysis, full regression tests and desktop/mobile browser checks.
