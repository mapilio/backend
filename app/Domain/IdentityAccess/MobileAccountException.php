<?php

namespace App\Domain\IdentityAccess;

use RuntimeException;
use Throwable;

class MobileAccountException extends RuntimeException
{
    /**
     * @param  string|array<string, list<string>>  $publicMessage
     */
    public function __construct(
        private readonly array|string $publicMessage,
        int $status = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Mobile account operation failed.', $status, $previous);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validation(array $errors): self
    {
        return new self($errors);
    }

    /**
     * @return string|array<string, list<string>>
     */
    public function publicMessage(): array|string
    {
        return $this->publicMessage;
    }
}
