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
        $token = config('ui.backtest_token');
        if (! is_string($token) || $token === '') {
            return response()->json([
                'error' => ['code' => 'research_write_disabled', 'message' => 'Research job creation is disabled.'],
            ], 503);
        }

        $provided = $request->header('X-Research-Token') ?: $request->bearerToken();
        if (! hash_equals($token, (string) $provided)) {
            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'A valid research token is required.'],
            ], 401);
        }

        return $next($request);
    }
}
