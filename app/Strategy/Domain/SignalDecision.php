<?php

declare(strict_types=1);

namespace App\Strategy\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class SignalDecision
{
    public function __construct(
        public string $id,
        public string $exchange,
        public string $category,
        public string $symbol,
        public string $timeframe,
        public DateTimeImmutable $decisionTime,
        public DateTimeImmutable $candleCloseTime,
        public SignalSide $side,
        public ?QualityClass $qualityClass,
        public int $score,
        public ?PricePlan $pricePlan,
        public ?string $modelRiskPercent,
        public DateTimeImmutable $expiresAt,
        public int $maximumHoldingMinutes,
        /** @var list<string> */
        public array $reasons,
        /** @var list<string> */
        public array $risks,
        public array $indicatorSnapshot,
        public string $strategyVersion,
        public string $schemaVersion,
        public ?int $candleId = null,
    ) {}

    public function toContractArray(): array
    {
        $utc = new DateTimeZone('UTC');
        $date = static fn (DateTimeImmutable $value): string => $value->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');

        $executionIntent = match ($this->side) {
            SignalSide::Buy => 'OPEN_LONG',
            SignalSide::Sell => $this->category === 'linear' ? 'OPEN_SHORT' : 'NO_ACTION',
            SignalSide::Skip => 'NO_ACTION',
        };

        return [
            'schema_version' => $this->schemaVersion,
            'strategy_version' => $this->strategyVersion,
            'id' => $this->id,
            'research_mode' => true,
            'disclaimer' => 'Исследовательский режим. Не является инвестиционной рекомендацией',
            'market' => ['exchange' => $this->exchange, 'category' => $this->category, 'symbol' => $this->symbol, 'timeframe' => $this->timeframe],
            'candle_id' => $this->candleId,
            'decision_time' => $date($this->decisionTime),
            'candle_close_time' => $date($this->candleCloseTime),
            'side' => $this->side->value,
            'execution_intent' => $executionIntent,
            'quality_class' => $this->qualityClass?->value,
            'score' => $this->score,
            'entry_zone' => $this->pricePlan === null ? null : ['low' => $this->pricePlan->entryLow, 'high' => $this->pricePlan->entryHigh],
            'stop_loss' => $this->pricePlan?->stopLoss,
            'take_profit_1' => $this->pricePlan?->takeProfit1,
            'take_profit_2' => $this->pricePlan?->takeProfit2,
            'risk_reward' => $this->pricePlan === null ? null : ['tp1' => $this->pricePlan->riskReward1, 'tp2' => $this->pricePlan->riskReward2],
            'model_risk_percent' => $this->modelRiskPercent,
            'expires_at' => $date($this->expiresAt),
            'maximum_holding_minutes' => $this->maximumHoldingMinutes,
            'reasons' => array_values($this->reasons),
            'risks' => array_values($this->risks),
            'rules' => $this->indicatorSnapshot['rules'] ?? [],
            'indicators' => $this->indicatorSnapshot,
        ];
    }
}
