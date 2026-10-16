<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternMatchRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRecord;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Ramsey\Uuid\Uuid;

final readonly class PatternMatchService
{
    public function __construct(
        private MarketFeatureExtractor $features,
        private PatternMatcher $matcher,
        private AsOfMarketContextService $contexts,
        private BarrierOutcomeLabeler $labeler,
        private array $config,
    ) {}

    public function process(SignalDecision $decision): array
    {
        $this->resolveMatured($decision->symbol, $decision->decisionTime);
        $previousRecord = SignalRecord::query()->where([
            'exchange' => $decision->exchange, 'category' => $decision->category,
            'symbol' => $decision->symbol, 'strategy_id' => 'baseline',
        ])->where('decision_time', '<', $decision->decisionTime)->latest('decision_time')->first(['decision_time', 'reasons', 'indicator_snapshot']);
        $previous = $previousRecord === null ? null : ['decision_timestamp' => $previousRecord->decision_time->getTimestamp(),
            'reason' => $previousRecord->reasons[0] ?? 'none', 'snapshot' => (array) $previousRecord->indicator_snapshot];
        $context = $this->contexts->at($decision->exchange, $decision->category, $decision->symbol, $decision->decisionTime);
        $extracted = $this->features->extract($decision->indicatorSnapshot, $decision->decisionTime->getTimestamp(), $previous, $context);
        $configurationHash = hash('sha256', json_encode($this->config, JSON_THROW_ON_ERROR));
        $patterns = PatternRecord::query()->join('research_pattern_runs as runs', 'runs.id', '=', 'research_patterns.pattern_run_id')
            ->where('runs.symbol', $decision->symbol)->where('runs.configuration_hash', $configurationHash)
            ->where('research_patterns.status', 'VALIDATED')->latest('research_patterns.created_at')->limit(100)
            ->get(['research_patterns.id', 'research_patterns.direction', 'research_patterns.terms']);
        $matches = [];
        foreach ($patterns as $pattern) {
            if (! $this->matcher->matches((array) $pattern->terms, $extracted['features'])) {
                continue;
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "traderbybit:pattern-match:{$pattern->id}:{$decision->symbol}:{$decision->decisionTime->format('U.u')}")->toString();
            PatternMatchRecord::query()->insertOrIgnore([[
                'id' => $id, 'pattern_id' => $pattern->id, 'symbol' => $decision->symbol,
                'direction' => $pattern->direction, 'matched_at' => $decision->decisionTime,
                'feature_snapshot' => json_encode(['features' => $extracted['features'], 'regime' => $extracted['regime'],
                    'entry' => data_get($decision->indicatorSnapshot, '15m.close'), 'atr' => data_get($decision->indicatorSnapshot, '15m.atr_14')], JSON_THROW_ON_ERROR),
                'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
            ]]);
            $matches[] = PatternMatchRecord::query()->findOrFail($id);
        }

        return $matches;
    }

    private function resolveMatured(string $symbol, \DateTimeImmutable $through): void
    {
        $holdingBars = (int) $this->config['holding_bars_5m'];
        PatternMatchRecord::query()->where('symbol', $symbol)->where('status', 'PENDING')
            ->where('matched_at', '<=', $through->modify('-'.($holdingBars * 5).' minutes'))
            ->oldest('matched_at')->limit(100)->each(function (PatternMatchRecord $match) use ($holdingBars): void {
                $snapshot = $match->feature_snapshot;
                if (! is_string($snapshot['entry'] ?? null) || ! is_string($snapshot['atr'] ?? null)) {
                    $match->update(['status' => 'UNRESOLVED', 'outcome' => 'MISSING_ENTRY_FEATURES']);

                    return;
                }
                $bars = CandleRecord::query()->where(['exchange' => 'bybit', 'category' => 'linear', 'symbol' => $match->symbol,
                    'interval' => '5', 'is_closed' => true])->where('open_time', '>=', $match->matched_at)
                    ->oldest('open_time')->limit($holdingBars)->get(['open_time', 'open', 'high', 'low', 'close']);
                $payload = $bars->map(static fn (CandleRecord $bar): array => ['open_time' => $bar->open_time->getTimestamp(),
                    'open' => $bar->open, 'high' => $bar->high, 'low' => $bar->low, 'close' => $bar->close])->all();
                $label = $this->labeler->label($match->direction, $snapshot['entry'], $snapshot['atr'], $match->matched_at->getTimestamp(), $payload, $this->config);
                if ($label === null) {
                    return;
                }
                $match->update(['status' => $label['ambiguous'] ? 'AMBIGUOUS' : 'RESOLVED',
                    'outcome' => $label['outcome'], 'net_r' => $label['net_r'],
                    'outcome_at' => (new \DateTimeImmutable('@'.$label['outcome_timestamp']))->setTimezone(new \DateTimeZone('UTC'))]);
            });
    }
}
