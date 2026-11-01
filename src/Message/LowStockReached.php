<?php

declare(strict_types=1);

namespace App\Message;

final readonly class LowStockReached
{
    public function __construct(public int $productId)
    {
    }
}
