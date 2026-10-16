<?php

namespace App;

enum StockMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case Receiving = 'receiving';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case PurchaseReturn = 'purchase_return';
    case RepairConsumption = 'repair_consumption';
    case RepairReturn = 'repair_return';
    case Reversal = 'reversal';
}
