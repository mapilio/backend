<?php

namespace App\Domain\IdentityAccess;

use RuntimeException;
use Throwable;

final class SocialTokenBridgeException extends RuntimeException
{
    private function __construct(string $message, private readonly int $status, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidCredentials(): self
    {
        return new self('Invalid social login credentials.', 401);
    }

    public static function unavailable(?Throwable $previous = null): self
    {
        return new self('Social login is temporarily unavailable.', 503, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }
}
