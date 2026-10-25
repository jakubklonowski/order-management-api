<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\CategoryExists;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Schema(required: ['name'])]
final class CategoryRequest
{
    #[Assert\NotBlank(message: 'Name is required.', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[Assert\Positive(message: 'Parent id must be a positive integer.')]
    #[CategoryExists]
    private ?int $parentId = null;

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setParentId(?int $parentId): void
    {
        $this->parentId = $parentId;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }
}
