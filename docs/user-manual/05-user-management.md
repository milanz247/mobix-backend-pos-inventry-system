# 5. Users, roles, and permissions

## Invite or create a user

Access requires `users.create`; assigning roles also requires `users.assign-roles`.

1. Open **User Management → Invite user**.
2. Enter **Full name**, **Login email**, **Contact number**, and **Account status**.
3. Assign the appropriate **Roles**. Grant only the access required for the job.
4. Use **Email an invitation** when available to have the user set a password through the email/password-reset flow. Otherwise follow the form's password fields.
5. Save. The user appears under **All users**. Assign required locations separately through **Settings → Locations**.

## Maintain users

Open **User Management → All users** to search/view the list, edit a user, change account status, or delete where allowed. Update permission: `users.update`; deletion: `users.delete`. Deactivated users cannot use the account. Deletion is a distinct administrative action; prefer deactivation when historical accountability is needed.

## Create a role

1. Open **Role Management → Create role** (`roles.create`).
2. Enter **Role name** and optional **Description**.
3. Expand the permission groups. Tick only the actions required. **Select all** and **Clear** work within their group; the page shows selected counts.
4. Select **Create role**. The role then appears in **All roles** and can be assigned to users by someone with role-assignment access.

Edit a role from **Role Management → All roles** (`roles.update`). System roles are marked **System** and have extra safeguards. Role deletion requires `roles.delete`; a role in use may not be removable. The **Permissions** page in Role Management is a read-only catalogue (`permissions.view`), not an editor of permission definitions.

## Key permission examples

Permission names are listed in the UI, grouped by module. Examples include `sales.pos`, `sales.create`, `sales.discount`, `sales.credit`, `sale-returns.create`, `sale-refunds.create`, `inventory.receive`, `inventory.adjust`, `purchases.post`, and `locations.assign-users`. A multi-step action can require more than one permission: POS checkout requires POS access, sale creation, and sales view. A missing permission may hide a button or cause an access-denied response.

## Assign function permissions with the role matrix

Open **Role Management → All roles**, create a role or edit a non-Owner role, and use **Function permissions**. Each function has separate **View**, **Add**, **Edit**, **Delete** and **Other actions** columns. Tick only the capabilities the role needs. Missing operations appear as **—**: posted invoices, payments and stock movements keep their history and use explicit return/refund/correction actions.

Search by module or function; use **Select module**, **Select visible**, **View only** or **Clear visible** to work in bulk. Required permissions are included automatically (for example, editing products also needs viewing products and managing variants). Unticking a required permission also removes the dependent selections. **Save role permissions** applies the selection to all users assigned that role. An empty selection is valid. The Owner role remains protected with full access.

Delete is separate from activate/deactivate for products, categories, brands and suppliers. Purchase deletion applies only to drafts. Damaged stock has independent view, move-to-damaged, repair and write-off permissions. Report viewing and printing, sale/return receipt printing, credit-invoice viewing/additions, saved-customer lookup/creation, and business/invoice/POS settings are separately configurable. View-only settings do not show a save action.

Limited administrators can assign only access they already hold, including prerequisites. Users cannot add permissions to their own role, delete system roles, delete assigned roles, or remove protected Owner access. Personal profile/security remain available to the signed-in user rather than being controlled as another staff-management permission.

The permission upgrade retains the equivalent capabilities previously granted to existing roles and direct users. After the upgrade, individual checkboxes can be revoked independently; reseeding does not restore customized non-Owner assignments. Review the complete [function permission reference](../permissions-and-roles.md) for the current actions.
