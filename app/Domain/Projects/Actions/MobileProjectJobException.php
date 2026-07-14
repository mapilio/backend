<?php

namespace App\Domain\Projects\Actions;

use RuntimeException;

class MobileProjectJobException extends RuntimeException
{
    public static function projectNotFound(): self
    {
        return new self('Project not found!', 403);
    }

    public static function projectNotEligible(): self
    {
        return new self('This project is not eligible.', 500);
    }

    public static function alreadyMember(): self
    {
        return new self('You are a member of this project', 500);
    }
}
