<?php

namespace App;

enum VariantTrackingMode: string
{
    case Serialized = 'serialized';
    case QuantityBased = 'quantity_based';

    public function label(): string
    {
        return match ($this) {
            self::Serialized => 'Serialized / IMEI tracked',
            self::QuantityBased => 'Quantity based',
        };
    }
}
