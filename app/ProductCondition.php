<?php

namespace App;

enum ProductCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Refurbished = 'refurbished';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Used => 'Used',
            self::Refurbished => 'Refurbished',
        };
    }
}
