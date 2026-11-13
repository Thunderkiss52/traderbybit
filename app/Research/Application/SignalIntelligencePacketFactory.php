<?php

declare(strict_types=1);

namespace App\Research\Application;

use App\Backtest\Application\HistoricalExecutionCostPolicy;
use App\Exchanges\Infrastructure\Persistence\ExchangeRecord;
use App\MarketData\Application\MarketCandleConflictService;
use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/** Builds bounded, immutable research inputs; never mutates trading state. */
final readonly class SignalIntelligencePacketFactory
{
    public function __construct(private MarketCandleConflictService $integrity) {}

    public function evaluation(SignalRecord $signal, array $horizons, CarbonImmutable $asOf): array
    {
        $horizons = array_values(array_unique($horizons));
        sort($horizons);
        $minutes = (int) preg_replace('/m$/', '', (string) $signal->timeframe);
        if ($horizons === [] || array_diff($horizons, [1, 3, 5, 15, 30, 60]) !== []
            || ! in_array($minutes, [1, 3, 5, 15, 30, 60], true)
            || ! in_array($signal->side, ['BUY', 'SELL'], true) || $signal->category !== 'linear'
            || $signal->decision_time === null || $signal->created_at === null || $signal->candle_close_time === null) {
            throw new InvalidArgumentException('Signal scope or evaluation horizons are unsupported.');
        }
        $decision = CarbonImmutable::instance($signal->decision_time)->utc();
        $available = $decision->max(CarbonImmutable::instance($signal->created_at)->utc());
        if ($available > $asOf || $signal->candle_close_time > $decision) {
            throw new InvalidArgumentException('Signal was unavailable or uses future features.');
        }
        $entry = $available->second === 0 && $available->micro === 0 ? $available : $available->startOfMinute()->addMinute();
        $end = $entry->addMinutes(max($horizons));
        if ($this->integrity->hasActiveConflict($signal->exchange, $signal->symbol, '1', $entry, $end)) {
            throw new InvalidArgumentException('Signal outcome candles have unresolved integrity conflicts.');
        }
        $exchange = ExchangeRecord::query()->findOrFail($signal->exchange);
        $costs = (array) $exchange->cost_model;
        $paper = HistoricalExecutionCostPolicy::apply($signal->exchange, [], $exchange->market_type, $costs);
        if (! is_string($costs['version'] ?? null) || $costs['version'] === '') {
            throw new InvalidArgumentException('Versioned exchange costs are unavailable.');
        }
        $context = MarketContextRecord::query()->where('exchange', $signal->exchange)->where('category', $signal->category)
            ->where('symbol', $signal->symbol)->where('observed_at', '<=', $decision)
            ->where('observed_at', '>=', $decision->subSeconds(300))->where('created_at', '<=', $decision)
            ->where('updated_at', '<=', $decision)
            ->orderByDesc('observed_at')->first();
        if ($context === null) {
            throw new InvalidArgumentException('Point-in-time instrument constraints are unavailable.');
        }
        $bars = CandleRecord::query()->where('exchange', $signal->exchange)->where('category', $signal->category)
            ->where('symbol', $signal->symbol)->where('interval', '1')->where('is_closed', true)
            ->where('open_time', '>=', $entry)->where('open_time', '<', $end->min($asOf->startOfMinute()))
            ->where('created_at', '<=', $asOf)->where('updated_at', '<=', $asOf)
            ->orderBy('open_time')->limit(512)->get()->map(fn (CandleRecord $bar): array => [
                'exchange' => $bar->exchange, 'category' => $bar->category, 'symbol' => $bar->symbol,
                'open_ms' => $bar->open_time->getTimestampMs(), 'close_ms' => $bar->open_time->addMinute()->getTimestampMs(),
                'closed' => true, 'open' => $bar->open, 'high' => $bar->high, 'low' => $bar->low, 'close' => $bar->close,
            ])->all();
        // Only the existing explicit settlement schedule is used. Empirical inference
        // from future funding events cannot establish point-in-time schedule knowledge.
        $schedule = config("market_data.funding_schedules.{$signal->exchange}.{$signal->symbol}", []);
        $hours = (int) ($schedule['interval_hours'] ?? 0);
        $scheduleKnown = in_array($hours, [1, 2, 4, 8], true) && (int) ($schedule['offset_hours'] ?? -1) === 0;
        $events = MarketFundingEventRecord::query()->where('exchange', $signal->exchange)->where('category', $signal->category)
            ->where('symbol', $signal->symbol)->where('funding_at', '>', $entry)->where('funding_at', '<=', $end->min($asOf))
            ->where('created_at', '<=', $asOf)->where('updated_at', '<=', $asOf)
            ->orderBy('funding_at')->limit(512)->get()->map(fn (MarketFundingEventRecord $event): array => [
                'exchange' => $event->exchange, 'category' => $event->category, 'symbol' => $event->symbol,
                'at_ms' => $event->funding_at->getTimestampMs(), 'rate' => $event->rate, 'mark_price' => $event->mark_price ?? '',
            ])->all();
        $revision = config('research.signal_intelligence.code_revision');
        if ($revision === null && is_file(base_path('build-metadata.json'))) {
            $revision = json_decode((string) file_get_contents(base_path('build-metadata.json')), true, 8, JSON_THROW_ON_ERROR)['git_sha'] ?? null;
        }
        if (! is_string($revision) || ! preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $revision)) {
            throw new InvalidArgumentException('Immutable build revision is unavailable.');
        }
        $notional = (string) config('research.signal_intelligence.reference_notional', '5');
        $configuration = hash('sha256', json_encode([$signal->strategy_id, $signal->strategy_version, $costs, $schedule, $notional], JSON_THROW_ON_ERROR));
        $datasetHash = hash('sha256', json_encode([$bars, $events, $context->getAttributes()], JSON_THROW_ON_ERROR));

        return ['operation' => 'evaluate', 'evaluation' => [
            'signal' => ['id' => $signal->id, 'exchange' => $signal->exchange, 'category' => $signal->category, 'symbol' => $signal->symbol,
                'strategy_id' => $signal->strategy_id, 'strategy_version' => $signal->strategy_version,
                'source_timeframe_minutes' => $minutes, 'decision_ms' => $decision->getTimestampMs(),
                'available_ms' => $available->getTimestampMs(), 'feature_available_ms' => $signal->candle_close_time->getTimestampMs(),
                'direction' => $signal->side, 'reference_price' => (string) $signal->entry_low,
                'regime' => (string) ($signal->indicator_snapshot['regime']['classification'] ?? 'UNCERTAIN')],
            'as_of_ms' => $asOf->getTimestampMs(), 'horizons_minutes' => $horizons, 'notional' => $notional,
            'horizon_anchor' => 'NEXT_MINUTE_OPEN',
            'instrument' => ['exchange' => $signal->exchange, 'category' => $signal->category, 'symbol' => $signal->symbol,
                'available_ms' => $context->observed_at->getTimestampMs(), 'tick_size' => $context->tick_size ?? '',
                'quantity_step' => $context->qty_step ?? '', 'min_quantity' => $context->min_order_qty ?? '',
                'max_quantity' => $context->max_order_qty ?? '', 'min_notional' => $context->min_notional_value ?? ''],
            'costs' => ['source_id' => $costs['version'], 'taker_rate' => $paper['fees']['taker_rate'],
                'slippage_rate' => $paper['slippage_rate'], 'funding_complete' => $scheduleKnown,
                'funding_schedule_id' => $scheduleKnown ? "{$signal->exchange}:{$signal->symbol}:configured:{$hours}h" : '',
                'funding_interval_minutes' => $hours * 60, 'funding_offset_minutes' => 0,
                'funding_coverage_from_ms' => $entry->getTimestampMs(), 'funding_coverage_to_ms' => $end->min($asOf)->getTimestampMs(),
                'funding_events' => $events],
            'provenance' => ['dataset_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vector:signal:'.$datasetHash)->toString(),
                'dataset_hash' => $datasetHash, 'configuration_hash' => $configuration, 'code_revision' => $revision],
            'candles' => $bars,
        ]];
    }
}
