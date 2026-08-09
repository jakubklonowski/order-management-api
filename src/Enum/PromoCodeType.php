<?php

namespace App\Enum;

enum PromoCodeType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
