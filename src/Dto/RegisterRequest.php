<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Email is not valid.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.')]
    public string $password = '';
}
