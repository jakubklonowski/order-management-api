<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\OrderItemRequest;
use App\Entity\Order;
use App\Entity\User;
use App\Exception\InsufficientStockException;
use App\Repository\InventoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrderPlacer
{
    private const SCALE = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryRepository $inventories,
    ) {
    }

    /**
     * Reserves stock and creates the order in one transaction.
     *
     * @param list<OrderItemRequest> $orderLines
     *
     * @throws InsufficientStockException when any line cannot be reserved
     */
    public function place(User $user, array $orderLines): Order
    {
        $orderPositions = $this->mergeByProduct($orderLines);

        // sorts order positions by product ID
        // this should prevent deadlock occurring when two carts with
        // the same products in different order blocks each other
        ksort($orderPositions);

        return $this->em->wrapInTransaction(fn (): Order => $this->buildOrder($user, $orderPositions));
    }

    /**
     * @param list<OrderItemRequest> $lines
     *
     * @return array<int, int> [product ID => quantity]
     */
    private function mergeByProduct(array $lines): array
    {
        $quantities = [];

        // the same product twice in an order is merged into a single position with summed quantity
        foreach ($lines as $line) {
            $quantities[$line->getProductId()] = ($quantities[$line->getProductId()] ?? 0) + $line->getQuantity();
        }

        return $quantities;
    }

    /**
     * @param array<int, int> $orderPositions
     */
    private function buildOrder(User $user, array $orderPositions): Order
    {
        $order = new Order($user);
        $total = '0.00';

        foreach ($orderPositions as $productId => $quantity) {
            $inventory = $this->inventories->findOneByProductIdForUpdate($productId);

            // product with no stock cant be sold
            if (null === $inventory) {
                throw new InsufficientStockException($productId);
            }

            try {
                $inventory->reserve($quantity);
            } catch (\DomainException) {
                // reserve() throws bare DomainException caused by insufficient stock;
                // exception is enriched with $productId and threw again for controller to catch
                throw new InsufficientStockException($productId);
            }

            $product = $inventory->getProduct();

            // price is copied onto the row so repricing the product won't affect previous transactions
            $order->addItem($product, $quantity, $product->getPrice());

            $total = bcadd($total, bcmul($product->getPrice(), (string) $quantity, self::SCALE), self::SCALE);
        }

        $order->setTotalPrice($total);

        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }
}
