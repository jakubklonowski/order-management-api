<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ProductExists extends Constraint
{
    public string $message = 'Product {{ id }} does not exist.';
}
