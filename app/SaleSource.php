<?php

namespace App;

enum SaleSource: string
{
    case InStorePos = 'in_store_pos';
    case WhatsApp = 'whatsapp';
    case Facebook = 'facebook';
    case Phone = 'phone';
    case Other = 'other';
    case Ecommerce = 'ecommerce';

    public function label(): string
    {
        return match ($this) {
            self::InStorePos => 'In-store POS',
            self::WhatsApp => 'WhatsApp',
            self::Facebook => 'Facebook',
            self::Phone => 'Phone',
            self::Other => 'Other',
            self::Ecommerce => 'Ecommerce',
        };
    }
}
