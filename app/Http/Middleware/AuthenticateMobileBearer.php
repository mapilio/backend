<?php

namespace App\Http\Middleware;

use App\Domain\IdentityAccess\LegacyMobileAuth;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileBearer
{
    public function __construct(private readonly LegacyMobileAuth $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->userFromBearer($request->header('Authorization'));

        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('mapilio_mobile_user', $user);

        return $next($request);
    }
}
