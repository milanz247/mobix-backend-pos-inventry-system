# 8. Inventory and IMEI devices

## Inventory overview and stock by location

Open **Inventory → Overview** (`inventory.view`) for total quantity on hand, serialized devices in stock, tracked variants, authorized locations, location summaries, and recent quantity movements. Choose **View stock by location** for the detailed list. Search by product/SKU/barcode and filter by location, tracking type, category, or brand. Pagination controls move between result pages.

The overview explicitly indicates when no reorder threshold is configured; in that case it does not display low-stock alerts. This version has no configured low-stock threshold editor/alert workflow.

## Stock receiving

1. Open **Inventory → Stock receiving** (`inventory.receive`).
2. Select **Destination location** and **Product variant**. Confirm the displayed tracking mode.
3. For quantity-based stock, enter **Received quantity** and optional **Unit cost**. For serialized stock, add each device and enter IMEI 1, optional IMEI 2/serial, condition, unit cost, and optional selling price.
4. Enter optional reference, reason, and notes. Use **Review** then confirm the operation.
5. On success, stock and movement records are posted for that location. Confirm via **Overview**, **Stock by location**, or **Movement history**.

Serialized IMEIs are validated for uniqueness across both IMEI slots. Receiving is a direct stock operation; when recording a supplier invoice with full supplier/accounting context, use a purchase instead.

## Opening stock

Use **Inventory → Opening stock** (`inventory.opening`) for initial stock setup, not routine deliveries. Select location/variant and enter quantity or individual device data as above, plus a required reason (defaulted to “Initial inventory setup”). Review and confirm. This creates an auditable opening operation.

## Adjustments

Open **Inventory → Adjustments** (`inventory.adjust`). Select location and variant, choose **Increase stock** or **Decrease stock**, enter quantity, select a reason (physical count, damaged goods, missing stock, data correction, or other authorized reason), and optionally add reference/notes. Review/confirm. Do not use adjustments to hide unexplained differences; investigate movements first. Decreases cannot make stock negative, and serialized inventory is managed through individual device status/workflows rather than free-form quantity changes.

## Transfers

Open **Inventory → Transfers** (`inventory.transfer`). Choose **Source location** and **Destination location**. Add quantity-based variants and quantities under **Quantity items**; select exact serialized devices in the device list. Enter required **Transfer reference**, reason, and notes. Choose **Review transfer**, verify the locations/items, then **Confirm transfer**. Both sides commit together; any validation failure rolls back the complete transfer. Source stock must be available and the locations must be accessible.

## IMEI lookup and status

Open **Inventory → IMEI lookup** (`serialized-devices.view`). Search by IMEI, serial, SKU, or product; filter by authorized location and status. Expand recent events to inspect the status/location trail. With `serialized-devices.manage`, use **Mark repair** or **Return to stock** for an eligible device. These controls only record an inventory device status event: they do not create a repair job, assign a technician, quote work, or consume parts. Sold devices cannot be marked through this action.

## Movement history

Open **Inventory → Movement history** (`stock-movements.view`). Search by product or SKU, then filter by authorized location and movement type. The table provides stock movement records and their references/locations. Check it when counts differ: confirm location, variant tracking mode, and whether a transaction is draft or posted. Sales, posted purchases, receiving, opening stock, adjustments, returns, and transfers have different movement types. Historical transactions remain associated with their original location.
