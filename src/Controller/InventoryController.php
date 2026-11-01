<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\InventoryRequest;
use App\Entity\Inventory;
use App\Entity\Product;
use App\Message\LowStockReached;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/inventory', requirements: ['productId' => '\d+'])]
#[IsGranted('ROLE_ADMIN')] // customers should never be able to access this controller
#[OA\Tag(name: 'Inventory')]
#[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
#[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
final class InventoryController extends AbstractController
{
    private const READ = ['groups' => 'inventory:read'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/{productId}', name: 'api_inventory_show', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'The stock of the product.',
        content: new Model(type: Inventory::class, groups: [self::READ['groups']]),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function show(#[MapEntity(id: 'productId')] Product $product): JsonResponse
    {
        $inventory = $product->getInventory();

        if (null === $inventory) {
            throw $this->createNotFoundException();
        }

        return $this->json($inventory, Response::HTTP_OK, [], self::READ);
    }

    #[Route('/{productId}', name: 'api_inventory_update', methods: ['PUT'])]
    #[OA\Response(
        response: 200,
        description: 'The stock was updated.',
        content: new Model(type: Inventory::class, groups: [self::READ['groups']]),
    )]
    #[OA\Response(
        response: 201,
        description: 'The product had no stock yet, so it was created.',
        content: new Model(type: Inventory::class, groups: [self::READ['groups']]),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationFailed')]
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

        $alreadyLow = $inventory->isLowStock();

        $this->em->wrapInTransaction(function () use ($inventory, $request, $alreadyLow): void {
            $inventory->setQuantity($request->getQuantity())
                ->setLowStockThreshold($request->getLowStockThreshold());
            $this->em->flush();

            // dispatch low stock alert only if stock was not low and is low now
            if (!$alreadyLow && $inventory->isLowStock()) {
                $this->bus->dispatch(new LowStockReached(
                    $inventory->getProductId() ?? throw new \LogicException('Inventory points at an unsaved product.')
                ));
            }
        });

        return $this->json($inventory, Response::HTTP_OK, [], self::READ);
    }
}
