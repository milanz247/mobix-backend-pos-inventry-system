# 7. POS and sales

POS access requires `sales.pos`. Completing checkout also requires `sales.create` and `sales.view`. Discounts, price overrides, and credit balances need their respective permissions.

## Complete a sale

1. Open **POS & Sales → POS checkout** and confirm the selected **Location**. The product catalogue and stock availability correspond to that location.
2. Search by product, variant, SKU, barcode, or scan into the search field. **Category** and **Brand** filters narrow the catalogue. A scanned SKU/barcode can add a match directly. For serialized stock, search/scan an IMEI or serial identifier to select the exact device.
3. Select an available variant to add it to the cart. Quantity-based items can have their quantity changed. Serialized items represent a specific physical device and require the correct identifier. Use the line remove control to remove an unsaved item.
4. If a cart must wait, use the hold-cart control and select it later from the held-cart selector. Held carts are stored in this browser's local storage, per cashier, for up to seven days; they are not server-side sales and do not reserve stock. Clearing the cart only removes the unsaved cart.
5. In **Customer & notes**, optionally enter customer name, phone, email, and notes. Credit checkout includes a searchable saved customer picker. New credit customers are saved by phone number for reuse. If left blank, the receipt identifies a walk-in customer.
6. Choose the **Sale source** (In-store POS, WhatsApp, Facebook, Phone, or Other as offered). Apply line/order discounts only if permitted. Override prices only if the account has price-override access. Discounts cannot exceed the applicable line/cart total.
7. Review quantities, prices, discounts, and total. Tax is currently recorded as zero; no tax is calculated by checkout.
8. For cash sales, enter cash received and choose **Pay cash**. For a credit sale, choose **Credit sale** below the cash controls. Select a saved customer or enter a customer name and phone (both required). Enter cash paid today, including zero for full credit, review the balance due, and choose **Complete credit sale**. Credit checkout requires `sales.credit`.
9. Review the amount due and submit/confirm checkout. Payment total cannot exceed the sale total. A short payment is rejected unless the user has `sales.credit`; if allowed, the remaining amount is recorded as outstanding.
10. On successful checkout the system records a completed sale, sale lines, submitted payments, cashier, location, source, and customer details, and posts stock deduction in the same transaction. Serialized item stock is sold by its exact IMEI; combo checkout consumes the configured component stock. A stock/validation failure prevents the transaction from partially posting.
11. Open the resulting sale or **Sales history** to verify it. From the sale detail choose **Print receipt**. Print/reprint uses the saved invoice settings and browser print dialog.

If a required product cannot be found, stop and ask a catalogue manager to create/activate it; product creation is not part of checkout. Insufficient quantity or no available device prevents adding/checkout. Receive stock or correct the selected location before retrying.

## Find a sale

Open **POS & Sales → Sales history** (`sales.view`). Search by invoice, customer, SKU, barcode, or IMEI. Filter by date range, location, cashier, payment status, and sale status. Select a sale to inspect source, customer, item lines, IMEI, totals, payment records, and returns. A sale may be partially paid; use the sale detail's payment form to record an additional payment when authorized (`sale-payments.create`). Payment cannot exceed the outstanding balance. Cost snapshots are shown only to users with the separate `sales.costs.view` permission.

## Receipt

Sale detail has **Print receipt**. The receipt screen has **Print receipt** and **Back to sale** controls. Paper format and visible fields come from **Settings → Invoice settings**. Printing is handled by the browser/printer; the app does not claim that a physical print completed.

## Credit sales and later collections

Open **POS & Sales → Credit sales** to search by invoice, customer name, or phone. Filter by date, location, cashier, or payment status. The list shows paid and outstanding amounts and retains settled credit invoices. Choose **Payments / Settle** to open a bill. Under **Record payment**, enter each payment as it is received, or choose **Use full balance / Final payment** and save to settle the bill. Each collection keeps its date, receiving user, method, and reference. Payment cannot exceed the balance. The status changes from unpaid to partially paid to paid automatically. Customer records and invoices belong to the current business; invoices remain restricted to accessible locations.

### Add items to an existing credit invoice

From **Credit sales**, open an unpaid bill and choose **Add items to this bill**. The POS shows the original invoice and customer. Add only the new items to the cart, choose **Add items to credit bill**, review the previous balance and new items, then confirm. The invoice number and previous payments stay the same, and only the new items are deducted from stock. Fully settled bills and bills with returns require a new sale.

In credit checkout, **Find customer** shows matching saved customers directly. Click a result to select it. Spaces and common punctuation in phone numbers are ignored when searching. Choose **Create new customer** to enter a new name and phone.
