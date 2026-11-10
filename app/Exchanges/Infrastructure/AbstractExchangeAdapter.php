<?php

declare(strict_types=1);

namespace App\Exchanges\Infrastructure;

use App\Exchanges\Contracts\ExtendedExchangeAdapter;
use App\Security\ExchangeUrlSecurityValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

abstract class AbstractExchangeAdapter implements ExtendedExchangeAdapter
{
    protected string $baseUrl;

    protected int $timeoutSeconds = 8;

    protected int $maxRetries = 3;

    abstract public function id(): string;

    abstract public function name(): string;

    /**
     * Bounded HTTP GET with exponential backoff on 429/5xx and connection exceptions.
     * Strictly read-only public market data. Zero credentials or trade endpoints.
     */
    protected function get(string $path, array $query = [], array $headers = []): array
    {
        $url = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))
            ? $path
            : rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        // Enforce SSRF protection on every outbound request
        app(ExchangeUrlSecurityValidator::class)->assertSafe($url);

        $delayMs = 150;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->withoutRedirecting()
                    ->withHeaders(array_merge([
                        'User-Agent' => 'VECTOR-Research/1.0 (Public Market Data; Non-Trading)',
                        'Accept' => 'application/json',
                    ], $headers))
                    ->get($url, $query);

                if ($response->status() === 429) {
                    if ($attempt === $this->maxRetries) {
                        throw new RuntimeException("Rate limit exceeded (HTTP 429) on {$this->id()} endpoint {$path}.");
                    }
                    Sleep::for($delayMs * $attempt)->milliseconds();

                    continue;
                }

                if ($response->serverError()) {
                    if ($attempt === $this->maxRetries) {
                        throw new RuntimeException("Server error ({$response->status()}) on {$this->id()} endpoint {$path}.");
                    }
                    Sleep::for($delayMs * $attempt)->milliseconds();

                    continue;
                }

                if (! $response->successful()) {
                    throw new RuntimeException("HTTP {$response->status()} error on {$this->id()} endpoint {$path}: {$response->body()}");
                }

                $json = $response->json();
                if (! is_array($json)) {
                    throw new RuntimeException("Non-JSON or malformed payload returned from {$this->id()} endpoint {$path}.");
                }

                return $json;
            } catch (ConnectionException $e) {
                if ($attempt === $this->maxRetries) {
                    throw new RuntimeException("Connection timeout or failure reaching {$this->id()} endpoint {$path}: {$e->getMessage()}", 0, $e);
                }
                Sleep::for($delayMs * $attempt)->milliseconds();
            }
        }

        throw new RuntimeException("Request attempts exhausted for {$this->id()} endpoint {$path}.");
    }

    public function healthCheck(): array
    {
        $start = microtime(true);
        try {
            $this->fetchTicker('BTCUSDT');
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'status' => 'HEALTHY',
                'latency_ms' => $latency,
                'endpoint' => $this->baseUrl,
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'error' => null,
            ];
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return [
                'status' => 'DEGRADED',
                'latency_ms' => $latency,
                'endpoint' => $this->baseUrl,
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Helper to safely format UTC ISO8601 timestamps from milliseconds or seconds.
     */
    protected static function utcTimestamp(int|float|string $timestamp): string
    {
        $ts = (float) $timestamp;
        if ($ts > 1e11) { // Milliseconds
            $seconds = (int) ($ts / 1000);
            $ms = (int) fmod($ts, 1000);

            return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%03dZ', $ms);
        }

        return gmdate('Y-m-d\TH:i:s\Z', (int) $ts);
    }

    /**
     * Strict non-negative decimal formatter.
     */
    protected static function decimal(mixed $value, string $field = 'value'): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing {$field} for decimal conversion.");
        }
        $str = (string) $value;
        if (! is_numeric($str)) {
            throw new InvalidArgumentException("Invalid numeric string for {$field}: '{$str}'");
        }

        return $str;
    }
}
