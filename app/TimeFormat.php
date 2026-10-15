<?php

namespace App;

enum TimeFormat: string
{
    case TwelveHour = '12';
    case TwentyFourHour = '24';

    public function label(): string
    {
        return match ($this) {
            self::TwelveHour => '12-hour (e.g. 2:30 PM)',
            self::TwentyFourHour => '24-hour (e.g. 14:30)',
        };
    }
}
