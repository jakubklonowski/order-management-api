<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\OrderRequest;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\UserRole;
use App\Exception\InsufficientStockException;
use App\Repository\OrderRepository;
use App\Security\OrderVoter;
use App\Service\OrderPlacer;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/orders', requirements: ['id' => '\d+'])]
final class OrderController extends AbstractController
{
    private const LIST = ['groups' => 'order:list'];
    private const READ = ['groups' => 'order:read'];
    private const HISTORY = ['groups' => 'order:history'];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderPlacer $placer,
    ) {
    }

    #[Route('', name: 'api_orders_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): JsonResponse
    {
        $orders = UserRole::Admin === $user->getRole()
            ? $this->orders->findBy([], ['id' => 'DESC'])
            : $this->orders->findByUser($user);

        return $this->json($orders, Response::HTTP_OK, [], self::LIST);
    }

    #[Route('/{id}', name: 'api_orders_show', methods: ['GET'])]
    public function show(Order $order): JsonResponse
    {
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        return $this->json($order, Response::HTTP_OK, [], self::READ);
    }

    #[Route('/{id}/history', name: 'api_orders_history', methods: ['GET'])]
    public function history(Order $order): JsonResponse
    {
        $this->denyAccessUnlessGranted(OrderVoter::VIEW, $order);

        return $this->json($order->getStatusHistory()->toArray(), Response::HTTP_OK, [], self::HISTORY);
    }

    #[Route('', name: 'api_orders_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] OrderRequest $request): JsonResponse
    {
        try {
            $order = $this->placer->place($user, $request->getItems());
        } catch (InsufficientStockException $exception) {
            // reservation rolled back, nothing ordered
            return $this->json(
                ['errors' => ['items' => [$exception->getMessage()]]],
                Response::HTTP_CONFLICT,
            );
        } catch (LockWaitTimeoutException) {
            // lock timeout, not server fault
            return $this->json(
                ['errors' => ['items' => ['Stock is being updated by another order. Try again.']]],
                Response::HTTP_CONFLICT,
            );
        }

        return $this->json($order, Response::HTTP_CREATED, [], self::READ);
    }
}
