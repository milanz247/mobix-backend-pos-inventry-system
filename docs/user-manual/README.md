# NalakaMobile User Manual

This manual describes the application as implemented in the inspected codebase. Menu items and actions are permission-dependent, so your screen may show fewer options than described. A user must sign in to work in the business workspace.

## Contents

1. [Introduction and access model](01-introduction.md)
2. [Login and account security](02-login-and-account.md)
3. [Dashboard](03-dashboard.md)
4. [Business, locations, and access](04-business-and-locations.md)
5. [Users, roles, and permissions](05-user-management.md)
6. [Products, categories, and brands](06-product-management.md)
7. [POS and sales](07-pos-and-sales.md)
8. [Inventory and IMEI devices](08-inventory-and-imei.md)
9. [Suppliers and purchases](09-suppliers-and-purchases.md)
10. [Sales returns and refunds](10-sales-returns-and-refunds.md)
11. [Reports](11-reports.md)
12. [Settings and troubleshooting](12-settings-and-troubleshooting.md)
13. [Frequently asked questions](13-faq.md)
14. [Implementation status and audit notes](IMPLEMENTATION-STATUS.md)

## Important distinctions

- Creating a catalogue product does not add quantity to stock. Use **Stock receiving**, **Opening stock**, or post a purchase to record stock.
- POS checkout creates a completed sale, records submitted payments, and posts inventory together. A sale below its total requires the **Allow credit sales** permission.
- Customer records are not a separate module. POS accepts optional customer name, phone, and email as sale details.
- Repair registration, job cards, technician assignment, quotations, repair parts, and repair payments are not implemented. The serialized-device screen only has a limited in-repair status.

This manual has no screenshots because a running browser preview was not available during the documentation audit. Procedures below use the real menu and field labels found in the current UI.
