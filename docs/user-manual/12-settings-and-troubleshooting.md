# 12. Settings and troubleshooting

## Settings overview

Open **Settings** from the sidebar. Available sections are **Business settings**, **Invoice settings**, **Locations**, and **Security**. The settings landing route sends users without business-settings access to Security. Editing business/invoice data requires `business-settings.update`; viewing requires `business-settings.view`. Location access uses location permissions. Security settings are available to signed-in users for their own password/security operations.

## Invoice designer

Open **Settings → Invoice settings**.

1. Set **Document title**, **Default print format** (A4 invoice or Thermal receipt), and **Brand accent** color.
2. Choose what appears: **Business logo**, **Contact details**, **Customer information**, **Cashier name**, **Payment details**, and **Tax**.
3. Enter the customer-facing footer message and return-policy wording in their respective text fields.
4. Review the live preview. It is a preview of configured receipt content, not a posted transaction.
5. Select **Save changes**. Validation errors remain beside the relevant field. Reopen an existing sale and choose **Print receipt** to print using the saved template.

Tax display can be toggled, but checkout currently records zero tax; a display preference does not configure tax calculation.

## Common problems

- **A menu or action is missing:** request the specific permission from an administrator. Also check whether the assigned role itself can be edited and whether the route requires multiple permissions.
- **Location is not listed:** ask an administrator to assign your account to it. Do not share accounts.
- **Product not available in POS:** check product/variant status, location stock, tracking mode, and the selected location. Combo availability depends on components.
- **Insufficient stock:** inspect **Stock by location** and **Movement history**. Use a valid receiving/purchase/transfer workflow; do not edit database records.
- **IMEI rejected or already exists:** check the digits and both IMEI slots; an identifier may already belong to another device in this business.
- **Sale payment rejected:** total payments cannot exceed sale total. A remaining balance requires `sales.credit`.
- **Purchase cannot be paid or returned:** only posted purchases accept supplier payments or returns. Draft purchases must be posted first.
- **Sale return/refund denied:** check return/refund permissions and remaining returnable quantity/paid amount.
- **Validation error:** keep the form open, read the field-specific message, correct the value (required fields, formats, totals, unique references/identifiers), and resubmit.
- **Invitation/reset email not received:** verify the email address and contact the administrator. Delivery depends on deployment mail configuration.
- **Print layout is incorrect:** check A4 vs thermal format, browser print settings, paper selection, and printer configuration.
