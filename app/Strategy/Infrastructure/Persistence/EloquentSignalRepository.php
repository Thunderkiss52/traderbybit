<?php

declare(strict_types=1);

namespace App\Strategy\Infrastructure\Persistence;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Domain\PricePlan;
use App\Strategy\Domain\QualityClass;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;

final class EloquentSignalRepository implements SignalRepository
{
    public function createIfAbsent(SignalDecision $decision): SignalDecision
    {
        $plan = $decision->pricePlan;
        SignalRecord::query()->insertOrIgnore([$this->attributes($decision, $plan)]);
        $record = SignalRecord::query()->findOrFail($decision->id);

        return new SignalDecision(
            id: $record->id, exchange: $record->exchange, category: $record->category, symbol: $record->symbol, timeframe: $record->timeframe,
            decisionTime: $record->decision_time->toDateTimeImmutable(), candleCloseTime: $record->candle_close_time->toDateTimeImmutable(),
            side: SignalSide::from($record->side), qualityClass: $record->quality_class === null ? null : QualityClass::from($record->quality_class), score: $record->score,
            pricePlan: $record->entry_low === null ? null : new PricePlan($record->entry_low, $record->entry_high, $record->stop_loss, $record->take_profit_1, $record->take_profit_2, $record->risk_reward_1, $record->risk_reward_2),
            modelRiskPercent: $record->model_risk_percent, expiresAt: $record->expires_at->toDateTimeImmutable(), maximumHoldingMinutes: $record->maximum_holding_minutes,
            reasons: $record->reasons, risks: $record->risks, indicatorSnapshot: $record->indicator_snapshot, strategyVersion: $record->strategy_version, schemaVersion: $record->schema_version,
            candleId: $record->candle_id ? (int) $record->candle_id : null,
        );
    }

    private function attributes(SignalDecision $d, ?PricePlan $p): array
    {
        $now = now();
        $candleId = $d->candleId ?? $this->resolveCandleId($d);

        return [
            'id' => $d->id, 'exchange' => $d->exchange, 'category' => $d->category, 'symbol' => $d->symbol, 'timeframe' => $d->timeframe,
            'candle_id' => $candleId,
            'side' => $d->side->value, 'quality_class' => $d->qualityClass?->value, 'score' => $d->score, 'decision_time' => $d->decisionTime, 'candle_close_time' => $d->candleCloseTime,
            'entry_low' => $p?->entryLow, 'entry_high' => $p?->entryHigh, 'stop_loss' => $p?->stopLoss, 'take_profit_1' => $p?->takeProfit1, 'take_profit_2' => $p?->takeProfit2,
            'risk_reward_1' => $p?->riskReward1, 'risk_reward_2' => $p?->riskReward2, 'model_risk_percent' => $d->modelRiskPercent, 'expires_at' => $d->expiresAt,
            'maximum_holding_minutes' => $d->maximumHoldingMinutes, 'reasons' => json_encode($d->reasons, JSON_THROW_ON_ERROR), 'risks' => json_encode($d->risks, JSON_THROW_ON_ERROR),
            'indicator_snapshot' => json_encode($d->indicatorSnapshot, JSON_THROW_ON_ERROR), 'contract' => json_encode($d->toContractArray(), JSON_THROW_ON_ERROR),
            'strategy_version' => $d->strategyVersion, 'schema_version' => $d->schemaVersion, 'strategy_id' => (string) ($d->indicatorSnapshot['strategy_id'] ?? 'baseline'), 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function resolveCandleId(SignalDecision $d): ?int
    {
        $timeframe = (string) $d->timeframe;
        $interval = match ($timeframe) {
            '5m', '5' => '5',
            '15m', '15' => '15',
            '30m', '30' => '30',
            '1h', '60' => '60',
            '4h', '240' => '240',
            '1d', 'D' => 'D',
            default => preg_replace('/[^0-9]/', '', $timeframe) ?: '15',
        };

        $minutes = match ($interval) {
            '5' => 5,
            '15' => 15,
            '30' => 30,
            '60' => 60,
            '240' => 240,
            'D' => 1440,
            default => (int) $interval ?: 15,
        };

        $openTime = $d->candleCloseTime->modify("-{$minutes} minutes");

        $id = CandleRecord::query()
            ->where('exchange', $d->exchange)
            ->where('category', $d->category)
            ->where('symbol', $d->symbol)
            ->where('interval', $interval)
            ->where('open_time', $openTime)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
