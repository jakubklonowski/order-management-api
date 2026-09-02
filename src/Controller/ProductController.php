<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProductListQuery;
use App\Dto\ProductRequest;
use App\Entity\Product;
use App\EventListener\ApiExceptionListener;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/products', requirements: ['id' => '\d+'])]
final class ProductController extends AbstractController
{
    private const LIST = ['groups' => 'product:list'];
    private const READ = ['groups' => 'product:read'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CategoryRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_products_index', methods: ['GET'])]
    public function index(
        // returns 422 instead of default 404 on bad query param
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ProductListQuery $query = new ProductListQuery(),
    ): JsonResponse {
        $page = $this->products->paginate(
            $query->getPage(),
            $query->getLimit(),
            $query->getCategoryId(),
            $query->getSearch(),
        );

        return $this->json([
            'items' => $page['items'],
            'page' => $query->getPage(),
            'limit' => $query->getLimit(),
            'total' => $page['total'],
        ], Response::HTTP_OK, [], self::LIST);
    }

    #[Route('/{id}', name: 'api_products_show', methods: ['GET'])]
    public function show(Product $product): JsonResponse
    {
        return $this->json($product, Response::HTTP_OK, [], self::READ);
    }

    #[Route('', name: 'api_products_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(#[MapRequestPayload] ProductRequest $request): JsonResponse
    {
        if ($this->skuBelongsToAnotherProduct($request->getSku())) {
            return $this->skuConflict();
        }

        $product = new Product(
            $request->getName(),
            $request->getPrice(),
            $request->getSku(),
            $this->categories->find($request->getCategoryId()),
        );
        $product->setDescription($request->getDescription());

        try {
            $this->em->persist($product);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // sku uniqueness catch in case something happened between check and write
            return $this->skuConflict();
        }

        return $this->json($product, Response::HTTP_CREATED, [], self::READ);
    }

    #[Route('/{id}', name: 'api_products_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Product $product, #[MapRequestPayload] ProductRequest $request): JsonResponse
    {
        if ($this->skuBelongsToAnotherProduct($request->getSku(), $product->getId())) {
            return $this->skuConflict();
        }

        $product->setName($request->getName())
            ->setDescription($request->getDescription())
            ->setPrice($request->getPrice())
            ->setSku($request->getSku())
            ->setCategory($this->categories->find($request->getCategoryId()));

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->skuConflict();
        }

        return $this->json($product, Response::HTTP_OK, [], self::READ);
    }

    #[Route('/{id}', name: 'api_products_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Product $product): Response
    {
        try {
            $this->em->remove($product);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            // order item product ON DELETE RESTRICT raised exception
            return $this->json(
                ['errors' => [ApiExceptionListener::GENERAL => ['Product appears on an order and cannot be deleted.']]],
                Response::HTTP_CONFLICT,
            );
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // returns true if particular SKU is taken by any product other than product with ID = $exceptId
    private function skuBelongsToAnotherProduct(string $sku, ?int $exceptId = null): bool
    {
        $owner = $this->products->findOneBy(['sku' => $sku]);

        return null !== $owner && $owner->getId() !== $exceptId;
    }

    private function skuConflict(): JsonResponse
    {
        return $this->json(['errors' => ['sku' => ['SKU is already in use.']]], Response::HTTP_CONFLICT);
    }
}
