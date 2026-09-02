<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ProductListQuery
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    #[Assert\Positive(message: 'Page must be a positive integer.')]
    private int $page = 1;

    #[Assert\Positive(message: 'Limit must be a positive integer.')]
    #[Assert\LessThanOrEqual(value: self::MAX_LIMIT, message: 'Limit cannot be greater than {{ compared_value }}.')]
    private int $limit = self::DEFAULT_LIMIT;

    // no #[CategoryExists] validator - filtering by non-existing
    // category is valid request with empty result set
    #[Assert\Positive(message: 'Category id must be a positive integer.')]
    private ?int $categoryId = null;

    #[Assert\Length(max: 255)]
    private ?string $search = null;

    public function setPage(int $page): void
    {
        $this->page = $page;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setCategoryId(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function setSearch(?string $search): void
    {
        $search = null === $search ? null : trim($search);

        $this->search = '' === $search ? null : $search;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }
}
