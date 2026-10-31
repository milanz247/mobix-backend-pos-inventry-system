# Function permissions and roles

The role matrix exposes implemented permissions. Select View, Add, Edit, Delete and the named actions, save the role, then assign it to users. Role, object-ownership and location checks still apply to every protected request.

New delete/status/damage/credit capabilities are independent. Existing assignments are migrated from their previous broader permission so staff do not lose established access. Later role changes remain under administrator control. Owner is protected; system roles cannot be deleted and custom roles must be unassigned before deletion. Prerequisites are added and checked on both client and server. Posted invoices and movement history are not made deletable by a checkbox.

| Module             | Function                           | Column  | Permission                  | Action                             |
| ------------------ | ---------------------------------- | ------- | --------------------------- | ---------------------------------- |
| Dashboard          | Dashboard                          | view    | `dashboard.view`            | View                               |
| Cashier Shifts     | Cashier Shifts                     | view    | `shifts.view`               | View                               |
| Cashier Shifts     | Cashier Shifts                     | actions | `shifts.open`               | Open                               |
| Cashier Shifts     | Cashier Shifts                     | actions | `shifts.movements`          | Movements                          |
| Cashier Shifts     | Cashier Shifts                     | actions | `shifts.close`              | Close                              |
| Cashier Shifts     | Cashier Shifts                     | actions | `shifts.view-all`           | View all                           |
| Cashier Shifts     | Cashier Shifts                     | actions | `shifts.approve`            | Approve                            |
| Cashier Shifts     | Cashier Shifts                     | actions | `shifts.correct`            | Correct                            |
| Users              | Users                              | view    | `users.view`                | View                               |
| Users              | Users                              | add     | `users.create`              | Add                                |
| Users              | Users                              | edit    | `users.update`              | Edit                               |
| Users              | Users                              | delete  | `users.delete`              | Delete                             |
| Users              | Users                              | actions | `users.assign-roles`        | Assign roles                       |
| Roles              | Roles                              | view    | `roles.view`                | View                               |
| Roles              | Roles                              | add     | `roles.create`              | Add                                |
| Roles              | Roles                              | edit    | `roles.update`              | Edit                               |
| Roles              | Roles                              | delete  | `roles.delete`              | Delete                             |
| Permissions        | Permissions                        | view    | `permissions.view`          | View                               |
| Business Settings  | Business Settings                  | view    | `business-settings.view`    | View                               |
| Business Settings  | Business Settings                  | edit    | `business-settings.update`  | Edit                               |
| Locations          | Locations                          | view    | `locations.view`            | View                               |
| Locations          | Locations                          | add     | `locations.create`          | Add                                |
| Locations          | Locations                          | edit    | `locations.update`          | Edit                               |
| Locations          | Locations                          | actions | `locations.assign-users`    | Assign users                       |
| Products           | Products                           | view    | `products.view`             | View                               |
| Products           | Products                           | add     | `products.create`           | Add                                |
| Products           | Products                           | edit    | `products.update`           | Edit                               |
| Products           | Products                           | actions | `products.deactivate`       | Activate / deactivate              |
| Categories         | Categories                         | view    | `categories.view`           | View                               |
| Categories         | Categories                         | add     | `categories.create`         | Add                                |
| Categories         | Categories                         | edit    | `categories.update`         | Edit                               |
| Categories         | Categories                         | actions | `categories.deactivate`     | Activate / deactivate              |
| Brands             | Brands                             | view    | `brands.view`               | View                               |
| Brands             | Brands                             | add     | `brands.create`             | Add                                |
| Brands             | Brands                             | edit    | `brands.update`             | Edit                               |
| Brands             | Brands                             | actions | `brands.deactivate`         | Activate / deactivate              |
| Variants           | Variants                           | actions | `variants.manage`           | Manage                             |
| Bundles            | Bundles                            | actions | `bundles.manage`            | Manage                             |
| Inventory          | Stock overview / location balances | view    | `inventory.view`            | View                               |
| Inventory          | Stock receiving                    | actions | `inventory.receive`         | Receive                            |
| Inventory          | Opening stock                      | actions | `inventory.opening`         | Opening                            |
| Inventory          | Stock adjustments                  | actions | `inventory.adjust`          | Adjust                             |
| Inventory          | Stock transfers                    | actions | `inventory.transfer`        | Transfer                           |
| Serialized Devices | Serialized Devices                 | view    | `serialized-devices.view`   | View                               |
| Serialized Devices | Serialized Devices                 | actions | `serialized-devices.manage` | Manage                             |
| Stock Movements    | Stock Movements                    | view    | `stock-movements.view`      | View                               |
| Suppliers          | Suppliers                          | view    | `suppliers.view`            | View                               |
| Suppliers          | Suppliers                          | add     | `suppliers.create`          | Add                                |
| Suppliers          | Suppliers                          | edit    | `suppliers.update`          | Edit                               |
| Suppliers          | Suppliers                          | actions | `suppliers.deactivate`      | Activate / deactivate              |
| Purchases          | Purchases                          | view    | `purchases.view`            | View                               |
| Purchases          | Purchases                          | add     | `purchases.create`          | Add                                |
| Purchases          | Purchases                          | edit    | `purchases.update`          | Edit                               |
| Purchases          | Purchases                          | actions | `purchases.post`            | Post invoice                       |
| Purchases          | Supplier payments                  | add     | `purchase-payments.create`  | Record payment                     |
| Purchases          | Purchase returns                   | add     | `purchase-returns.create`   | Create return                      |
| POS & Sales        | POS checkout                       | view    | `sales.pos`                 | Access POS                         |
| POS & Sales        | Sales invoices                     | view    | `sales.view`                | View                               |
| POS & Sales        | Sales invoices                     | add     | `sales.create`              | Add                                |
| POS & Sales        | Sales invoices                     | actions | `sales.price-override`      | Override prices                    |
| POS & Sales        | Sales invoices                     | actions | `sales.discount`            | Apply discounts                    |
| POS & Sales        | Credit sales                       | add     | `sales.credit`              | Allow credit sales                 |
| POS & Sales        | Customer payments                  | add     | `sale-payments.create`      | Record payment                     |
| POS & Sales        | Sales returns                      | add     | `sale-returns.create`       | Process returns                    |
| POS & Sales        | Customer refunds                   | add     | `sale-refunds.create`       | Issue refunds                      |
| POS & Sales        | Cost and profit information        | view    | `sales.costs.view`          | View costs and profit              |
| Products           | Products                           | delete  | `products.delete`           | Delete unused record               |
| Categories         | Categories                         | delete  | `categories.delete`         | Delete unused record               |
| Brands             | Brands                             | delete  | `brands.delete`             | Delete unused record               |
| Suppliers          | Suppliers                          | delete  | `suppliers.delete`          | Delete unused record               |
| Purchases          | Purchases                          | delete  | `purchases.delete`          | Delete draft invoice               |
| Invoice Settings   | Invoice Settings                   | view    | `invoice-settings.view`     | View                               |
| Invoice Settings   | Invoice Settings                   | edit    | `invoice-settings.update`   | Edit                               |
| POS Settings       | POS Settings                       | view    | `pos-settings.view`         | View                               |
| POS Settings       | POS Settings                       | edit    | `pos-settings.update`       | Edit                               |
| Damaged Stock      | Damaged Stock                      | view    | `damaged-stock.view`        | View                               |
| Damaged Stock      | Damaged Stock                      | add     | `damaged-stock.create`      | Move stock to damaged              |
| Damaged Stock      | Damaged Stock                      | actions | `damaged-stock.repair`      | Return repaired stock              |
| Damaged Stock      | Damaged Stock                      | actions | `damaged-stock.write-off`   | Write off damaged stock            |
| POS & Sales        | Credit sales                       | view    | `credit-sales.view`         | View credit invoices               |
| POS & Sales        | Credit sales                       | actions | `credit-sales.add-items`    | Add items to credit bill           |
| Customers          | Customers                          | view    | `customers.view`            | Find saved customers               |
| Customers          | Customers                          | add     | `customers.create`          | Save new credit customer           |
| POS & Sales        | Sales invoices                     | actions | `sales-receipts.print`      | Print sale receipt                 |
| POS & Sales        | Sales returns                      | actions | `return-receipts.print`     | Print return / replacement receipt |
| POS & Sales        | Warranty replacements              | add     | `sale-replacements.create`  | Issue replacement                  |
| Users              | Users                              | actions | `users.change-status`       | Activate / deactivate              |
| Locations          | Locations                          | actions | `locations.change-status`   | Activate / deactivate              |
