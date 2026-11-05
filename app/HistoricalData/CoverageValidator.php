<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\MarketData\Application\MarketCandleConflictService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CoverageValidator
{
    public function __construct(private MarketCandleConflictService $integrity) {}

    public function validate(string $id): array
    {
        $batch = DB::table('historical_download_batches')->find($id);
        abort_if($batch === null, 404);
        abort_if($batch->status === 'ACTIVE', 409, 'Pause or finish acquisition before coverage validation.');
        $plan = json_decode($batch->plan, true);
        $from = CarbonImmutable::parse($plan['from']);
        $to = CarbonImmutable::parse($plan['to_exclusive']);
        $series = [];
        $candleComplete = true;
        $errors = [];
        foreach ($plan['input']['symbols'] as $symbol) {
            foreach ($plan['input']['intervals'] as $tf) {
                $seconds = DownloadPlanner::INTERVALS[$tf];
                $interval = (string) ($seconds / 60);
                $start = (int) ceil($from->timestamp / $seconds) * $seconds;
                $expected = max(0, intdiv($to->timestamp - $start, $seconds));
                $next = $start;
                $actual = 0;
                $missing = 0;
                $samples = [];
                $rows = DB::table('market_candles')->where(['exchange' => 'binance', 'category' => 'linear', 'symbol' => $symbol, 'interval' => $interval, 'is_closed' => true])->where('open_time', '>=', CarbonImmutable::createFromTimestamp($start, 'UTC'))->where('open_time', '<=', $to->subSeconds($seconds))->orderBy('open_time')->cursor(['open_time']);
                foreach ($rows as $row) {
                    $at = CarbonImmutable::parse($row->open_time)->timestamp;
                    if ($at !== $next) {
                        $gap = max(0, intdiv($at - $next, $seconds));
                        $missing += $gap;
                        if (count($samples) < 20) {
                            $samples[] = ['from' => gmdate(DATE_ATOM, $next), 'bars' => $gap];
                        }
                    }$next = $at + $seconds;
                    $actual++;
                }
                if ($next < $to->timestamp) {
                    $gap = max(0, intdiv($to->timestamp - $next, $seconds));
                    $missing += $gap;
                    if ($gap > 0 && count($samples) < 20) {
                        $samples[] = ['from' => gmdate(DATE_ATOM, $next), 'bars' => $gap];
                    }
                }
                $integrity = $this->integrity->integrityReport('binance', $symbol, $interval, $from, $to);
                $complete = $expected > 0 && $actual === $expected && $missing === 0 && $integrity['status'] === 'VALID';
                $candleComplete = $candleComplete && $complete;
                $series[] = ['symbol' => $symbol, 'interval' => $tf, 'expected' => $expected, 'actual' => $actual, 'missing' => $missing, 'gap_samples' => $samples, 'integrity' => $integrity, 'complete' => $complete];
            }
        }
        $entries = DB::table('historical_download_entries')->where('batch_id', $id)->get();
        $downloadComplete = true;
        foreach ($entries as $entry) {
            if (! in_array($entry->status, ['IMPORTED', 'VALIDATED', 'ACCEPTED_WITH_LIMITATIONS', 'SUPERSEDED_DAILY', 'SUPERSEDED_API'], true)) {
                $downloadComplete = false;
                $errors[] = $entry->error_code ?? $entry->status;
            }if ($entry->status === 'ACCEPTED_WITH_LIMITATIONS') {
                $errors[] = 'ARCHIVE_DATA_GAPS';
            }
        }
        $funding = [];
        $fundingComplete = $plan['input']['funding'];
        foreach ($plan['input']['symbols'] as $symbol) {
            $coverage = $this->fundingCoverage($id, $symbol, $from, $to);
            $funding[] = $coverage;
            $fundingComplete = $fundingComplete && $coverage['complete'];
        }
        $conflicts = DB::table('historical_data_conflicts')->whereIn('symbol', $plan['input']['symbols'])->where('at', '>=', $from)->where('at', '<', $to)->where('status', 'UNRESOLVED')->count();
        $state = ! $downloadComplete || $conflicts > 0 ? 'FAILED' : ($candleComplete && (! $plan['input']['funding'] || $fundingComplete) && $errors === [] ? 'VALIDATED' : 'ACCEPTED_WITH_LIMITATIONS');
        $quality = ['download_complete' => $downloadComplete, 'candle_complete' => $candleComplete, 'funding_requested' => $plan['input']['funding'], 'funding_complete' => $fundingComplete, 'series' => $series, 'funding' => $funding, 'unresolved_historical_conflicts' => $conflicts, 'errors' => array_values(array_unique($errors)), 'status' => $state, 'research_ready' => $downloadComplete && $candleComplete && $conflicts === 0 && $errors === [], 'full_fidelity_ready' => $state === 'VALIDATED' && $fundingComplete, 'validated_at' => now('UTC')->toISOString()];
        DB::transaction(function () use ($id, $quality, $state): void {
            DB::table('historical_download_batches')->where('id', $id)->whereNotIn('status', ['ACTIVE', 'CANCELLED'])->update(['status' => $state, 'quality' => json_encode($quality, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]);
            app(BatchRepository::class)->audit($id, 'COVERAGE_VALIDATED', $quality);
        }, 3);

        return $quality;
    }

    public function fundingRows(string $batch, string $symbol, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('historical_funding_observations')->where(['batch_id' => $batch, 'symbol' => $symbol, 'status' => 'VERIFIED_OBSERVATION'])->where('funding_at', '>=', $from)->where('funding_at', '<', $to)->selectRaw('funding_at, min(rate)::text as rate, max(mark_price)::text as mark, max(interval_hours) as hours, count(distinct rate) as rates, count(distinct mark_price) as marks')->groupBy('funding_at')->orderBy('funding_at')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function fundingCoverage(string $batch, string $symbol, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->fundingRows($batch, $symbol, $from, $to);
        $missingMarks = 0;
        $contradictions = 0;
        $gaps = 0;
        $last = null;
        $calendar = false;
        foreach ($rows as $row) {
            $at = CarbonImmutable::parse($row['funding_at']);
            if ($row['mark'] === null) {
                $missingMarks++;
            }if ($row['rates'] !== 1 || $row['marks'] > 1) {
                $contradictions++;
            }
            if ($row['hours'] !== null) {
                $calendar = true;
                $period = (int) $row['hours'] * 3600;
                if ($last !== null && $at->timestamp - $last->timestamp !== $period) {
                    $gaps++;
                }if ($last === null && $at->timestamp - $period >= $from->timestamp) {
                    $gaps++;
                }
            }$last = $at;
        }
        if ($calendar && $last !== null && $last->timestamp + (int) end($rows)['hours'] * 3600 < $to->timestamp) {
            $gaps++;
        }
        $api = DB::table('historical_download_entries')->where(['batch_id' => $batch, 'family' => 'fundingApi', 'symbol' => $symbol])->where('requested_from', '<', $to)->where('requested_to', '>', $from)->get();
        $exhaustive = $api->isNotEmpty() && $api->every(fn ($e) => in_array($e->status, ['IMPORTED', 'VALIDATED'], true));
        $missingApi = 0;
        foreach ($rows as $row) {
            $verifiedMark = $row['mark'] !== null;
            $missingApi += (int) ! $verifiedMark;
        }

        return ['symbol' => $symbol, 'events' => count($rows), 'missing_marks' => $missingMarks, 'conflicting_events' => $contradictions, 'calendar_gaps' => $gaps, 'calendar_source' => $calendar ? 'PUBLISHED_PER_EVENT_INTERVAL' : 'PAGINATED_API_PUBLISHED_EVENT_STREAM', 'published_api_response_complete' => $exhaustive, 'mark_source' => 'BINANCE_FUNDING_SETTLEMENT_API', 'trade_candles_used_as_marks' => false, 'complete' => $rows !== [] && $missingMarks === 0 && $contradictions === 0 && $gaps === 0 && $exhaustive];
    }
}
