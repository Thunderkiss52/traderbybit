<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureQuoteIntegrityEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('quote_integrity.enabled', false)) {
            return response()->json([
                'error' => [
                    'code' => 'FEATURE_DISABLED',
                    'message' => 'Quote Integrity Monitor is disabled (QUOTE_INTEGRITY_ENABLED=false).',
                ],
            ], 503);
        }

        return $next($request);
    }
}
