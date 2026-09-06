<?php

declare(strict_types=1);

namespace App\Exception;

final class InsufficientStockException extends \DomainException
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct(sprintf('Product %d does not have enough stock.', $productId));
    }
}
