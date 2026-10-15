<?php

namespace App;

enum LocationType: string
{
    case Shop = 'shop';
    case Warehouse = 'warehouse';
    case RepairCentre = 'repair_centre';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Shop => 'Shop',
            self::Warehouse => 'Warehouse',
            self::RepairCentre => 'Repair centre',
            self::Other => 'Other',
        };
    }
}
