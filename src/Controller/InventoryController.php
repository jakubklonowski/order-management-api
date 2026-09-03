<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\InventoryRequest;
use App\Entity\Inventory;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/inventory', requirements: ['productId' => '\d+'])]
#[IsGranted('ROLE_ADMIN')] // customers should never be able to access this controller
final class InventoryController extends AbstractController
{
    private const READ = ['groups' => 'inventory:read'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/{productId}', name: 'api_inventory_show', methods: ['GET'])]
    public function show(#[MapEntity(id: 'productId')] Product $product): JsonResponse
    {
        $inventory = $product->getInventory();

        if (null === $inventory) {
            throw $this->createNotFoundException();
        }

        return $this->json($inventory, Response::HTTP_OK, [], self::READ);
    }

    #[Route('/{productId}', name: 'api_inventory_update', methods: ['PUT'])]
    public function update(
        #[MapEntity(id: 'productId')] Product $product,
        #[MapRequestPayload] InventoryRequest $request,
    ): JsonResponse {
        $inventory = $product->getInventory();

        // product has no stock until one is written by PUT
        if (null === $inventory) {
            $inventory = new Inventory($product, $request->getQuantity(), $request->getLowStockThreshold());

            $this->em->persist($inventory);
            $this->em->flush();

            return $this->json($inventory, Response::HTTP_CREATED, [], self::READ);
        }

        if ($request->getQuantity() < $inventory->getReservedQuantity()) {
            return $this->json(
                ['errors' => ['quantity' => ['Quantity cannot be lower than the reserved quantity.']]],
                Response::HTTP_CONFLICT,
            );
        }

        $inventory->setQuantity($request->getQuantity())
            ->setLowStockThreshold($request->getLowStockThreshold());
        $this->em->flush();

        return $this->json($inventory, Response::HTTP_OK, [], self::READ);
    }
}
