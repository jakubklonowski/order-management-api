<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\OrderStatus;
use Symfony\Component\Validator\Constraints as Assert;

final class OrderStatusRequest
{
    // typing the property as enum is what rejects an unknown status string,
    // so only assertion for null values (from missing field in request) is needed
    #[Assert\NotNull(message: 'Status is required.')]
    private ?OrderStatus $status = null;

    public function setStatus(?OrderStatus $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): ?OrderStatus
    {
        return $this->status;
    }
}
