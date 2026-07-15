<?php

namespace App\Domain\ImagerySequences\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ResolveSequenceAddress
{
    private const STATUS_FOUND = 1;

    private const STATUS_NOT_FOUND = 2;

    private const STATUS_ERROR = 3;

    /**
     * @return array{resolved: bool, status: string, address: string|null, attempts: int}
     */
    public function resolve(string $sequenceUuid): array
    {
        if (! config('mapilio.address_enrichment.enabled')) {
            return [
                'resolved' => false,
                'status' => 'disabled',
                'address' => null,
                'attempts' => 0,
            ];
        }

        $connection = $this->legacyConnection();

        try {
            $detail = $this->sequenceDetail($connection, $sequenceUuid);
            $existingAddress = $this->existingAddress($connection, $detail, $sequenceUuid);

            if ($existingAddress !== null) {
                $this->storeFoundAddress($connection, $sequenceUuid, $existingAddress);

                return [
                    'resolved' => false,
                    'status' => 'existing',
                    'address' => $existingAddress,
                    'attempts' => 0,
                ];
            }

            $points = $this->candidatePoints($connection, $sequenceUuid);
            $attempts = 0;

            if ($points->isNotEmpty()) {
                $this->assertEndpoint();
            }

            foreach ($points as $point) {
                $attempts++;
                $address = $this->lookup((float) $point->latitude, (float) $point->longitude);

                if ($address === null) {
                    continue;
                }

                $this->storeFoundAddress($connection, $sequenceUuid, $address);

                return [
                    'resolved' => true,
                    'status' => 'found',
                    'address' => $address,
                    'attempts' => $attempts,
                ];
            }

            $this->updateSequence($connection, $sequenceUuid, [
                'address_status' => self::STATUS_NOT_FOUND,
                'address_status_message' => null,
                'updated_at' => now(),
            ]);

            return [
                'resolved' => false,
                'status' => 'not_found',
                'address' => null,
                'attempts' => $attempts,
            ];
        } catch (Throwable $exception) {
            $message = $exception instanceof SequenceAddressException
                ? Str::limit($exception->getMessage(), 1000, '')
                : 'Sequence address lookup could not be completed.';

            $this->updateSequence($connection, $sequenceUuid, [
                'address_status' => self::STATUS_ERROR,
                'address_status_message' => $message,
                'updated_at' => now(),
            ]);

            if ($exception instanceof SequenceAddressException) {
                throw $exception;
            }

            throw new SequenceAddressException($message, previous: $exception);
        }
    }

    private function sequenceDetail(ConnectionInterface $connection, string $sequenceUuid): object
    {
        $details = $connection->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->limit(2)
            ->get();

        if ($details->count() !== 1) {
            throw new SequenceAddressException('Sequence address lookup requires exactly one active sequence.');
        }

        return $details->first();
    }

    private function existingAddress(ConnectionInterface $connection, object $detail, string $sequenceUuid): ?string
    {
        $detailAddress = $this->normalizeAddress($detail->start_address ?? null);

        if ($detailAddress !== null) {
            return $detailAddress;
        }

        $imageryAddress = $connection->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->whereNotNull('capture_address')
            ->where('capture_address', '!=', '')
            ->orderBy('id')
            ->value('capture_address');

        return $this->normalizeAddress($imageryAddress);
    }

    /**
     * @return Collection<int, object>
     */
    private function candidatePoints(ConnectionInterface $connection, string $sequenceUuid)
    {
        return $connection->table('default_mapilio_imagery')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->where('anomaly', false)
            ->whereBetween('latitude', [-90, 90])
            ->whereBetween('longitude', [-180, 180])
            ->orderBy('id')
            ->limit(min(10, max(1, (int) config('mapilio.address_enrichment.max_point_attempts', 3))))
            ->get(['latitude', 'longitude']);
    }

    private function lookup(float $latitude, float $longitude): ?string
    {
        $response = Http::acceptJson()
            ->withoutRedirecting()
            ->connectTimeout((int) config('mapilio.address_enrichment.connect_timeout', 3))
            ->timeout((int) config('mapilio.address_enrichment.timeout', 8))
            ->withHeaders([
                'User-Agent' => (string) config('mapilio.address_enrichment.user_agent', 'MapilioBackend/1.0'),
            ])
            ->get((string) config('mapilio.address_enrichment.endpoint'), [
                'lat' => $latitude,
                'lon' => $longitude,
                'limit' => 1,
            ]);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new SequenceAddressException("Address provider request failed with HTTP {$response->status()}.");
        }

        return $this->addressFromPhoton($response);
    }

    private function addressFromPhoton(Response $response): ?string
    {
        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['features']) || ! is_array($payload['features'])) {
            throw new SequenceAddressException('Address provider returned an invalid response.');
        }

        foreach ($payload['features'] as $feature) {
            if (! is_array($feature) || ! is_array($feature['properties'] ?? null)) {
                continue;
            }

            foreach (['street', 'district', 'city', 'state', 'name'] as $field) {
                $address = $this->normalizeAddress($feature['properties'][$field] ?? null);

                if ($address !== null) {
                    return $address;
                }
            }
        }

        return null;
    }

    private function storeFoundAddress(ConnectionInterface $connection, string $sequenceUuid, string $address): void
    {
        $connection->transaction(function () use ($connection, $sequenceUuid, $address): void {
            $connection->table('default_mapilio_imagery')
                ->where('sequence_uuid', $sequenceUuid)
                ->whereNull('deleted_at')
                ->where('anomaly', false)
                ->where(function ($query): void {
                    $query->whereNull('capture_address')->orWhere('capture_address', '');
                })
                ->update([
                    'capture_address' => $address,
                    'updated_at' => now(),
                ]);

            $this->updateSequence($connection, $sequenceUuid, [
                'start_address' => $address,
                'address_status' => self::STATUS_FOUND,
                'address_status_message' => null,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateSequence(ConnectionInterface $connection, string $sequenceUuid, array $values): void
    {
        $values = $this->onlyExistingColumns('default_mapilio_sequence_detail', $values);

        if ($values === []) {
            return;
        }

        $connection->table('default_mapilio_sequence_detail')
            ->where('sequence_uuid', $sequenceUuid)
            ->whereNull('deleted_at')
            ->update($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        $schema = Schema::connection(config('mapilio.legacy_database_connection'));

        return array_filter(
            $values,
            static fn (string $column): bool => $schema->hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function normalizeAddress(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = Str::squish($value ?? '');

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private function assertEndpoint(): void
    {
        $endpoint = trim((string) config('mapilio.address_enrichment.endpoint'));
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);
        $user = parse_url($endpoint, PHP_URL_USER);
        $password = parse_url($endpoint, PHP_URL_PASS);

        if (
            $endpoint === ''
            || ! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
            || $user !== null
            || $password !== null
        ) {
            throw new SequenceAddressException('Address provider endpoint must be a valid HTTP URL.');
        }

        if (app()->environment('production') && $scheme !== 'https') {
            throw new SequenceAddressException('Address provider endpoint must use HTTPS in production.');
        }
    }

    private function legacyConnection(): ConnectionInterface
    {
        return DB::connection(config('mapilio.legacy_database_connection'));
    }
}
