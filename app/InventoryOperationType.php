<?php

namespace App;

enum InventoryOperationType: string
{
    case Receiving = 'receiving';
    case OpeningStock = 'opening_stock';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case DeviceStatus = 'device_status';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
}
