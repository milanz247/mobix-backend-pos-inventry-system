# 11. Reports

## Sales reports

Open **POS & Sales → Sales reports** (`sales.reports.view`). Choose **From**, **To**, and **Location**; **All accessible locations** includes only locations assigned to your account. Select **Apply**. The report displays daily sales (date, transaction count, gross sales), payments by method (applied amount and cash change), and product sales (product, SKU, quantity sold, gross line total) for the selected range. Use the tables to review the results.

This report page has no export control in the current UI. It reports gross sales/payment/product totals, not net profit. The dashboard's gross profit measure uses sale cost snapshots and returned costs, but excludes operating expenses and should not be treated as full accounting profit.

## Sales history filters

**POS & Sales → Sales history** is an operational search, not a separate aggregate report. Filter by invoice/customer/SKU/barcode/IMEI, date range, location, cashier, payment status, and sale status. Open a row to view details and payment/return actions allowed to your role. Use pagination to continue through results.

## Damaged stock report

Open **Reports → Damaged stock report**. Filter by location, category, brand, model number, stock type, or product/SKU/barcode search. The report consolidates remaining damaged quantities into one row per SKU and location and includes product/variant, model, barcode, category, brand and unit. Category/brand summaries show SKU counts, quantity items and IMEI-device counts separately. Repaired and written-off items are excluded from the current balance. Cost values are visible only with `sales.costs.view`; quantity damage uses the recorded unit-cost snapshot, and devices use their recorded cost.

Choose **Print report** to open an A4 landscape list of every matching row, including rows beyond the current screen page. Verify the selected filters in its header, then print or save as PDF. Manage individual damaged lots from **Inventory → Damaged stock**. The management page has current-stock cards, category/brand/model filters, separate quantity and IMEI tabs, and a **Move stock to damaged** action.

Sales activity is attributed to its transaction time: a later credit-bill addition belongs to the addition date, and a warranty replacement's equal sale/return values cancel on the replacement date. These events no longer inflate the original sale date. Cash collections/refunds use their own payment dates and cashier shifts.
