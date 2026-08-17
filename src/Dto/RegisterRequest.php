<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Email is not valid.')]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[Assert\NotBlank(message: 'Password is required.', normalizer: 'trim')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.')]
    private string $password = '';

    public function setEmail(string $email): void
    {
        $this->email = trim($email);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
}
