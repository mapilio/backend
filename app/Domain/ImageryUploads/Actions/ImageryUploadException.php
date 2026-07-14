<?php

namespace App\Domain\ImageryUploads\Actions;

use RuntimeException;

class ImageryUploadException extends RuntimeException
{
    public static function missing(string $parameter): self
    {
        return new self("'{$parameter}' is required!", 400);
    }
}
