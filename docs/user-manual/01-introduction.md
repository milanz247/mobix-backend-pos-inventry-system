# 1. Introduction and access model

NalakaMobile is a business workspace for maintaining a product catalogue, tracking stock at locations, recording supplier purchases, completing point-of-sale transactions, and reviewing sales. It also includes account, role, location, business, and invoice settings.

## Getting around

After sign-in, use the left sidebar. It contains **Dashboard**, **User Management**, **Role Management**, **Product Management**, **Inventory**, **Purchasing**, **POS & Sales**, and **Settings** sections. A section is displayed only when the account has at least one corresponding permission. Items within a section are also permission-filtered. Direct access is protected on the server as well; hiding a menu item is not the only access control.

The sidebar's **POS & Sales** section provides **POS checkout** and **Sales history**. **Settings** contains **Business settings**, **Invoice settings**, **Locations**, and **Security** (some are permission gated).

## Roles and permissions

System roles include Owner, Admin, Manager, Cashier, and Technician. The permissions assigned to a role determine its actual access; custom roles can use the permissions listed under **Role Management → Permissions**. Do not assume a job title grants a particular right. For example, the default Cashier role can access the POS and sales history, but cannot process sales returns unless that permission is added.

Location access is an additional restriction. Users can only transact against locations to which they have access. Location-limited lists show authorized locations, not necessarily every business location.

## Common list behavior

Many lists provide search, filters, and pagination. Filters generally update the displayed results as you type or change a selection. Empty results do not mean a record was deleted; check the filters, search text, and location scope.
