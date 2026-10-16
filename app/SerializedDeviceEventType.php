<?php

namespace App;

enum SerializedDeviceEventType: string
{
    case Received = 'received';
    case OpeningBalance = 'opening_balance';
    case Transferred = 'transferred';
    case StatusChanged = 'status_changed';
    case ReturnedToSupplier = 'returned_to_supplier';
    case Sold = 'sold';
    case ReturnedFromSale = 'returned_from_sale';
}
