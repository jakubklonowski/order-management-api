<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InventoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryRepository::class)]
#[ORM\Table(name: 'inventory')]
class Inventory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'inventory', targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column]
    private int $reservedQuantity = 0;

    #[ORM\Column]
    private int $lowStockThreshold;

    public function __construct(Product $product, int $quantity, int $lowStockThreshold)
    {
        $this->product = $product;
        $this->quantity = $quantity;
        $this->lowStockThreshold = $lowStockThreshold;

        // Product holds the inverse side, so it must be set explicitly or
        // $product->getInventory() stays null and its cascade never fires.
        $product->setInventory($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getReservedQuantity(): int
    {
        return $this->reservedQuantity;
    }

    public function setReservedQuantity(int $reservedQuantity): static
    {
        $this->reservedQuantity = $reservedQuantity;

        return $this;
    }

    public function getLowStockThreshold(): int
    {
        return $this->lowStockThreshold;
    }

    public function setLowStockThreshold(int $lowStockThreshold): static
    {
        $this->lowStockThreshold = $lowStockThreshold;

        return $this;
    }

    public function getAvailableQuantity(): int
    {
        return $this->quantity - $this->reservedQuantity;
    }

    public function reserve(int $amount): void
    {
        if ($amount > $this->getAvailableQuantity()) {
            throw new \DomainException('Not enough available stock to reserve.');
        }

        $this->reservedQuantity += $amount;
    }

    public function release(int $amount): void
    {
        $this->reservedQuantity = max(0, $this->reservedQuantity - $amount);
    }

    public function isLowStock(): bool
    {
        return $this->getAvailableQuantity() <= $this->lowStockThreshold;
    }
}
