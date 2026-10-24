<?php

declare(strict_types=1);

namespace Tests\Unit\Exchanges;

use App\Exchanges\Binance\BinancePublicAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

final class BinancePublicTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_transient_public_get_recovers_with_bounded_backoff(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 502)->push([]);
        self::assertSame([], app(BinancePublicAdapter::class)->fetchCandles('BTCUSDT', '5m', 1));
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(200)->milliseconds(), Sleep::for(400)->milliseconds()]);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-MBX-APIKEY'));
    }

    public function test_retry_after_is_respected_for_short_rate_limit(): void
    {
        Http::fakeSequence()->push([], 429, ['Retry-After' => '2'])->push([]);
        self::assertSame([], app(BinancePublicAdapter::class)->fetchCandles('ETHUSDT', '5m', 1));
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
    }

    public function test_bans_long_or_unknown_rate_limits_and_permanent_errors_are_not_retried(): void
    {
        foreach ([[418, '2'], [429, '120'], [429, 'invalid'], [429, null], [400, null], [451, null]] as [$status, $retry]) {
            Http::fake(['*' => Http::response(['msg' => 'PRIVATE_ERROR_MUST_NOT_ESCAPE'], $status,
                $retry === null ? [] : ['Retry-After' => $retry])]);
            try {
                app(BinancePublicAdapter::class)->fetchCandles('BTCUSDT', '5m', 1);
                self::fail('Unavailable source accepted.');
            } catch (RuntimeException $exception) {
                self::assertSame('Binance public market data unavailable.', $exception->getMessage());
            }
            Http::assertSentCount(1);
        }
        Sleep::assertNeverSlept();
    }

    public function test_retry_budget_is_finite(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        try {
            app(BinancePublicAdapter::class)->fetchCandles('BTCUSDT', '5m', 1);
            self::fail('Unavailable source accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('Binance public market data unavailable.', $exception->getMessage());
        }
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_connection_failure_is_bounded_and_redacted(): void
    {
        Http::fake(['*' => Http::failedConnection('PRIVATE_TRANSPORT_DETAILS')]);
        try {
            app(BinancePublicAdapter::class)->fetchCandles('BTCUSDT', '5m', 1);
            self::fail('Unavailable source accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('Binance public market data unavailable.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        Sleep::assertSleptTimes(2);
    }

    public function test_redirect_following_is_disabled(): void
    {
        Http::fake(function ($request, $options) {
            self::assertFalse($options['allow_redirects']);

            return Http::response([], 302, ['Location' => 'https://example.invalid/private']);
        });
        $this->expectException(RuntimeException::class);
        app(BinancePublicAdapter::class)->fetchCandles('BTCUSDT', '5m', 1);
    }

    public function test_success_status_with_exchange_error_payload_fails_closed(): void
    {
        Http::fake(['*' => Http::response(['code' => -1121, 'msg' => 'PRIVATE_ERROR_MUST_NOT_ESCAPE'])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Binance public market data unavailable.');
        app(BinancePublicAdapter::class)->fetchCandles('BTCUSDT', '5m', 1);
    }
}
