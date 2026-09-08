<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\OrderRequest;
use App\Dto\OrderStatusRequest;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\UserRole;
use App\Exception\InsufficientStockException;
use App\Repository\OrderRepository;
use App\Security\OrderVoter;
use App\Service\OrderPlacer;
use App\Service\OrderStatusChanger;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/orders', requirements: ['id' => '\d+'])]
final class OrderController extends AbstractController
{
    private const LIST = ['groups' => 'order:list'];
    private const READ = ['groups' => 'order:read'];
    private const HISTORY = ['groups' => 'order:history'];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderPlacer $placer,
        private readonly OrderStatusChanger $statusChanger,
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

    #[Route('/{id}/cancel', name: 'api_orders_cancel', methods: ['POST'])]
    public function cancel(Order $order, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted(OrderVoter::CANCEL, $order);

        return $this->changeStatus($order, OrderStatus::Cancelled, $user);
    }

    #[Route('/{id}/status', name: 'api_orders_status', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function status(
        Order $order,
        #[MapRequestPayload] OrderStatusRequest $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        return $this->changeStatus($order, $request->getStatus(), $user);
    }

    private function changeStatus(Order $order, OrderStatus $to, User $by): JsonResponse
    {
        try {
            $this->statusChanger->change($order, $to, $by);
        } catch (\DomainException $exception) {
            // illegal status change, not wrong user
            return $this->json(
                ['errors' => ['status' => [$exception->getMessage()]]],
                Response::HTTP_CONFLICT,
            );
        }

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
