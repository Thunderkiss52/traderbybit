<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects browser-originated state-changing requests from foreign sites.
 *
 * The research API is protected by HTTP Basic credentials that browsers attach
 * automatically, and several deliberately client-compatible mutations do not use
 * the research token. Browsers expose the request origin through the
 * Sec-Fetch-Site header, which scripts cannot forge; non-browser clients (curl,
 * queue workers, tests) do not send it and remain unaffected.
 */
final class ValidateBrowserOrigin
{
    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $origin = rtrim((string) $request->headers->get('Origin', ''), '/');
        if ($origin !== '' && $this->isConfiguredOrigin($origin)) {
            return $next($request);
        }

        $fetchSite = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));
        if ($fetchSite !== '' && ! in_array($fetchSite, ['same-origin', 'none'], true)) {
            return $this->reject($request);
        }

        if ($origin === '') {
            return $next($request);
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        if (is_string($originHost) && strcasecmp($originHost, $request->getHost()) === 0) {
            return $next($request);
        }

        return $this->reject($request);
    }

    private function isConfiguredOrigin(string $origin): bool
    {
        $allowed = array_map(
            static fn (mixed $value): string => rtrim((string) $value, '/'),
            (array) config('cors.allowed_origins', []),
        );

        return in_array($origin, $allowed, true);
    }

    private function reject(Request $request): Response
    {
        return response()->json([
            'schema_version' => '1.0.0',
            'research_mode' => true,
            'error' => [
                'code' => 'ORIGIN_NOT_ALLOWED',
                'message' => 'State-changing requests are not allowed from this origin.',
                'request_id' => $request->attributes->get('request_id'),
            ],
        ], 403);
    }
}
