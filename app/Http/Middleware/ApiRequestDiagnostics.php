<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApiRequestDiagnostics
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Log::error('API request failed.', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $exception::class,
            ]);
            throw $exception;
        }

        $payload = $response instanceof JsonResponse ? $response->getData(true) : [];
        $featureDisabled = $response->getStatusCode() === 503 && ($payload['error']['code'] ?? null) === 'FEATURE_DISABLED';
        if ($response->getStatusCode() >= 500 && ! config('app.debug') && ! $featureDisabled) {
            $response = response()->json([
                'schema_version' => '1.0.0',
                'research_mode' => true,
                'error' => [
                    'code' => 'server_error',
                    'message' => 'The server could not complete the request.',
                    'request_id' => $requestId,
                ],
            ], $response->getStatusCode());
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        $bytes = strlen((string) $response->getContent());
        if ($durationMs >= (int) config('ui.slow_api_ms', 500) || $bytes >= (int) config('ui.large_response_bytes', 100_000)) {
            Log::warning('Slow or large API response.', [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round($durationMs, 2),
                'response_bytes' => $bytes,
            ]);
        }
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
