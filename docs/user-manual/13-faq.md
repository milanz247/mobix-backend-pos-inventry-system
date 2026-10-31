# 13. Frequently asked questions

## Why is a new product missing from available stock?

Catalogue creation defines the product and variants. It does not create inventory. Record opening stock, stock receiving, or a purchase and post the purchase at the correct location.

## Does the system have a customer list?

No separate customer-management pages or customer database workflow were found. POS lets the cashier enter optional customer name, phone, and email directly on the sale.

## What happens to stock after checkout?

Successful checkout posts the sale and inventory deduction together at the selected location. Serialized items are tied to the device identifier; combo sales consume component stock. A rejected checkout does not post a partial sale.

## Does a held cart reserve stock?

Held carts are saved on the server for their location, so authorized cashiers can resume them at another counter. They are not posted sales and do not reserve stock. Once resumed, the current cart uses browser session storage; hold it again before handing it over.

## Can a cashier accept a partial payment?

Only if their role has `sales.credit`. The unpaid portion remains outstanding. Later payments can be recorded against the sale up to the current outstanding balance by users with payment permission.

## Does every return automatically refund cash?

No. Return value and refund are separate. A refund requires refund permission and cannot exceed the eligible paid amount. Review the return and refund fields before confirming.

## Does the app manage repair jobs?

No. It can mark an eligible serialized device **In repair** and later **Return to stock**, with a status history. There are no repair tickets, job cards, technician assignments, estimates, parts usage, or repair payments.

## Can I export reports?

Sales, out-of-stock, damaged-stock and expense reports include Download filtered CSV. Downloads preserve the selected filters and access permissions and include all matching rows.

## Why are the sales and cash figures different?

Sales is transaction value. Cash received counts cash payment records only; payments by card/other methods are listed separately in reports. Returns, refunds, and outstanding balances are distinct measures.
