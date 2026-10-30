# 10. Sales returns, refunds and replacements

Open **Sales history → invoice → Process return**. Return access requires `sales.view` and `sale-returns.create`. A cash/card refund also requires `sale-refunds.create`.

1. Choose **Return / Refund** or **Replace same item — no cash refund**.
2. Enter the quantity for each sold item. The system prevents returning more than was sold and not already returned.
3. For ordinary refunds, choose the returned item's condition: **Saleable** restores available stock, while **Faulty** sends it to **Inventory → Damaged stock**. Selecting the **Faulty item** reason always sends the return to damaged stock.
4. A replacement always sends the old item to damaged stock and issues the same product and quantity from available stock. For IMEI devices, select the new replacement IMEI when required. Bundles with serialized components must be returned in full; replacement bundles consume their configured components.
5. Confirm the reason and notes, check the totals and submit. Refunds are limited to the eligible paid amount; unpaid returned value reduces the outstanding balance. A same-item replacement carries the original returned value and keeps the net invoice value, prior payments and balance unchanged. It does not create a refund.
6. Review the sale and receipt. Replacements appear as new invoice lines linked to the return; gross totals and return credits are shown separately. Replacement items can themselves be returned later if necessary.

Replacement access additionally requires `sales.create`, `shifts.view` and an open cashier shift at the original location. Replacement stock must be available. Stock, damage records and invoice changes are posted together; a validation or stock failure rolls back the entire operation. Retrying the same submission does not process it twice.

## Damaged stock

Open **Inventory → Damaged stock** (`inventory.view`) to search by product, SKU, invoice, customer or IMEI and filter by location. Quantity entries show received and remaining damaged quantities and the originating invoice where applicable. Damaged devices are listed by IMEI. Neither is available for POS sales.

With `inventory.adjust`, move shop stock to damaged by choosing the location, product, quantity and reason/notes. The system deducts available stock and records the same quantity in damaged stock. Alternatively, use **Adjustments → Decrease → Damaged**. It cannot move more than is available. IMEI moves additionally require `serialized-devices.manage`.

For a quantity entry, choose **Repair / Write off**. **Repaired** returns inspected quantity to available stock; **Write off** reduces damaged stock without increasing saleable stock. Both require notes and create an inventory audit record. For an IMEI item, open **Inspect / repair device** to use its status and audit history. Move it through repair and return it to stock only when saleable.

## Printing return and replacement documents

Under the sale's **Returns** section, choose **Print return receipt** or **Print replacement receipt** for the specific transaction. The document includes its return number, original invoice, returned items and SKU/model/IMEI, damaged/saleable disposition, replacement items issued, refunds, and invoice balance. It uses the configured A4 or 58/80 mm thermal paper setting. New returns retain the balance at the time of the transaction, so later payments do not rewrite that document. Older returns without a saved balance are explicitly labeled with the current invoice balance. Reprinting the sale receipt also includes return history and the net invoice amount.
