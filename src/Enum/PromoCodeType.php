<?php

declare(strict_types=1);

namespace App\Enum;

enum PromoCodeType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
