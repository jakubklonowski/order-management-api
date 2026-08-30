<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalPrice;

    #[ORM\ManyToOne(targetEntity: PromoCode::class)]
    #[ORM\JoinColumn(name: 'promo_code_id', nullable: true, onDelete: 'SET NULL')]
    private ?PromoCode $promoCode = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, OrderStatusHistory> */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderStatusHistory::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['changedAt' => 'ASC'])]
    private Collection $statusHistory;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->status = OrderStatus::Pending;
        $this->totalPrice = '0.00';
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();

        $this->statusHistory->add(new OrderStatusHistory($this, OrderStatus::Pending, $user));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    /**
     * The only way to change order status.
     * Checks if particular status change is legal and logs the change to OrderStatusHistory.
     *
     * @throws \DomainException when transition is not allowed
     */
    public function changeStatus(OrderStatus $to, ?User $by): static
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new \DomainException(sprintf('Cannot change order status from "%s" to "%s"', $this->status->value, $to->value));
        }

        $this->status = $to;
        $this->statusHistory->add(new OrderStatusHistory($this, $to, $by));

        return $this;
    }

    public function getTotalPrice(): string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function getPromoCode(): ?PromoCode
    {
        return $this->promoCode;
    }

    public function setPromoCode(?PromoCode $promoCode): static
    {
        $this->promoCode = $promoCode;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(Product $product, int $quantity, string $unitPrice): OrderItem
    {
        $item = new OrderItem($this, $product, $quantity, $unitPrice);
        $this->items->add($item);

        return $item;
    }

    /** @return Collection<int, OrderStatusHistory> */
    public function getStatusHistory(): Collection
    {
        return $this->statusHistory;
    }
}
