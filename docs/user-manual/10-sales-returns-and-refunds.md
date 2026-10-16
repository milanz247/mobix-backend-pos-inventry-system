# 10. Sales returns and refunds

Processing a return requires `sale-returns.create`; recording a refund additionally requires `sale-refunds.create`. Return/refund actions are available from a sale detail, not as a general menu transaction.

1. Open **POS & Sales → Sales history**, locate the invoice, and open it.
2. Choose **Process return**. If the action is missing, confirm the account has return permission and the sale is accessible.
3. For each item, check sold quantity, already-returned amount, returnable quantity, and return value. Enter a return quantity. Serialized devices must match the original sale. A bundle with serialized components must be returned as a complete bundle.
4. Confirm **Return location**. It is fixed to the original sale location and disabled in the form. Select a **Reason** (Customer return, Faulty item, Incorrect item, or Other), and add optional notes.
5. Review **Return total** and **Refundable paid amount**. When refund permission is present, choose refund method and enter an amount not exceeding the eligible paid amount; add an optional reference. Without refund permission, only the return workflow can be used, subject to the UI's validation.
6. Submit and confirm **Process return**. The application records a permanent return and any refund atomically. A failed validation does not partially apply the return.
7. Reopen the sale detail/history and check returned quantities, status, and refund/payment details.

Restocking: quantity-based returned goods are added to the original sale location when the **Return to saleable stock** option is enabled (the form defaults it on). Serialized devices return to stock at that location when restocked; if restocking is off, a returned serialized device is marked **In repair**. The line explicitly shows **Not added to available stock** when restocking is not selected. Review this carefully before submission. Already returned quantity is unavailable for another return. Refund is capped by the remaining paid amount; a return does not necessarily mean cash was refunded.
