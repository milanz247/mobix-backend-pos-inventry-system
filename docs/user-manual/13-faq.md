# 13. Frequently asked questions

## Why is a new product missing from available stock?

Catalogue creation defines the product and variants. It does not create inventory. Record opening stock, stock receiving, or a purchase and post the purchase at the correct location.

## Does the system have a customer list?

No separate customer-management pages or customer database workflow were found. POS lets the cashier enter optional customer name, phone, and email directly on the sale.

## What happens to stock after checkout?

Successful checkout posts the sale and inventory deduction together at the selected location. Serialized items are tied to the device identifier; combo sales consume component stock. A rejected checkout does not post a partial sale.

## Does a held cart reserve stock?

No. Held carts are saved in the browser's local storage for that cashier, limited to ten recent carts and pruned after seven days. They are not posted sales and do not reserve stock.

## Can a cashier accept a partial payment?

Only if their role has `sales.credit`. The unpaid portion remains outstanding. Later payments can be recorded against the sale up to the current outstanding balance by users with payment permission.

## Does every return automatically refund cash?

No. Return value and refund are separate. A refund requires refund permission and cannot exceed the eligible paid amount. Review the return and refund fields before confirming.

## Does the app manage repair jobs?

No. It can mark an eligible serialized device **In repair** and later **Return to stock**, with a status history. There are no repair tickets, job cards, technician assignments, estimates, parts usage, or repair payments.

## Can I export reports?

The current sales report page has no export action. Use the on-screen filters and tables.

## Why are the sales and cash figures different?

Sales is transaction value. Cash received counts cash payment records only; payments by card/other methods are listed separately in reports. Returns, refunds, and outstanding balances are distinct measures.
