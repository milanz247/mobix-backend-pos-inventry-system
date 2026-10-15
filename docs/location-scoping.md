# Location scoping

NalakaMobile currently supports one registered business with multiple locations. The `businesses` record owns the business-wide configuration and catalogue. Locations represent operational sites such as shops, warehouses, and repair centres.

## Authorization boundary

Every location-scoped endpoint must perform both checks:

1. The user has the relevant Spatie permission for the requested operation.
2. `LocationAccessService` confirms that the location belongs to the registered business and that the user is assigned to it. The protected Owner has all-location access.

Route parameters and submitted location IDs are never authorization grants. Controllers should authorize the resolved `Location` through `LocationPolicy` before reading or writing location-scoped records.

## Future operational tables

Business-wide catalogue records, such as product definitions, should use `business_id`. Operational records must use `location_id` and be resolved through an authorized location:

- stock quantities and reorder levels;
- immutable stock movements and adjustments;
- serialized phone and IMEI inventory;
- POS sales, registers, payments, and refunds;
- repair jobs and the location holding the device;
- purchase receiving and location transfers.

Reads must start from an authorized location or constrain by the user’s accessible location IDs. Writes must derive `location_id` from the authorized route or server-side context, never directly trust it from request data. Transfers require authorization for both source and destination locations and must run in one database transaction.

Historical operational records should restrict location deletion. Locations should normally be deactivated, while historical rows retain their original `location_id`.
