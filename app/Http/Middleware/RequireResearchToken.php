<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireResearchToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('services.research_api_token');

        if ($configuredToken === null || $configuredToken === '') {
            return response()->json([
                'error' => [
                    'code' => 'AUTH_NOT_CONFIGURED',
                    'message' => 'Research API token is not configured on the server.',
                ],
            ], 500);
        }

        $providedToken = $request->header('X-Research-Token');
        if ($providedToken === null || $providedToken === '') {
            $providedToken = $request->bearerToken();
        }

        if ($providedToken === null || $providedToken === '') {
            return response()->json([
                'error' => [
                    'code' => 'TOKEN_MISSING',
                    'message' => 'Research API token is missing.',
                ],
            ], 401);
        }

        if (! hash_equals((string) $configuredToken, (string) $providedToken)) {
            return response()->json([
                'error' => [
                    'code' => 'TOKEN_INVALID',
                    'message' => 'Research API token is invalid.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
