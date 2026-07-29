<?php

namespace App\Domain\DataMigration;

use Throwable;

/** Publishes owner-private JSON atomically, without replacing an existing path. */
final class PrivateJsonPublisher implements JsonPublisher
{
    public function publish(string $directory, string $filename, string $json): void
    {
        if (strlen($json) > ComputeImportSchemaFingerprint::MAX_BYTES) {
            throw new ImportSchemaDescriptorExtractionException('DESCRIPTOR_TOO_LARGE');
        }
        if ($directory === '' || is_link($directory)) {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_INVALID');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_INVALID');
        }
        if (! $this->privateDirectory($directory)) {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_INVALID');
        }
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        if (is_link($path) || file_exists($path)) {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_EXISTS');
        }

        $directoryIdentity = $this->identity($directory);
        $temporary = $directory.DIRECTORY_SEPARATOR.'.'.bin2hex(random_bytes(16)).'.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_WRITE_FAILED');
        }
        @chmod($temporary, 0600);
        $temporaryIdentity = $this->identity($temporary);
        try {
            $written = 0;
            while ($written < strlen($json)) {
                $chunk = @fwrite($handle, substr($json, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new ImportSchemaDescriptorExtractionException('OUTPUT_WRITE_FAILED');
                }
                $written += $chunk;
            }
            if (! @fflush($handle) || (function_exists('fsync') && ! @fsync($handle))) {
                throw new ImportSchemaDescriptorExtractionException('OUTPUT_WRITE_FAILED');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            $this->cleanup($temporary, $temporaryIdentity);
            throw $exception;
        }
        fclose($handle);

        if (! $this->same($directoryIdentity, $this->identity($directory)) || ! @link($temporary, $path)) {
            $this->cleanup($temporary, $temporaryIdentity);
            throw new ImportSchemaDescriptorExtractionException($this->existsAtPath($path) ? 'OUTPUT_EXISTS' : 'OUTPUT_WRITE_FAILED');
        }
        if (! @chmod($path, 0600) || ! $this->privateFile($path) || ! $this->same($temporaryIdentity, $this->identity($path))) {
            $publishedIdentity = $this->identity($path);
            if ($this->same($temporaryIdentity, $publishedIdentity)) {
                @unlink($path);
            }
            $this->cleanup($temporary, $temporaryIdentity);
            throw new ImportSchemaDescriptorExtractionException('OUTPUT_WRITE_FAILED');
        }
        $this->cleanup($temporary, $temporaryIdentity);
    }

    private function identity(string $path): ?array
    {
        $stat = @stat($path);

        return $stat === false ? null : ['device' => (int) $stat[0], 'inode' => (int) $stat[1]];
    }

    private function same(?array $left, ?array $right): bool
    {
        return $left !== null && $left === $right;
    }

    private function cleanup(string $path, ?array $identity): void
    {
        if (! is_link($path) && is_file($path) && $this->same($identity, $this->identity($path))) {
            @unlink($path);
        }
    }

    private function privateDirectory(string $path): bool
    {
        if (is_link($path) || ! is_dir($path)) {
            return false;
        }

        return DIRECTORY_SEPARATOR === '\\' || ((@fileperms($path) & 0077) === 0 && (! function_exists('posix_geteuid') || @fileowner($path) === posix_geteuid()));
    }

    private function privateFile(string $path): bool
    {
        if (is_link($path) || ! is_file($path)) {
            return false;
        }

        return DIRECTORY_SEPARATOR === '\\' || ((@fileperms($path) & 0077) === 0 && (! function_exists('posix_geteuid') || @fileowner($path) === posix_geteuid()));
    }

    private function existsAtPath(string $path): bool
    {
        return @lstat($path) !== false;
    }
}
