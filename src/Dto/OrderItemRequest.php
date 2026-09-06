<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\ProductExists;
use Symfony\Component\Validator\Constraints as Assert;

final class OrderItemRequest
{
    #[Assert\NotNull(message: 'Product id is required.')]
    #[Assert\Positive(message: 'Product id must be a positive integer.')]
    #[ProductExists]
    private ?int $productId = null;

    #[Assert\NotNull(message: 'Quantity is required.')]
    #[Assert\Positive(message: 'Quantity must be a positive integer.')]
    private ?int $quantity = null;

    public function setProductId(?int $productId): void
    {
        $this->productId = $productId;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function setQuantity(?int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }
}
