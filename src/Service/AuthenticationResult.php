<?php

namespace App\Service;

use App\Entity\User;

class AuthenticationResult
{
    public function __construct(
        private bool $success,
        private ?User $user,
        private string $message
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}