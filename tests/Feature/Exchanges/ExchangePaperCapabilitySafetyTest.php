<?php

declare(strict_types=1);

namespace Tests\Feature\Exchanges;

use App\Exchanges\Application\ExchangeCapabilityValidator;
use App\Exchanges\Application\ExchangeStrategyCompatibilityService;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExchangePaperCapabilitySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rest_adapter_and_declared_costs_do_not_establish_an_execution_model(): void
    {
        foreach (['coinbase', 'upbit', 'okx', 'bitget', 'gate', 'kucoin', 'kraken', 'htx'] as $id) {
            $result = app(ExchangeCapabilityValidator::class)->evaluate(ExchangeRecord::query()->findOrFail($id));
            self::assertFalse($result['eligible'], $id);
            self::assertFalse($result['checks']['has_execution_model'], $id);
            self::assertFalse($result['checks']['has_fresh_context_and_constraints'], $id);
        }
    }

    public function test_history_requires_every_timeframe_and_rejects_open_future_or_gapped_bars(): void
    {
        Carbon::setTestNow('2026-11-07T12:00:00Z');
        CarbonImmutable::setTestNow('2026-11-07T12:00:00Z');
        try {
            $service = app(ExchangeStrategyCompatibilityService::class);
            $exchange = ExchangeRecord::query()->findOrFail('bybit');
            $strategy = ['id' => 'baseline', 'timeframes' => ['5', '15'], 'symbols' => ['BTCUSDT']];
            $this->candles('5');
            self::assertFalse($service->evaluate($exchange, $strategy)['research_eligible']);
            $this->candles('15');
            $complete = $service->evaluate($exchange, $strategy);
            self::assertTrue($complete['research_eligible']);
            self::assertTrue($complete['checks']['fresh_required_candles']);
            self::assertFalse($complete['paper_eligible'], 'Fresh candles alone do not prove executable context.');
            self::assertFalse($service->evaluate($exchange, $strategy + ['requires_funding' => true])['research_eligible'], 'Declared funding capability is not historical funding evidence.');
            self::assertFalse($service->evaluate($exchange, $strategy + ['requires_open_interest' => true])['research_eligible'], 'Declared OI capability is not historical OI evidence.');

            $last = CandleRecord::query()->where('exchange', 'bybit')->where('interval', '15')->latest('open_time')->firstOrFail();
            $last->update(['is_closed' => false]);
            self::assertFalse($service->evaluate($exchange, $strategy)['research_eligible']);
            $last->update(['is_closed' => true, 'open_time' => now('UTC')->addMinutes(15)]);
            self::assertFalse($service->evaluate($exchange, $strategy)['research_eligible']);
            $last->update(['open_time' => now('UTC')->subMinutes(15)]);
            CandleRecord::query()->where('exchange', 'bybit')->where('interval', '15')->where('open_time', now('UTC')->subMinutes(150))->delete();
            self::assertFalse($service->evaluate($exchange, $strategy)['research_eligible']);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_invalid_cost_rates_cannot_enable_a_model(): void
    {
        $validator = app(ExchangeCapabilityValidator::class);
        foreach (['-0.1', 'NaN', 'INF', '0.1', 0.001, null] as $invalid) {
            self::assertFalse($validator->validRate($invalid));
        }
        self::assertTrue($validator->validRate('0'));
        self::assertTrue($validator->validRate('0.00055'));
    }

    private function candles(string $interval): void
    {
        $rows = [];
        for ($i = 1; $i <= 220; $i++) {
            $time = now('UTC')->subMinutes($i * (int) $interval);
            $rows[] = ['exchange' => 'bybit', 'category' => 'linear', 'symbol' => 'BTCUSDT', 'interval' => $interval,
                'open_time' => $time, 'open' => '100', 'high' => '105', 'low' => '95', 'close' => '102',
                'volume' => '10', 'turnover' => '1000', 'is_closed' => true, 'created_at' => $time, 'updated_at' => $time];
        }
        CandleRecord::query()->insert($rows);
    }
}
