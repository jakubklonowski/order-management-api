<?php

namespace App\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case Customer = 'customer';

    public function toRoleName(): string
    {
        return 'ROLE_' . strtoupper($this->value);
    }
}
