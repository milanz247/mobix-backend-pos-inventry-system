<?php

namespace App;

enum SerializedDeviceStatus: string
{
    case InStock = 'in_stock';
    case Sold = 'sold';
    case Damaged = 'damaged';
    case InRepair = 'in_repair';
    case ReturnedToSupplier = 'returned_to_supplier';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::InStock => 'In stock',
            self::Sold => 'Sold',
            self::InRepair => 'In repair',
            self::ReturnedToSupplier => 'Returned to supplier',
        };
    }
}
