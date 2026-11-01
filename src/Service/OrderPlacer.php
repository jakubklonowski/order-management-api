<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\OrderItemRequest;
use App\Entity\Order;
use App\Entity\User;
use App\Exception\InsufficientStockException;
use App\Message\LowStockReached;
use App\Message\OrderPlaced;
use App\Repository\InventoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class OrderPlacer
{
    private const SCALE = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryRepository $inventories,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Reserves stock and creates the order in one transaction.
     *
     * @param array<OrderItemRequest> $orderLines
     *
     * @throws InsufficientStockException when any line cannot be reserved
     */
    public function place(User $user, array $orderLines): Order
    {
        $orderPositions = $this->mergeByProduct($orderLines);

        return $this->em->wrapInTransaction(fn (): Order => $this->buildOrder($user, $orderPositions));
    }

    /**
     * @param array<OrderItemRequest> $lines
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
        $inventories = $this->inventories->findAllByProductIdsForUpdate(array_keys($orderPositions));

        $order = new Order($user);
        $total = '0.00';
        $newlyLowProductIds = [];

        foreach ($orderPositions as $productId => $quantity) {
            $inventory = $inventories[$productId] ?? null;

            // product with no stock cant be sold
            if (null === $inventory) {
                throw new InsufficientStockException($productId);
            }

            $alreadyLow = $inventory->isLowStock();

            try {
                $inventory->reserve($quantity);
            } catch (\DomainException) {
                // reserve() throws bare DomainException caused by insufficient stock;
                // exception is enriched with $productId and threw again for controller to catch
                throw new InsufficientStockException($productId);
            }

            // dispatch low stock alert only if stock was not low and is low now
            if (!$alreadyLow && $inventory->isLowStock()) {
                $newlyLowProductIds[] = $productId;
            }

            $product = $inventory->getProduct();

            // price is copied onto the row so repricing the product won't affect previous transactions
            $order->addItem($product, $quantity, $product->getPrice());

            $total = bcadd($total, bcmul($product->getPrice(), (string) $quantity, self::SCALE), self::SCALE);
        }

        $order->setTotalPrice($total);

        $this->em->persist($order);
        $this->em->flush();

        $this->bus->dispatch(new OrderPlaced($order->getId() ?? throw new \LogicException('Order has no id after flush.')));

        foreach ($newlyLowProductIds as $productId) {
            $this->bus->dispatch(new LowStockReached($productId));
        }

        return $order;
    }
}
