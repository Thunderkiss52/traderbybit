<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The cache stores refuse to unserialise PHP objects (config/cache.php serializable_classes = false),
 * therefore every cached market payload must consist of scalars and arrays only.
 */
final class MarketCacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/market-cache-'.bin2hex(random_bytes(8));
        config(['cache.default' => 'file', 'cache.stores.file.path' => $this->cachePath]);
        Cache::forgetDriver('file');
        $this->travelTo(CarbonImmutable::parse('2026-10-22T12:00:00Z'));
        foreach (range(0, 2) as $i) {
            CandleRecord::query()->create([
                'exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => '15',
                'open_time' => now('UTC')->subHour()->addMinutes($i * 15),
                'open' => '60000.12345678', 'high' => '60100', 'low' => '59900', 'close' => '60050',
                'volume' => '10', 'turnover' => '600000', 'is_closed' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cachePath);
        parent::tearDown();
    }

    public function test_candles_endpoint_survives_restricted_cache_round_trip(): void
    {
        self::assertFalse(config('cache.serializable_classes'));

        $url = '/api/v1/market/BTCUSDT/candles?interval=15';
        $cold = $this->getJson($url)->assertOk()->json('data');
        $warm = $this->getJson($url)->assertOk()->json('data');

        self::assertSame($cold, $warm);
        self::assertCount(3, $warm);
        self::assertIsString($warm[0]['open']);
        self::assertSame($cold[0]['open'], $warm[0]['open']);
        self::assertSame('BTCUSDT', $warm[0]['symbol']);
        self::assertTrue($warm[0]['is_closed']);
        self::assertNotFalse($warm[0]['open_time']);
    }

    public function test_indicators_endpoint_survives_restricted_cache_round_trip(): void
    {
        $url = '/api/v1/market/BTCUSDT/indicators?interval=15';
        $cold = $this->getJson($url)->assertOk()->json('data');
        $warm = $this->getJson($url)->assertOk()->json('data');

        self::assertSame($cold, $warm);
        self::assertCount(3, $warm['times']);
        foreach ($warm['times'] as $time) {
            self::assertIsString($time);
        }
    }

    public function test_analysis_endpoint_survives_restricted_cache_round_trip(): void
    {
        $url = '/api/v1/market/BTCUSDT/analysis?interval=15';
        $cold = $this->getJson($url)->assertOk()->json('data');
        $warm = $this->getJson($url)->assertOk()->json('data');

        self::assertSame($cold, $warm);
        self::assertCount(3, $warm['candles']);
        self::assertSame($cold['candles'][0]['open'], $warm['candles'][0]['open']);
        self::assertIsString($warm['candles'][0]['open']);
        self::assertSame($cold['candles'][0]['open_time'], $warm['candles'][0]['open_time']);
        self::assertSame($cold['indicators']['times'], $warm['indicators']['times']);
        self::assertNull($warm['context']);
        self::assertSame([], $warm['candlestick_patterns']);
        self::assertSame([], $warm['signals']);
    }

    public function test_cached_market_payloads_contain_no_objects(): void
    {
        $this->getJson('/api/v1/market/BTCUSDT/candles?interval=15')->assertOk();
        $this->getJson('/api/v1/market/BTCUSDT/indicators?interval=15')->assertOk();
        $this->getJson('/api/v1/market/BTCUSDT/analysis?interval=15')->assertOk();

        $files = File::allFiles($this->cachePath);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $contents = $file->getContents();
            self::assertGreaterThan(10, strlen($contents));
            $payload = unserialize(substr($contents, 10), ['allowed_classes' => false]);
            $this->assertPortable($payload, $file->getFilename());
        }
    }

    private function assertPortable(mixed $value, string $path): void
    {
        if (is_object($value)) {
            self::fail(sprintf('Cached payload %s contains an object of type %s.', $path, $value::class));
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertPortable($item, $path.'['.(string) $key.']');
            }
        }
    }
}
