<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Repository\InventoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrderStatusChanger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryRepository $inventories,
    ) {
    }

    /**
     * Moves an order to a new status and applies proper actions to stock.
     *
     * @throws \DomainException when the transition is not allowed
     */
    public function change(Order $order, OrderStatus $to, User $by): void
    {
        $this->em->wrapInTransaction(function () use ($order, $to, $by): void {
            // confirming moves no stock, so it takes no locks and waits on nobody
            $inventories = self::movesStock($to)
                ? $this->inventories->findAllByProductIdsForUpdate(self::getProductIdsOfOrder($order))
                : [];

            $order->changeStatus($to, $by);

            foreach ($order->getItems() as $item) {
                $inventory = $inventories[$item->getProduct()->getId()] ?? null;

                // in case product and stock were deleted in the meantime
                if (null === $inventory) {
                    continue;
                }

                if (OrderStatus::Cancelled === $to) {
                    $inventory->release($item->getQuantity());
                } elseif (OrderStatus::Shipped === $to) {
                    $inventory->ship($item->getQuantity());
                }
            }

            $this->em->flush();
        });
    }

    private static function movesStock(OrderStatus $to): bool
    {
        return OrderStatus::Cancelled === $to || OrderStatus::Shipped === $to;
    }

    /**
     * @return list<int>
     */
    private static function getProductIdsOfOrder(Order $order): array
    {
        $productIds = [];

        foreach ($order->getItems() as $item) {
            $productIds[] = $item->getProduct()->getId();
        }

        return $productIds;
    }
}
