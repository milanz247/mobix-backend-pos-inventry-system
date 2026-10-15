<?php

namespace App;

enum BusinessType: string
{
    case MobileSalesAndRepairs = 'mobile_sales_and_repairs';
    case MobileSalesOnly = 'mobile_sales_only';
    case MobileRepairsOnly = 'mobile_repairs_only';

    public function label(): string
    {
        return match ($this) {
            self::MobileSalesAndRepairs => 'Mobile sales and repairs',
            self::MobileSalesOnly => 'Mobile sales only',
            self::MobileRepairsOnly => 'Mobile repairs only',
        };
    }
}
