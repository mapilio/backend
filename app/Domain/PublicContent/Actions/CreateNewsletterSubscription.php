<?php

namespace App\Domain\PublicContent\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class CreateNewsletterSubscription
{
    public function create(string $email): void
    {
        $baseUrl = rtrim(trim((string) config('services.mailcoach.base_url')), '/');
        $token = trim((string) config('services.mailcoach.token'));
        $listId = trim((string) config('services.mailcoach.list_id'));

        $this->assertConfiguration($baseUrl, $token, $listId);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->withoutRedirecting()
                ->connectTimeout(max(1, (int) config('services.mailcoach.connect_timeout', 3)))
                ->timeout(max(1, (int) config('services.mailcoach.timeout', 8)))
                ->withHeaders([
                    'Idempotency-Key' => hash('sha256', $email),
                ])
                ->post("{$baseUrl}/api/email-lists/{$listId}/subscribers", [
                    'email' => $email,
                    'skip_confirmation' => (bool) config('services.mailcoach.skip_confirmation', true),
                ]);
        } catch (ConnectionException $exception) {
            throw new NewsletterSubscriptionException('Newsletter provider could not be reached.', previous: $exception);
        } catch (Throwable $exception) {
            throw new NewsletterSubscriptionException('Newsletter request could not be completed.', previous: $exception);
        }

        // Mailcoach uses 422 for an already-subscribed address; this public operation is idempotent.
        if (! $response->successful() && $response->status() !== 422) {
            throw new NewsletterSubscriptionException('Newsletter provider rejected the request.');
        }
    }

    private function assertConfiguration(string $baseUrl, string $token, string $listId): void
    {
        $parts = parse_url($baseUrl);

        if (
            $baseUrl === ''
            || ! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || $token === ''
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $listId) !== 1
        ) {
            throw new NewsletterSubscriptionException('Newsletter provider is not configured.');
        }

        if (app()->environment('production') && $parts['scheme'] !== 'https') {
            throw new NewsletterSubscriptionException('Newsletter provider must use HTTPS in production.');
        }
    }
}
