# 9. Suppliers and purchases

## Suppliers

Open **Purchasing → Suppliers**. With `suppliers.create`, choose **Add supplier**, enter the supplier name and the form's contact person, phone, alternate phone, email, address, city, and notes as applicable, then save. Search the list and open a supplier to inspect its details and linked purchases. Use **Edit** with `suppliers.update`; activate/deactivate requires `suppliers.deactivate`. A supplier must be active and belong to the business to use it for a purchase.

## Create and post a purchase invoice

1. Open **Purchasing → Create purchase** (`purchases.create`).
2. Select an active **Supplier**, authorized **Location**, enter the supplier **Invoice number** and **Invoice date**, and optional notes.
3. Add product variants. Enter quantities and unit costs, line discounts where applicable. Serialized phone lines require each device's IMEI identifiers, condition, unit cost, and optional selling price.
4. Review subtotal, discounts, and total, then save. This creates a **Draft** purchase. A draft can be edited by a user with `purchases.update`; it does not add stock and cannot receive a payment.
5. Open the purchase detail and check invoice lines, supplier, location, and totals. Select **Post invoice** when ready (`purchases.post` and `inventory.receive` are both required).
6. Posting creates the inventory receipt and changes status to posted. Quantity stock is added to that location, and serialized devices are registered. Posting is not a draft save; verify the resulting stock/movements.

The purchase list can search invoice/supplier and filter purchase status, payment status, and location. It shows invoice, supplier, date/location, lines, total, paid amount, purchase status, and payment status.

## Record supplier payment

On a posted purchase, users with `purchase-payments.create` can enter a payment date, amount, method, optional reference, and notes. Save the payment and verify it appears in the purchase detail. Payments cannot exceed the outstanding amount. Draft purchases do not accept payments. A purchase's **Paid** total is distinct from its inventory receipt.

## Purchase returns

For a posted purchase only, open its detail and choose the return action (`purchase-returns.create`). Enter return number/date, return location, notes, and quantities or exact serialized device(s) being returned. Submit the return. A posted purchase return creates a stock return operation and updates the purchase status/balance calculations. You cannot return more than the unreturned quantity/devices, return the same line twice in one submission, or return a draft purchase. Review the return through the purchase detail and stock movement history.
