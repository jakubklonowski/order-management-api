<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class OrderRequest
{
    /** @var list<OrderItemRequest> */
    #[Assert\Count(min: 1, minMessage: 'An order must contain at least one item.')]
    #[Assert\Valid]
    private array $items;

    // variadic parameter needed for type enforcing
    public function __construct(OrderItemRequest ...$items)
    {
        $this->items = $items;
    }

    /** @return list<OrderItemRequest> */
    public function getItems(): array
    {
        return $this->items;
    }
}
