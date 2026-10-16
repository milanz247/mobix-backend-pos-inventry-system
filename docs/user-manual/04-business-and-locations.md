# 4. Business, locations, and access

## Business settings

Users with `business-settings.view` can open **Settings → Business settings**; saving requires `business-settings.update`.

1. Review or update **Business name**, **Business type**, **Business email**, **Business contact number**, **Business address**, **City / town**, **Country**, and **Currency** as applicable.
2. Upload or replace **Business logo** if desired.
3. Under **Date and time**, choose the business **Timezone** and **Time format**.
4. Select **Save changes** and resolve any field errors shown on the form.

Business name/contact/logo feed invoice and welcome branding. Currency and timezone affect how transaction amounts and dates are presented. The supported business types include mobile sales/repair options, but selecting one does not enable a repair workflow.

## Create or edit a location

1. Open **Settings → Locations**. Creating, viewing, and editing are protected by the corresponding location permissions (`locations.create`, `.view`, `.update`).
2. Select **Create location** to add one, or open a location and choose its edit action.
3. Enter **Location name**, **Location code**, **Location type**, **Status**, and any applicable contact number, email, address, and city.
4. Save the form. Location codes must be unique for the business; validation identifies missing or duplicate values.

The location list/detail pages support viewing and status changes, including deactivate/activate where authorized. Deactivation is not the same as deleting historical transactions.

## Assign users to locations

1. Open the location's user assignment page from **Settings → Locations**.
2. Select the users who need access and save. This action requires `locations.assign-users`.
3. Ask the user to sign in again or reopen the transaction page and confirm the intended location is available.

## Selecting a location for work

The POS and stock/purchase forms select a location as part of each transaction. The POS shows a location selector, initially set to an authorized location. Inventory lists and sales reports offer location filters. Always confirm the location before checkout, receiving, adjustments, transfers, or purchase posting.

Stock is maintained per location. A sale reduces stock at its selected location; receiving, opening stock, and posted purchases add it at the destination; transfers reduce the source and increase the destination. A user with no access to the requested location is denied by the server. Ask an administrator to assign access rather than retrying with another user's account.
