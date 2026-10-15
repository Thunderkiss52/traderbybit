<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Http;

use App\MarketData\Contracts\HttpTransport;
use App\MarketData\Exceptions\TransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

final readonly class LaravelHttpTransport implements HttpTransport
{
    public function __construct(
        private string $baseUrl,
        private int $timeoutSeconds,
        private int $connectTimeoutSeconds,
    ) {}

    public function get(string $path, array $query): TransportResponse
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->get($path, $query);

            try {
                $json = $response->json();
            } catch (JsonException) {
                $json = null;
            }

            return new TransportResponse(
                status: $response->status(),
                json: is_array($json) ? $json : null,
                headers: $response->headers(),
            );
        } catch (ConnectionException $exception) {
            throw new TransportException('Bybit HTTP connection failed.', previous: $exception);
        }
    }
}
