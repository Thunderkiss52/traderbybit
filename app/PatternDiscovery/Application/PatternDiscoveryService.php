<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

use App\MarketData\Infrastructure\Persistence\CandleRecord;
use App\PatternDiscovery\Domain\MarketObservation;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRecord;
use App\PatternDiscovery\Infrastructure\Persistence\PatternRunRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class PatternDiscoveryService
{
    public function __construct(
        private MarketFeatureExtractor $features,
        private BarrierOutcomeLabeler $labeler,
        private PatternMiner $miner,
    ) {}

    public function run(string $runId): void
    {
        $run = PatternRunRecord::query()->findOrFail($runId);
        if ($run->status !== 'QUEUED') {
            return;
        }
        $run->update(['status' => 'RUNNING']);
        try {
            $config = $run->parameters;
            $records = SignalRecord::query()->where('symbol', $run->symbol)
                ->where('exchange', 'bybit')->where('category', 'linear')
                ->where('decision_time', '>=', $run->from_at)
                ->where('decision_time', '<=', $run->to_at)
                ->orderBy('decision_time')->orderBy('id')
                ->limit((int) $config['maximum_decisions'] + 1)->get();
            if ($records->count() > (int) $config['maximum_decisions']) {
                throw new \RuntimeException('research_decision_limit_exceeded_narrow_date_range');
            }
            $observations = [];
            $quality = ['missing_features' => 0, 'missing_outcomes' => 0, 'ambiguous_labels' => 0];
            $previous = null;
            foreach ($records as $record) {
                $time = $record->decision_time->getTimestamp();
                $snapshot = (array) ($record->indicator_snapshot ?? []);
                $contextRecord = MarketContextRecord::query()->where('exchange', $record->exchange)
                    ->where('category', $record->category)->where('symbol', $record->symbol)
                    ->where('observed_at', '<=', $record->decision_time)
                    ->orderByDesc('observed_at')->first(['observed_at', 'funding_rate', 'open_interest_change']);
                $context = $contextRecord === null ? null : [
                    'observed_at' => $contextRecord->observed_at->getTimestamp(),
                    'funding_rate' => $contextRecord->funding_rate,
                    'open_interest_change' => $contextRecord->open_interest_change,
                ];
                $features = $this->features->extract($snapshot, $time, $previous, $context);
                $previous = ['decision_timestamp' => $time, 'reason' => $record->reasons[0] ?? 'none', 'snapshot' => $snapshot];
                $entry = $snapshot['15m'] ?? null;
                if (! is_array($entry) || ! is_numeric($entry['atr_14'] ?? null) || ! is_numeric($entry['close'] ?? null)
                    || count($features['features']) < 5) {
                    $quality['missing_features']++;
                    continue;
                }
                $lastOpen = $time + (((int) $config['holding_bars_5m'] - 1) * 300);
                // Never label a decision with candles closing after this research window.
                if ($lastOpen + 300 > $run->to_at->getTimestamp()) {
                    $quality['missing_outcomes']++;
                    continue;
                }
                $bars = CandleRecord::query()->where('exchange', $record->exchange)
                    ->where('category', $record->category)->where('symbol', $record->symbol)
                    ->where('interval', '5')->where('is_closed', true)
                    ->where('open_time', '>=', $record->decision_time)
                    ->where('open_time', '<=', (new DateTimeImmutable('@'.$lastOpen))->setTimezone(new \DateTimeZone('UTC')))
                    ->orderBy('open_time')->limit((int) $config['holding_bars_5m'])->get(['open_time', 'open', 'high', 'low', 'close']);
                $candles = $bars->map(static fn (CandleRecord $bar): array => [
                    'open_time' => $bar->open_time->getTimestamp(), 'open' => $bar->open,
                    'high' => $bar->high, 'low' => $bar->low, 'close' => $bar->close,
                ])->all();
                foreach (['BUY', 'SELL'] as $direction) {
                    $label = $this->labeler->label($direction, (string) $entry['close'], (string) $entry['atr_14'], $time, $candles, $config);
                    if ($label === null) {
                        $quality['missing_outcomes']++;
                        continue;
                    }
                    $quality['ambiguous_labels'] += (int) $label['ambiguous'];
                    $observations[] = new MarketObservation($record->id, $record->symbol, $direction, $time,
                        $label['outcome_timestamp'], $features['features'], $label['won'], $label['net_r'],
                        $label['ambiguous'], $features['regime']);
                }
            }
            $results = $this->miner->discover($observations, $config);
            DB::transaction(function () use ($run, $results, $records, $observations, $quality, $config): void {
                foreach ($results as $result) {
                    $canonical = json_encode([$result['direction'], $result['terms']], JSON_THROW_ON_ERROR);
                    PatternRecord::query()->create([
                        'pattern_run_id' => $run->id, 'direction' => $result['direction'],
                        'status' => $result['status'], 'terms' => $result['terms'],
                        'rule_hash' => hash('sha256', $canonical), 'version' => (string) $config['version'],
                        'metrics' => $result,
                    ]);
                }
                $run->update(['status' => 'COMPLETED', 'examined_decisions' => $records->count(),
                    'labeled_observations' => count($observations), 'data_quality' => $quality,
                    'completed_at' => now('UTC')]);
            });
        } catch (\Throwable $error) {
            $run->update(['status' => 'FAILED', 'data_quality' => ['error' => $error->getMessage()], 'completed_at' => now('UTC')]);
            throw $error;
        }
    }
}
