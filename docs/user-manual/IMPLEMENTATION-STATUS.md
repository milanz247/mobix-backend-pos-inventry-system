> Updated 2026-10-31: see [shop operations improvements](../shop-operations-2026-10-31.md) for customer accounts, credit terms, stock alerts, server-held carts and tax. Repair job management and backup/restore remain excluded.

# Implementation status and documentation audit

Audit basis: route list, route middleware, Vue page/components, controllers, request validation, services, models, permission enum/seeder, and available project tests were inspected. No running browser was available, so runtime visuals and real printer/email delivery could not be tested. The guide intentionally describes current code behavior rather than proposed workflows.

## Fully implemented and code-verified

- Welcome/root routing, login, logout/session flow, password-reset screens, password update, and authenticated dashboard routing.
- Permission-filtered navigation and server-side permission middleware for catalogue, inventory, purchases, POS/sales, role/user management, and business/invoice settings.
- Business configuration, location CRUD/status and user-location assignment; location-scoped data access.
- Product/category/brand CRUD and status workflows; quantity-based, serialized, and combo/bundle catalogue data.
- Inventory overview, stock-by-location, opening stock, receiving, adjustments, transfers, serialized-device lookup/status events, and movement history.
- Supplier CRUD/status; purchase draft creation/edit, posting to inventory, supplier payments, and posted purchase returns.
- POS catalogue search/filter, cart, local held carts, customer contact fields, sale-source capture, discounts/price overrides/credit permission checks, multi-method payments, sale posting, serialized device selection, and combo component stock posting.
- Sales history/detail/receipt, later sale payments, sales returns and optional refunds, and invoice settings/live preview.
- Dashboard sales, payment, return/refund, outstanding, net-sales-trend, and cost-snapshot gross-profit calculations are defined in code and location-scoped.

## Partially implemented or limited

- **Customer operations:** customer directory, bounded POS search, normalized phone matching, credit terms and location-scoped statements are implemented. Existing historical unlinked sales are not automatically assigned to customers.
- **Repair:** serialized devices can move to/from an `in_repair` status with events. No repair-management workflow exists. A Repair centre location type and Technician role do not mean repair jobs are implemented.
- **Low stock:** location-specific reorder levels, low-stock screens and dashboard attention counts are implemented. External email/SMS notifications are not included.
- **Tax:** business settings controls the tax-inclusive rate, default zero. Checkout, returns and replacements retain per-line tax snapshots.
- **Email invitation/reset:** UI and password broker flow exist, but actual delivery could not be runtime verified and depends on mail configuration.
- **Receipt printing:** receipt rendering and browser print action exist; physical output depends on browser/printer and could not be verified in this audit.
- **Location selection:** operational forms select locations individually. There is no persistent global branch switcher in the reviewed sidebar.

## Missing features (not documented as workflows)

- Repair ticket/job card intake, technician assignment, quotations/approval, repair status pipeline, parts consumption, repair payments, warranty, and delivery.
- Product stock editing from product creation; stock is managed in inventory/purchase workflows instead.

## Runtime behavior not independently verified

- Actual deployment email delivery and reset-link arrival.
- Browser print behavior and physical A4/thermal printer compatibility.
- Production data-specific role assignments, locations, and catalogue records; the manual does not assume demo data.
- Application rendering in a live browser. No screenshots are included; they were not fabricated.

## Audit caveat

Code inspection verifies intended implemented paths, but cannot prove a particular deployment's environment, database contents, mail service, printer setup, or user-specific permissions. If a field/action differs in a deployed instance, check that deployment's version and assigned permissions before proceeding.
