<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Domain\PublicContent\Actions\CreateNewsletterSubscription;
use App\Domain\PublicContent\Actions\NewsletterSubscriptionException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterSubscriptionController extends Controller
{
    public function __invoke(Request $request, CreateNewsletterSubscription $subscriptions): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if (trim((string) ($validated['website'] ?? '')) !== '') {
            return $this->accepted();
        }

        $email = mb_strtolower(trim($validated['email']));

        try {
            $subscriptions->create($email);
        } catch (NewsletterSubscriptionException) {
            return response()->json([
                'message' => 'Subscription service is temporarily unavailable.',
            ], 503);
        }

        return $this->accepted();
    }

    private function accepted(): JsonResponse
    {
        return response()->json([
            'message' => 'Subscription has been received.',
        ], 202);
    }
}
