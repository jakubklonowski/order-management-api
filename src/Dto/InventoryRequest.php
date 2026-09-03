<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

// no reservedQuantity as it's not to be edited directly through Inventory endpoints
final class InventoryRequest
{
    #[Assert\NotNull(message: 'Quantity is required.')]
    #[Assert\PositiveOrZero(message: 'Quantity cannot be negative.')]
    private ?int $quantity = null;

    #[Assert\NotNull(message: 'Low stock threshold is required.')]
    #[Assert\PositiveOrZero(message: 'Low stock threshold cannot be negative.')]
    private ?int $lowStockThreshold = null;

    public function setQuantity(?int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setLowStockThreshold(?int $lowStockThreshold): void
    {
        $this->lowStockThreshold = $lowStockThreshold;
    }

    public function getLowStockThreshold(): ?int
    {
        return $this->lowStockThreshold;
    }
}
