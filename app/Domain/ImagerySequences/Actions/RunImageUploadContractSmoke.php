<?php

namespace App\Domain\ImagerySequences\Actions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class RunImageUploadContractSmoke
{
    private const JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAgACAwERAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4yv2s/lc/9k=';

    /**
     * @return array{checks: list<string>, artifacts: array<string, string>}
     */
    public function run(string $mode): array
    {
        $mode = strtolower($mode);

        if (! in_array($mode, ['all', 'mobile', 'chunk'], true)) {
            throw new RuntimeException('Smoke mode must be all, mobile, or chunk.');
        }

        $configuration = $this->configuration();
        $runId = Str::lower((string) Str::ulid());
        $email = sprintf('mapilio-smoke+%s@example.invalid', $runId);
        $checks = [];
        $artifacts = ['email' => $email];

        if (in_array($mode, ['all', 'mobile'], true)) {
            [$mobileChecks, $mobileArtifacts] = $this->runMobile($configuration, $email, $runId);
            $checks = [...$checks, ...$mobileChecks];
            $artifacts = [...$artifacts, ...$mobileArtifacts];
        }

        if (in_array($mode, ['all', 'chunk'], true)) {
            [$chunkChecks, $chunkArtifacts] = $this->runChunked($configuration, $email, $runId);
            $checks = [...$checks, ...$chunkChecks];
            $artifacts = [...$artifacts, ...$chunkArtifacts];
        }

        return compact('checks', 'artifacts');
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{list<string>, array<string, string>}
     */
    private function runMobile(array $configuration, string $email, string $runId): array
    {
        $filename = "mapilio-smoke-mobile-{$runId}.jpg";
        $response = $this->request($configuration)
            ->attach('file', $this->jpeg(), $filename, ['Content-Type' => 'image/jpeg'])
            ->post($this->url($configuration, config('mapilio.image_server.mobile_upload_path')), [
                'email' => $email,
            ]);

        $this->assertSuccessful($response, 'Mobile image upload');
        $hash = $this->hashFrom($response->json('files.0.hash'), 'Mobile image upload');
        $this->assertImageServed($configuration, $hash, $filename, 480);

        return [
            ['mobile upload returned files[0].hash', 'mobile 480 image URL resolved'],
            ['mobile_filename' => $filename],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{list<string>, array<string, string>}
     */
    private function runChunked(array $configuration, string $email, string $runId): array
    {
        $imageFilename = "mapilio-smoke-chunk-{$runId}.jpg";
        $session = "mapilio_smoke_{$runId}.zip";
        $archive = $this->zip($imageFilename);
        $total = strlen($archive);
        $chunkSize = min(max(1, (int) $configuration['chunk_size']), $total);
        $offset = $this->fetchOffset($configuration, $session, $email);

        if ($offset !== 0) {
            throw new RuntimeException('Chunk smoke session unexpectedly existed before upload.');
        }

        $firstChunk = substr($archive, 0, $chunkSize);
        $this->uploadChunk($configuration, $session, $email, $firstChunk, 0, $total);
        $resumedOffset = $this->fetchOffset($configuration, $session, $email);

        if ($resumedOffset !== strlen($firstChunk)) {
            throw new RuntimeException('Chunk offset did not match the first uploaded part.');
        }

        $offset = $resumedOffset;

        while ($offset < $total) {
            $chunk = substr($archive, $offset, $chunkSize);
            $this->uploadChunk($configuration, $session, $email, $chunk, $offset, $total);
            $offset += strlen($chunk);
        }

        $storedOffset = $this->fetchOffset($configuration, $session, $email);

        if ($storedOffset !== $total) {
            throw new RuntimeException('Chunk offset did not reach the complete archive size.');
        }

        $response = $this->request($configuration)
            ->withHeaders($this->chunkHeaders($session, $email, $total, $total))
            ->post($this->chunkUrl($configuration));

        $this->assertSuccessful($response, 'Chunk upload finalization');
        $hash = $this->hashFrom($response->json('hash'), 'Chunk upload finalization');
        $this->assertImageServed($configuration, $hash, $imageFilename, 480);

        return [
            [
                'chunk offset initialized at zero',
                'chunk upload resumed from server offset',
                'chunk finalization returned hash',
                'chunk 480 image URL resolved',
            ],
            ['chunk_session' => $session, 'chunk_image_filename' => $imageFilename],
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function uploadChunk(
        array $configuration,
        string $session,
        string $email,
        string $chunk,
        int $offset,
        int $total,
    ): void {
        $response = $this->request($configuration)
            ->withHeaders($this->chunkHeaders($session, $email, $offset, $total))
            ->attach('chunk', $chunk, $session, ['Content-Type' => 'application/octet-stream'])
            ->post($this->chunkUrl($configuration));

        $this->assertSuccessful($response, 'Chunk upload');
    }

    /** @param array<string, mixed> $configuration */
    private function fetchOffset(array $configuration, string $session, string $email): int
    {
        $response = $this->request($configuration)->get($this->chunkUrl($configuration), [
            'fileName' => $session,
            'email' => $email,
        ]);

        $this->assertSuccessful($response, 'Chunk offset lookup');
        $offset = $response->json('totalChunkUploaded');

        if (! is_int($offset) || $offset < 0) {
            throw new RuntimeException('Chunk offset lookup returned an invalid offset.');
        }

        return $offset;
    }

    /** @param array<string, mixed> $configuration */
    private function assertImageServed(array $configuration, string $hash, string $filename, int $size): void
    {
        $url = $this->url(
            $configuration,
            sprintf(
                '/%s/%s/%s/%d',
                trim((string) config('mapilio.image_server.image_path_prefix', 'im'), '/'),
                rawurlencode($hash),
                rawurlencode($filename),
                $size,
            ),
        );

        $attempts = max(1, (int) $configuration['poll_attempts']);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->request($configuration)
                ->withHeader('Range', 'bytes=0-0')
                ->get($url);

            if (in_array($response->status(), [200, 206], true)
                && str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/')) {
                return;
            }

            if ($attempt < $attempts && (int) $configuration['poll_delay_ms'] > 0) {
                usleep((int) $configuration['poll_delay_ms'] * 1000);
            }
        }

        throw new RuntimeException("Generated {$size}px image URL did not resolve in time.");
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        $configuration = config('mapilio.image_upload_smoke', []);

        if (! (bool) ($configuration['enabled'] ?? false)) {
            throw new RuntimeException('Image upload smoke testing is disabled.');
        }

        if (app()->environment('production')) {
            throw new RuntimeException('Image upload smoke testing cannot run in production.');
        }

        $baseUrl = rtrim((string) ($configuration['base_url'] ?? ''), '/');
        $parts = parse_url($baseUrl);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Image upload smoke base URL must be a credential-free HTTPS origin.');
        }

        $host = strtolower($parts['host']);
        $allowedHosts = array_values(array_filter(array_map(
            static fn (string $allowed): string => strtolower(trim($allowed)),
            explode(',', (string) ($configuration['allowed_hosts'] ?? '')),
        )));

        if ($host === 'cdn.mapilio.com' || ! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException('Image upload smoke host is not an approved non-production target.');
        }

        $configuration['base_url'] = $baseUrl;

        return $configuration;
    }

    /** @param array<string, mixed> $configuration */
    private function request(array $configuration): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(max(1, (int) $configuration['connect_timeout']))
            ->timeout(max(1, (int) $configuration['request_timeout']))
            ->withoutRedirecting();
    }

    /** @param array<string, mixed> $configuration */
    private function chunkUrl(array $configuration): string
    {
        return $this->url($configuration, config('mapilio.image_server.chunk_upload_path'));
    }

    /** @param array<string, mixed> $configuration */
    private function url(array $configuration, ?string $path): string
    {
        return $configuration['base_url'].'/'.ltrim((string) $path, '/');
    }

    /** @return array<string, string> */
    private function chunkHeaders(string $session, string $email, int $offset, int $total): array
    {
        return [
            'Content-Range' => "bytes={$offset}-{$total}/{$total}",
            'X-File-Id' => $session,
            'email' => $email,
        ];
    }

    private function hashFrom(mixed $hash, string $operation): string
    {
        if (! is_string($hash)
            || $hash === ''
            || strlen($hash) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $hash) === 1) {
            throw new RuntimeException("{$operation} returned an invalid hash.");
        }

        return $hash;
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("{$operation} failed with HTTP {$response->status()}.");
        }
    }

    private function jpeg(): string
    {
        $jpeg = base64_decode(self::JPEG_BASE64, true);

        if ($jpeg === false) {
            throw new RuntimeException('Embedded smoke JPEG is invalid.');
        }

        return $jpeg;
    }

    private function zip(string $filename): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The optional PHP zip extension is required for chunk smoke testing.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mapilio-upload-smoke-');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary smoke archive.');
        }

        $archive = new ZipArchive;
        $opened = false;

        try {
            if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to build the temporary smoke archive.');
            }

            $opened = true;

            if (! $archive->addFromString($filename, $this->jpeg())) {
                throw new RuntimeException('Unable to build the temporary smoke archive.');
            }

            $closed = $archive->close();
            $opened = false;

            if (! $closed) {
                throw new RuntimeException('Unable to build the temporary smoke archive.');
            }

            $contents = file_get_contents($path);

            if ($contents === false || $contents === '') {
                throw new RuntimeException('Temporary smoke archive is empty.');
            }

            return $contents;
        } finally {
            if ($opened) {
                $archive->close();
            }

            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
