<?php

namespace App;

enum SerializedDeviceStatus: string
{
    case InStock = 'in_stock';
    case Sold = 'sold';
    case InRepair = 'in_repair';
    case ReturnedToSupplier = 'returned_to_supplier';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'In stock',
            self::Sold => 'Sold',
            self::InRepair => 'In repair',
            self::ReturnedToSupplier => 'Returned to supplier',
        };
    }
}
