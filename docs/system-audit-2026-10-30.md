# Transaction, damaged stock and print audit — 2026-10-30

The full application test suite passed: **316 tests, 3,617 assertions**. This checks the application's recorded behavior; it is not a guarantee against every possible production failure.

## Corrected behavior

- Each return or replacement has its own printable document, linked to its original invoice. It includes SKU/model/IMEI, returned item disposition, replacement details, refund and invoice balance.
- New return documents retain the net invoice value, net payments and outstanding balance at the time of the return. Later collections do not change those figures. Legacy documents without those snapshots explicitly show the current balance.
- Sale receipts show return credits, replacement history, returned quantities and the balance after returns/refunds.
- Later credit-invoice additions and warranty replacements are attributed to their transaction dates. The original sale day no longer absorbs those later values. A replacement's equal issue and return values cancel on the replacement day.
- Addition/replacement activity is linked to the cashier shift that processed it. Actual cash collections and refunds continue to use their own shift/payment records.
- Sold and supplier-returned IMEI devices cannot be made saleable through an inventory status adjustment.
- Credit sales has a credit-specific description, aligned payment/return controls, separate paid/due figures and mobile invoice cards. Settled invoices show **View payments**.
- The damaged management screen now has stock cards, category/brand/model filters, separate quantity/IMEI views, a searchable move-stock dialog, and mobile cards with directly accessible actions.
- Reports → Damaged stock report consolidates the same SKU at the same location, with category, brand, model, barcode, quantity/unit and permission-protected cost values. Category and brand summaries are available. Printing includes every matching row, not just the current page.

## Verified transaction cases

- Available quantity decreases when shop stock moves to damaged stock.
- Faulty customer returns increase damaged stock without increasing saleable stock.
- A replacement deducts only its new quantity or selected IMEI from available stock; the original item remains damaged.
- Replacement values retain original discounts, payments and outstanding credit balances.
- Insufficient replacement stock rolls back the complete return, replacement, damage receipt and invoice update.
- Repeated requests do not duplicate sales, returns, replacements, damage receipts or repairs.
- Repairs restore only the selected remaining damaged quantity. Write-offs never increase saleable stock. Excess quantities are rejected.
- Reports exclude resolved damage and unauthorized locations, keep distinct SKUs separate, and hide costs without the relevant permission.
- Print endpoints reject returns that do not belong to the selected invoice.

The first full-suite run identified three stale test expectations: logout already redirects to login, the current dashboard no longer contains the old payment-method/top-product widgets, and the credit-shift fixture needed the now-required customer details. These fixtures/assertions were aligned with the existing application contract; the financial and stock assertions remain in place.

## UI and print verification

Production-built Vue pages were rendered in headless Chromium with synthetic preview records. A4 and 58/80 mm replacement receipts, a 60-row complete damaged report, desktop management screens and a 390 px mobile layout were inspected. Browser errors and horizontal document overflow were checked. Table-heading overlap and narrow-receipt spacing found during that review were corrected. PDFs/screenshots were generated under `/tmp/pos-print-check/artifacts`.

TypeScript, frontend lint for the changed files, PHP formatting, production build, and targeted PHPStan checks for the changed report/transaction services passed. Project-wide PHPStan still has existing purchasing/inventory type-annotation issues outside those targeted checks; a globally clean static-analysis result is not claimed.

## Data and hardware limits

The local database migrations were applied. Historical damaged quantity entries without a cost snapshot were initialized from the returned line's cost where available, otherwise from the variant's catalogue cost; new entries store the cost at recording time. Historical additions without an unambiguous line/shift link retain the available historical data rather than guessing a cashier shift.

Browser/PDF output was verified. A physical printer, driver settings, paper feed and cutter were not available for testing; select the matching paper size in the printer dialog.

## Function-permission follow-up

The application now defines **95 function permissions** with module, function, View/Add/Edit/Delete/action category, description and prerequisite metadata. The role matrix, permission reference, route gates, action checks and visible controls use the same definitions. A migration copies the equivalent existing role/direct-user grants into the new independent permissions; it does not reset customized roles.

The incorrect checkbox model binding in the old role form was replaced with an explicit selected-ID form payload. Checked state, clear-and-save with an empty list, automatic prerequisites, removing dependent actions and mobile document overflow were verified in Chromium. Owner access remains protected. Limited administrators cannot grant missing prerequisites, add permissions to their own role, delete system roles or delete assigned roles. Unknown custom permission definitions are displayed safely.

Additional tests cover independent delete/deactivate, preventing status changes through forged edit payloads, separate repair/write-off permissions, and independent view/print/report access. Legacy workflow fixtures now explicitly grant their equivalent split permissions; dedicated negative tests use exact minimal assignments. The final full suite passed **316 tests with 3,617 assertions**, including the report-only-link test. Project-wide frontend formatting and lint checks, TypeScript, production build and targeted permission PHPStan checks passed. The permission upgrade migration was applied to the local database.
