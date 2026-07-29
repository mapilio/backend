<?php

namespace Tests\Feature\Database;

use App\Domain\DataMigration\ComputeImportSchemaFingerprint;
use App\Domain\DataMigration\ImportSchemaDescriptorExtractionException;
use App\Domain\DataMigration\PrivateJsonPublisher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PrivateJsonPublisherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/mapilio-schema-publisher-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->directory, 0700, true);
        Config::set('app.env', 'testing');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_real_publication_is_private_atomic_and_consumable_by_fingerprinter(): void
    {
        $json = json_encode([
            'schema_version' => 1,
            'fingerprint_algorithm' => 'mapilio-schema-fingerprint-v1',
            'engine' => 'postgresql',
            'schema' => 'legacy',
            'table' => 'users',
            'columns' => [[
                'position' => 1, 'name' => 'id', 'type_schema' => 'pg_catalog', 'type_name' => 'int4',
                'nullable' => false, 'character_length' => null, 'numeric_precision' => 32,
                'numeric_scale' => 0, 'datetime_precision' => null,
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        (new PrivateJsonPublisher)->publish($this->directory, 'descriptor.json', $json);

        $path = $this->directory.'/descriptor.json';
        $this->assertSame(0700, fileperms($this->directory) & 0777);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame($json, File::get($path));
        $result = (new ComputeImportSchemaFingerprint)->compute($path);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->fingerprint);
        $this->assertSame([], File::glob($this->directory.'/.'.'*.tmp'));
    }

    public function test_existing_and_symlink_destinations_are_not_overwritten(): void
    {
        File::put($this->directory.'/existing.json', 'original');
        try {
            (new PrivateJsonPublisher)->publish($this->directory, 'existing.json', '{}');
            $this->fail('Expected no-overwrite failure.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('OUTPUT_EXISTS', $exception->reasonCode);
        }
        File::put($this->directory.'/target.json', 'target');
        symlink($this->directory.'/target.json', $this->directory.'/linked.json');
        try {
            (new PrivateJsonPublisher)->publish($this->directory, 'linked.json', '{}');
            $this->fail('Expected symlink rejection.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('OUTPUT_EXISTS', $exception->reasonCode);
        }
        $this->assertSame('original', File::get($this->directory.'/existing.json'));
        $this->assertSame('target', File::get($this->directory.'/target.json'));
    }

    public function test_oversized_compact_json_is_rejected_before_publication(): void
    {
        $json = str_repeat('x', ComputeImportSchemaFingerprint::MAX_BYTES + 1);
        try {
            (new PrivateJsonPublisher)->publish($this->directory, 'large.json', $json);
            $this->fail('Expected size failure.');
        } catch (ImportSchemaDescriptorExtractionException $exception) {
            $this->assertSame('DESCRIPTOR_TOO_LARGE', $exception->reasonCode);
        }
        $this->assertFileDoesNotExist($this->directory.'/large.json');
    }
}
