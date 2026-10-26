<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchanges\Binance\BinanceCandleIngestion;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BinanceCandleInsertionRaceTest extends TestCase
{
    #[DataProvider('races')]
    public function test_concurrent_insert_reloads_every_identity_and_preserves_winning_rows(bool $conflict, bool $allLost): void
    {
        $this->ensurePostgresTestDatabaseReady('market_candles');
        $db = DB::connection();
        if ($db->getConfig('host') !== '127.0.0.1') {
            $this->markTestSkipped('Race fixture requires the isolated loopback PostgreSQL service.');
        }
        $symbol = 'RACE'.strtoupper(bin2hex(random_bytes(6))).'USDT';
        config()->set('market_data.symbols', [$symbol]);
        Http::preventStrayRequests();
        $source = [];
        $winner = [];
        for ($index = 0; $index < 3; $index++) {
            $time = CarbonImmutable::parse('2026-01-01T00:00:00Z')->addMinutes(5 * $index);
            $ms = $time->getTimestamp() * 1000;
            $source[] = [$ms, '100', '105', '99', '102', '10', $ms + 299999, '1010'];
            if ($index > 0 || $allLost) {
                $winner[] = [
                    'exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol, 'interval' => '5',
                    'open_time' => $time->toIso8601String(), 'open' => '100', 'high' => '105', 'low' => '99',
                    // The final conflicting identity catches partial reload/validation implementations.
                    'close' => $conflict && $index === 2 ? '102.000000000001' : '102',
                    'volume' => '10', 'turnover' => '1010',
                    'created_at' => $time->toIso8601String(), 'updated_at' => $time->toIso8601String(),
                ];
            }
        }
        Http::fake(['https://fapi.binance.com/fapi/v1/klines*' => static function () use (&$source) {
            return Http::response($source);
        }]);
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/binance_candle_race_writer.php'),
            json_encode($winner, JSON_THROW_ON_ERROR), (string) $db->selectOne('select pg_backend_pid() as pid')->pid,
        ], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'bybit_test',
            'DB_PORT' => (string) $db->getConfig('port'), 'DB_USERNAME' => $db->getConfig('username'), 'DB_PASSWORD' => $db->getConfig('password'),
        ]);
        $process->setTimeout(15);
        $db->statement("SET statement_timeout = '15s'");
        $db->enableQueryLog();
        try {
            $process->start();
            self::assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            $db->flushQueryLog();
            try {
                $result = app(BinanceCandleIngestion::class)->ingest($symbol, '5m', 3);
                self::assertFalse($conflict, 'A conflicting race winner was silently accepted.');
                self::assertSame($allLost ? 0 : 1, $result['new_closed_candles']);
            } catch (DomainException $exception) {
                self::assertTrue($conflict);
                self::assertSame('Conflicting immutable Binance close observation.', $exception->getMessage());
            }
            self::assertCount(3, $db->getQueryLog(), 'One initial SELECT, one batch INSERT and one complete reload required.');
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertStringContainsString('COMMITTED_AFTER_INSERT_BLOCKED', $process->getOutput());
            $stored = $db->table('market_candles')->where('symbol', $symbol)->orderBy('open_time')->get();
            self::assertCount($conflict ? count($winner) : 3, $stored, 'Conflicting race must roll back newly inserted rows.');
            foreach ($winner as $row) {
                $persisted = $stored->first(fn ($candle) => CarbonImmutable::parse($candle->open_time)->equalTo($row['open_time']));
                self::assertNotNull($persisted);
                self::assertDecimalSame($row['close'], $persisted->close);
                self::assertTrue(CarbonImmutable::parse($persisted->updated_at)->equalTo($row['updated_at']));
            }
            // A corrected retry accepts the winner's immutable data and only adds any missing candle.
            if ($conflict) {
                $source[2][4] = '102.000000000001';
            }
            self::assertSame($conflict && ! $allLost ? 1 : 0, app(BinanceCandleIngestion::class)->ingest($symbol, '5m', 3)['new_closed_candles']);
            self::assertSame(0, app(BinanceCandleIngestion::class)->ingest($symbol, '5m', 3)['new_closed_candles']);
        } finally {
            $process->stop();
            $db->disableQueryLog();
            $db->statement('SET statement_timeout = 0');
            $db->table('market_candles')->where('exchange', 'binance')->where('symbol', $symbol)->delete();
        }
    }

    public static function races(): array
    {
        return [
            'identical subset' => [false, false],
            'conflicting final identity' => [true, false],
            'identical complete batch' => [false, true],
            'conflicting complete batch' => [true, true],
        ];
    }
}
