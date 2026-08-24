<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PromoCodeType;
use App\Repository\PromoCodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
#[ORM\Table(name: 'promo_codes')]
class PromoCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column(length: 20, enumType: PromoCodeType::class)]
    private PromoCodeType $type;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $value;

    #[ORM\Column]
    private int $maxUses;

    #[ORM\Column]
    private int $usedCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(string $code, PromoCodeType $type, string $value, int $maxUses, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->code = strtoupper($code);
        $this->type = $type;
        $this->value = $value;
        $this->maxUses = $maxUses;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getType(): PromoCodeType
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getMaxUses(): int
    {
        return $this->maxUses;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function incrementUsedCount(): void
    {
        ++$this->usedCount;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isValid(): bool
    {
        if ($this->usedCount >= $this->maxUses) {
            return false;
        }

        if (null !== $this->expiresAt && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return true;
    }
}
