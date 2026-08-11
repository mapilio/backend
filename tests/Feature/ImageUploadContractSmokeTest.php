<?php

namespace Tests\Feature;

use App\Domain\ImagerySequences\Actions\RunImageUploadContractSmoke;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionClassConstant;
use Tests\TestCase;

class ImageUploadContractSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mapilio.image_upload_smoke', [
            'enabled' => true,
            'base_url' => 'https://images.staging.example.test',
            'allowed_hosts' => 'images.staging.example.test',
            'connect_timeout' => 1,
            'request_timeout' => 2,
            'chunk_size' => 128,
            'poll_attempts' => 2,
            'poll_delay_ms' => 0,
        ]);
    }

    public function test_command_requires_explicit_write_confirmation(): void
    {
        Http::fake();

        $this->artisan('mapilio:smoke-image-upload')
            ->expectsOutputToContain('Refusing to write')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_embedded_mobile_fixture_is_a_decodable_jpeg(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $constant = new ReflectionClassConstant(RunImageUploadContractSmoke::class, 'JPEG_BASE64');
        $jpeg = base64_decode($constant->getValue(), true);
        $image = is_string($jpeg) ? imagecreatefromstring($jpeg) : false;

        $this->assertIsString($jpeg);
        $this->assertNotFalse($image);

        $this->assertSame(2, imagesx($image));
        $this->assertSame(2, imagesy($image));
        imagedestroy($image);
    }

    public function test_production_cdn_target_is_rejected_even_when_allowlisted(): void
    {
        config()->set('mapilio.image_upload_smoke.base_url', 'https://cdn.mapilio.com');
        config()->set('mapilio.image_upload_smoke.allowed_hosts', 'cdn.mapilio.com');
        Http::fake();

        $this->artisan('mapilio:smoke-image-upload', ['--confirm-write' => true])
            ->expectsOutputToContain('not an approved non-production target')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_laravel_production_environment_is_always_rejected(): void
    {
        Http::fake();
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->artisan('mapilio:smoke-image-upload', ['--confirm-write' => true])
                ->expectsOutputToContain('cannot run in production')
                ->assertFailed();
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }

        Http::assertNothingSent();
    }

    public function test_target_must_be_a_credential_free_https_origin(): void
    {
        Http::fake();

        foreach ([
            'http://images.staging.example.test',
            'https://user@images.staging.example.test',
            'https://images.staging.example.test/path',
            'https://images.staging.example.test?target=other',
        ] as $invalidUrl) {
            config()->set('mapilio.image_upload_smoke.base_url', $invalidUrl);

            $this->artisan('mapilio:smoke-image-upload', ['--confirm-write' => true])
                ->expectsOutputToContain('credential-free HTTPS origin')
                ->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_full_staging_contract_covers_mobile_chunk_resume_and_image_resolution(): void
    {
        $offset = 0;
        $imageAttempts = [];

        Http::fake(function (Request $request) use (&$offset, &$imageAttempts) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_ends_with($url, '/api/upload/mobile')) {
                return Http::response([
                    'files' => [['hash' => 'mobile-opaque-hash']],
                ]);
            }

            if ($request->method() === 'GET' && str_contains($url, '/upload/')) {
                return Http::response(['totalChunkUploaded' => $offset]);
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/upload/')) {
                preg_match('/bytes=(\d+)-(\d+)\/(\d+)/', (string) $request->header('Content-Range')[0], $range);
                $start = (int) ($range[1] ?? -1);
                $total = (int) ($range[3] ?? -1);

                if ($start === $total) {
                    return Http::response(['hash' => 'chunk-opaque-hash']);
                }

                $offset = min($start + 128, $total);

                return Http::response('', 200);
            }

            if ($request->method() === 'GET' && str_contains($url, '/im/')) {
                $imageAttempts[$url] = ($imageAttempts[$url] ?? 0) + 1;

                return $imageAttempts[$url] === 1
                    ? Http::response('', 404)
                    : Http::response('jpeg', 206, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response([], 500);
        });

        $this->artisan('mapilio:smoke-image-upload', ['--confirm-write' => true])
            ->expectsOutputToContain('mobile upload returned files[0].hash')
            ->expectsOutputToContain('chunk upload resumed from server offset')
            ->expectsOutputToContain('chunk finalization returned hash')
            ->expectsOutputToContain('Smoke artifacts are not deleted automatically')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://images.staging.example.test/api/upload/mobile'
                && $request->hasFile('file')
                && collect($request->data())->contains(
                    static fn (array $part): bool => ($part['name'] ?? null) === 'email'
                        && str_ends_with((string) ($part['contents'] ?? ''), '@example.invalid'),
                );
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://images.staging.example.test/upload/'
                && $request->hasHeader('X-File-Id')
                && $request->hasHeader('Content-Range');
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/im/mobile-opaque-hash/')
                && str_ends_with($request->url(), '/480');
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/im/chunk-opaque-hash/')
                && str_ends_with($request->url(), '/480');
        });
    }
}
