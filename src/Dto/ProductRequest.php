<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\CategoryExists;
use Symfony\Component\Validator\Constraints as Assert;

final class ProductRequest
{
    #[Assert\NotBlank(message: 'Name is required.', normalizer: 'trim')]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[Assert\Length(max: 5000)]
    private ?string $description = null;

    // price as string because of binary representation of decimals being imprecise
    #[Assert\NotBlank(message: 'Price is required.')]
    #[Assert\Regex(
        pattern: '/^\d{1,8}(\.\d{1,2})?$/',
        message: 'Price must be a decimal string with up to 8 digits and 2 decimal places e.g. "19.99".',
    )]
    private string $price = '';

    #[Assert\NotBlank(message: 'SKU is required.', normalizer: 'trim')]
    #[Assert\Length(max: 64)]
    private string $sku = '';

    #[Assert\NotNull(message: 'Category id is required.')]
    #[Assert\Positive(message: 'Category id must be a positive integer.')]
    #[CategoryExists]
    private ?int $categoryId = null;

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setDescription(?string $description): void
    {
        $description = null === $description ? null : trim($description);

        $this->description = '' === $description ? null : $description;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setPrice(string $price): void
    {
        $this->price = trim($price);
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setSku(string $sku): void
    {
        $this->sku = trim($sku);
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setCategoryId(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }
}
