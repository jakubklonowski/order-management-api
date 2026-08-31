<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CategoryRequest;
use App\Entity\Category;
use App\EventListener\ApiExceptionListener;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/categories', requirements: ['id' => '\d+'])]
final class CategoryController extends AbstractController
{
    private const READ = ['groups' => 'category:read'];

    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_categories_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json($this->categories->findBy([], ['name' => 'ASC']), Response::HTTP_OK, [], self::READ);
    }

    #[Route('/{id}', name: 'api_categories_show', methods: ['GET'])]
    public function show(Category $category): JsonResponse
    {
        return $this->json($category, Response::HTTP_OK, [], self::READ);
    }

    #[Route('', name: 'api_categories_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(#[MapRequestPayload] CategoryRequest $request): JsonResponse
    {
        $category = new Category($request->getName());
        $category->setParent($this->getCategoryById($request->getParentId()));

        $this->em->persist($category);
        $this->em->flush();

        return $this->json($category, Response::HTTP_CREATED, [], self::READ);
    }

    #[Route('/{id}', name: 'api_categories_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Category $category, #[MapRequestPayload] CategoryRequest $request): JsonResponse
    {
        $parent = $this->getCategoryById($request->getParentId());

        if ($this->wouldCreateCycle($category, $parent)) {
            return $this->json(
                ['errors' => ['parentId' => ['A category cannot be its own ancestor.']]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $category->setName($request->getName())->setParent($parent);
        $this->em->flush();

        return $this->json($category, Response::HTTP_OK, [], self::READ);
    }

    #[Route('/{id}', name: 'api_categories_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Category $category): Response
    {
        try {
            $this->em->remove($category);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            // product-category ON DELETE RESTRICT raised exception
            return $this->json(
                ['errors' => [ApiExceptionListener::GENERAL => ['Category has products and cannot be deleted.']]],
                Response::HTTP_CONFLICT,
            );
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function getCategoryById(?int $id): ?Category
    {
        return null === $id ? null : $this->categories->find($id);
    }

    // checks if new parent is the same category itself or is one of its descendants
    private function wouldCreateCycle(Category $category, ?Category $parent): bool
    {
        while (null !== $parent) {
            if ($parent === $category) {
                return true;
            }

            $parent = $parent->getParent();
        }

        return false;
    }
}
