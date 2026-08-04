<?php

namespace App\Domain\IdentityAccess;

use RuntimeException;
use Throwable;

class LegacyMobileAuthException extends RuntimeException
{
    /**
     * @param  string|list<string>|array<string, list<string>>  $legacyMessage
     */
    public function __construct(
        private readonly array|string $legacyMessage,
        int $code = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Legacy mobile authentication failed.', $code, $previous);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validation(array $errors): self
    {
        return new self($errors, 400);
    }

    public static function invalidCredentials(): self
    {
        return new self(['Email or password is invalid.'], 400);
    }

    public static function inactiveAccount(): self
    {
        return new self('This account is inactive.', 400);
    }

    /**
     * @return string|list<string>|array<string, list<string>>
     */
    public function legacyMessage(): array|string
    {
        return $this->legacyMessage;
    }
}
