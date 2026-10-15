<?php

namespace App;

enum ProductType: string
{
    case Simple = 'simple';
    case Variable = 'variable';
    case Serialized = 'serialized';
    case Combo = 'combo';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple product',
            self::Variable => 'Variable product',
            self::Serialized => 'Serialized device',
            self::Combo => 'Combo / bundle',
        };
    }
}
