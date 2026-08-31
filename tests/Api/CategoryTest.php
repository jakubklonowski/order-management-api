<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Category;
use App\Entity\Product;
use App\Enum\UserRole;
use App\Tests\ApiTestCase;

final class CategoryTest extends ApiTestCase
{
    private string $adminToken;
    private string $customerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenForNewUser('admin@domain.pl', UserRole::Admin);
        $this->customerToken = $this->tokenForNewUser('customer@domain.pl');
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        $category = new Category($name);
        $category->setParent($parent);

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private static function payload(string $name, ?int $parentId = null): string
    {
        return json_encode(['name' => $name, 'parentId' => $parentId], \JSON_THROW_ON_ERROR);
    }

    private function countCategories(): int
    {
        return \count($this->em->getRepository(Category::class)->findAll());
    }

    // clears cache so test is forced to assert data against database
    private function reload(int $id): Category
    {
        $this->em->clear();
        $category = $this->em->getRepository(Category::class)->find($id);

        self::assertNotNull($category);

        return $category;
    }

    public function testListingRequiresAuthentication(): void
    {
        $this->request('GET', '/api/categories');

        self::assertResponseStatusCodeSame(401);
    }

    public function testListingReturnsCategoriesByName(): void
    {
        $this->category('Tools');
        $this->category('Adhesives');

        $this->request('GET', '/api/categories', token: $this->customerToken);

        self::assertResponseIsSuccessful();
        $body = $this->responseBody();

        self::assertCount(2, $body);
        self::assertSame(['Adhesives', 'Tools'], array_column($body, 'name'));
        self::assertEqualsCanonicalizing(['id', 'name', 'parentId'], array_keys($body[0]));
    }

    public function testShowReportsTheParentAsAnId(): void
    {
        $parent = $this->category('Tools');
        $child = $this->category('Hammers', $parent);

        $this->request('GET', '/api/categories/'.$child->getId(), token: $this->customerToken);

        self::assertResponseIsSuccessful();
        self::assertSame($parent->getId(), $this->responseBody()['parentId']);
    }

    public function testShowRejectsUnknownId(): void
    {
        $this->request('GET', '/api/categories/999999', token: $this->customerToken);

        self::assertResponseStatusCodeSame(404);
    }

    // guards ApiExceptionListener rather than this endpoint
    public function testUnknownIdDoesNotLeakTheEntityClass(): void
    {
        $this->request('GET', '/api/categories/999999', token: $this->customerToken);

        self::assertSame(['Not Found'], $this->responseBody()['errors']['_']);
    }

    public function testCustomerCannotCreate(): void
    {
        $this->request('POST', '/api/categories', self::payload('Tools'), $this->customerToken);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countCategories());
    }

    public function testAdminCreatesCategory(): void
    {
        $this->request('POST', '/api/categories', self::payload('Tools'), $this->adminToken);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Tools', $this->responseBody()['name']);
        self::assertNull($this->responseBody()['parentId']);
        self::assertSame(1, $this->countCategories());
    }

    public function testCreateAcceptsAnExistingParent(): void
    {
        $parentId = $this->category('Tools')->getId();

        $this->request('POST', '/api/categories', self::payload('Hammers', $parentId), $this->adminToken);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($parentId, $this->responseBody()['parentId']);
        self::assertSame($parentId, $this->reload($this->responseBody()['id'])->getParent()->getId());
    }

    public function testCreateRejectsBlankName(): void
    {
        $this->request('POST', '/api/categories', self::payload('   '), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('name', $this->responseBody()['errors']);
        self::assertSame(0, $this->countCategories());
    }

    public function testCreateRejectsUnknownParent(): void
    {
        $this->request('POST', '/api/categories', self::payload('Hammers', 999999), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('parentId', $this->responseBody()['errors']);
        self::assertSame(0, $this->countCategories());
    }

    public function testUpdateRenamesAndReparents(): void
    {
        $parentId = $this->category('Tools')->getId();
        $categoryId = $this->category('Hamers')->getId();

        $this->request('PUT', '/api/categories/'.$categoryId, self::payload('Hammers', $parentId), $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertSame('Hammers', $this->responseBody()['name']);
        self::assertSame($parentId, $this->responseBody()['parentId']);

        $updated = $this->reload($categoryId);
        self::assertSame('Hammers', $updated->getName());
        self::assertSame($parentId, $updated->getParent()->getId());
    }

    public function testUpdateCanRemoveTheParent(): void
    {
        $parent = $this->category('Tools');
        $childId = $this->category('Hammers', $parent)->getId();

        // PUT with no parentId makes it root level category
        $this->request('PUT', '/api/categories/'.$childId, self::payload('Hammers'), $this->adminToken);

        self::assertResponseIsSuccessful();
        self::assertNull($this->responseBody()['parentId']);
        self::assertNull($this->reload($childId)->getParent());
    }

    public function testUpdateRejectsSelfAsParent(): void
    {
        $category = $this->category('Tools');

        $this->request('PUT', '/api/categories/'.$category->getId(), self::payload('Tools', $category->getId()), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('parentId', $this->responseBody()['errors']);
    }

    public function testUpdateRejectsAncestorAsChild(): void
    {
        $ancestor = $this->category('Tools');
        $child = $this->category('Hammers', $ancestor);

        $this->request('PUT', '/api/categories/'.$ancestor->getId(), self::payload('Tools', $child->getId()), $this->adminToken);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('parentId', $this->responseBody()['errors']);
    }

    public function testAdminDeletesCategory(): void
    {
        $category = $this->category('Tools');

        $this->request('DELETE', '/api/categories/'.$category->getId(), token: $this->adminToken);

        self::assertResponseStatusCodeSame(204);
        self::assertEmpty($this->client->getResponse()->getContent());
        self::assertSame(0, $this->countCategories());
    }

    public function testDeletingAParentPromotesItsChildren(): void
    {
        $parent = $this->category('Tools');
        $childId = $this->category('Hammers', $parent)->getId();

        $this->request('DELETE', '/api/categories/'.$parent->getId(), token: $this->adminToken);

        self::assertResponseStatusCodeSame(204);

        // ON DELETE SET NULL makes children survive as root
        self::assertNull($this->reload($childId)->getParent());
    }

    public function testDeleteRejectsCategoryStillHoldingProducts(): void
    {
        $category = $this->category('Tools');
        $this->em->persist(new Product('Hammer', '19.99', 'SKU-1', $category));
        $this->em->flush();

        $this->request('DELETE', '/api/categories/'.$category->getId(), token: $this->adminToken);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->countCategories());
    }
}
