<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Research\Infrastructure\Persistence\ResearchJobRecord;
use App\Research\Infrastructure\Persistence\SignalQualityProfileRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SignalQualityLegacyProfitFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_profile_ratio_is_withheld_without_changing_history_or_pagination(): void
    {
        $now = CarbonImmutable::parse('2026-11-13T12:00:00Z');
        $job = ResearchJobRecord::query()->create([
            'job_id' => (string) Str::uuid(), 'idempotency_key' => str_repeat('a', 64),
            'kind' => 'SIGNAL_MARKOUT', 'requested_at_utc' => $now,
        ]);
        $scope = ['data_source' => 'HYPOTHETICAL', 'exchange' => 'bybit', 'strategy_id' => 'trend_pullback',
            'symbol' => 'BTCUSDT', 'source_timeframe_minutes' => 15, 'horizon_minutes' => 15];
        $legacy = ['profit_factor' => 2.5, 'descriptive_metrics' => [
            'profit_factor' => '2.500000000000', 'unavailable_reasons' => ['precision' => 'NOT_PROVIDED'],
        ], 'other_evidence' => 'unchanged'];
        $current = ['profit_factor' => null, 'descriptive_metrics' => [
            'profit_factor' => null, 'unavailable_reasons' => ['profit_factor' => 'OBSERVATION_PNL_AMOUNTS_NOT_PROVIDED'],
        ], 'other_evidence' => 'unchanged'];
        for ($i = 0; $i < 12; $i++) {
            SignalQualityProfileRecord::query()->create([
                'id' => (string) Str::uuid(), 'profile_key' => hash('sha256', 'profile-'.$i), 'research_job_id' => $job->job_id,
                'scope_hash' => str_repeat('b', 64), 'scope' => $scope, 'policy_version' => 'SIGNAL_QUALITY_HOEFFDING_V'.($i === 0 ? '1' : '2'),
                'policy_hash' => str_repeat('c', 64), 'classification' => $i === 0 ? 'PROFITABLE_CONFIRMED' : 'NEUTRAL', 'input_hash' => str_repeat('d', 64),
                'result' => $i === 0 ? $legacy : $current, 'as_of' => $now->subMinutes($i), 'created_at' => $now,
            ]);
        }
        $before = DB::table('signal_quality_profiles')->orderBy('id')->pluck('result', 'id')->all();
        $response = $this->getJson('/api/v1/research/profitability/signal-quality-profiles?exchange=bybit');
        $response->assertOk()->assertJsonPath('profiles.total', 12)->assertJsonPath('profiles.per_page', 10)
            ->assertJsonPath('profiles.data.0.classification', 'INVALID_DATA')
            ->assertJsonPath('profiles.data.0.historical_classification', 'PROFITABLE_CONFIRMED')
            ->assertJsonPath('profiles.data.0.result.profit_factor', null)
            ->assertJsonPath('profiles.data.0.result.profit_factor_unavailable_reason', 'LEGACY_NORMALIZED_RETURN_RATIO_NOT_PNL')
            ->assertJsonPath('profiles.data.0.result.source_timeframe_attribution_warning', 'LEGACY_SOURCE_TIMEFRAME_UNVERIFIED')
            ->assertJsonPath('profiles.data.0.result.descriptive_metrics.profit_factor', null)
            ->assertJsonPath('profiles.data.0.result.descriptive_metrics.unavailable_reasons.profit_factor', 'LEGACY_NORMALIZED_RETURN_RATIO_NOT_PNL')
            ->assertJsonPath('profiles.data.0.result.descriptive_metrics.unavailable_reasons.precision', 'NOT_PROVIDED')
            ->assertJsonPath('profiles.data.0.result.other_evidence', 'unchanged');
        self::assertEquals($current, $response->json('profiles.data.1.result'));
        $this->getJson('/api/v1/research/profitability/signal-quality-profiles?exchange=bybit&page=2')
            ->assertOk()->assertJsonPath('profiles.total', 12)->assertJsonCount(2, 'profiles.data');
        self::assertSame($before, DB::table('signal_quality_profiles')->orderBy('id')->pluck('result', 'id')->all());
        self::assertSame(1, ResearchJobRecord::query()->count());
    }
}
