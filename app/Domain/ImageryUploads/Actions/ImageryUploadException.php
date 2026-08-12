<?php

namespace App\Domain\ImageryUploads\Actions;

use RuntimeException;

class ImageryUploadException extends RuntimeException
{
    public static function missing(string $parameter): self
    {
        return new self("'{$parameter}' is required!", 400);
    }

    public static function tooManyPoints(int $received, int $limit): self
    {
        return new self("'json_data' accepts at most {$limit} points, {$received} received!", 400);
    }
}
