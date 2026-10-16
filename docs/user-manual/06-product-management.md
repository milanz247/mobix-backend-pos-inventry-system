# 6. Products, categories, and brands

Product management requires the relevant `products.*`, `categories.*`, `brands.*`, `variants.manage`, or `bundles.manage` permissions. **Product Management** contains **All products**, **Categories**, and **Brands**.

## Create a product

1. Open **Product Management → All products → Create product**.
2. Enter **Product name**, choose **Product type** and **Category**, and optionally choose a **Brand**, add a **Description**, set **Product status**, and upload a **Product image**.
3. Add at least one variant. For each, enter a **Variant label**, unique **SKU**, optional barcode, status, model/storage/colour/details as applicable, **Tracking mode**, unit, condition, default buying/cost price, selling price, and optional minimum selling price.
4. For a combo/bundle type, add component variants and their quantities in the bundle component section. Component choices must already exist and be valid for the bundle.
5. Review the fields and select the form's save/create button. Correct errors highlighted below fields.
6. Confirm the product in **Product Management → All products** or open its detail page. Use the list search and filters to find it.

SKUs and barcodes identify variants; keep them unique and scan-tested. **Quantity based** variants track a quantity. **Serialized** variants track individual devices. Combo products represent virtual sets of components; they are not ordinary stock items to receive as a single variant. Availability depends on component stock.

Default catalogue prices are reference prices. Actual purchase costs and serialized-device unit costs are recorded in inventory/purchase workflows. Creating a catalogue record does not add stock.

## Edit and deactivate

Use the product detail page's **Edit** action (`products.update`) to change supported details. The product list supports status change (deactivate/activate) for users with `products.deactivate`. The list can be searched and filtered by status/category/brand where shown. Deactivation prevents normal active catalogue use but preserves transaction history.

## Categories and brands

Open **Product Management → Categories** or **Brands**. Use **Create category/brand**, enter its name and any shown description/status, then save. Search the list by name and use row actions to edit or deactivate/reactivate. Create categories and brands before products if they are needed as selections. Each action is separately permission controlled.
