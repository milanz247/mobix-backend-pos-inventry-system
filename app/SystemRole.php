<?php

namespace App;

enum SystemRole: string
{
    case Owner = 'Owner';
    case Admin = 'Admin';
    case Manager = 'Manager';
    case Cashier = 'Cashier';
    case Technician = 'Technician';

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Protected business owner with full access.',
            self::Admin => 'Manages users, roles, permissions and business settings.',
            self::Manager => 'Oversees daily operations and user records.',
            self::Cashier => 'Access for point-of-sale staff.',
            self::Technician => 'Access for repair technicians.',
        };
    }
}
