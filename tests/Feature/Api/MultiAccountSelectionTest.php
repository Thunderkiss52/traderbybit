<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exchanges\Binance\BinanceDeploymentInitializer;
use App\PaperTrading\Application\PaperAccountService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\RiskDecisionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MultiAccountSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Initialize accounts
        $service = app(PaperAccountService::class);
        $service->allPrimaryAccounts('bybit');
    }

    public function test_dashboard_returns_aggregate_portfolio_when_all_is_selected(): void
    {
        $response = $this->getJson('/api/v1/dashboard?account=all', [
            'X-Exchange' => 'bybit',
        ]);

        $response->assertOk();
        $data = $response->json('data');

        self::assertNotNull($data);
        self::assertArrayHasKey('portfolio', $data);
        self::assertArrayHasKey('aggregate_portfolio', $data);
        self::assertArrayHasKey('accounts', $data);
        self::assertTrue($data['is_aggregate']);
        self::assertTrue($data['portfolio']['is_aggregate']);
        self::assertSame('aggregate', $data['portfolio']['scope']);
        self::assertSame('bybit', $data['portfolio']['exchange_id']);
        self::assertGreaterThanOrEqual(13, $data['portfolio']['accounts_count']);
        self::assertCount(count($data['accounts']), $data['accounts']);
        self::assertNull($data['account']);
    }

    public function test_dashboard_returns_account_scoped_portfolio_when_specific_account_requested(): void
    {
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')->firstOrFail();

        $response = $this->getJson('/api/v1/dashboard?account='.$account->id, [
            'X-Exchange' => 'bybit',
        ]);

        $response->assertOk();
        $data = $response->json('data');

        self::assertNotNull($data);
        self::assertFalse($data['is_aggregate']);
        self::assertFalse($data['portfolio']['is_aggregate']);
        self::assertSame('account', $data['portfolio']['scope']);
        self::assertSame($account->id, $data['portfolio']['account_id']);
        self::assertSame($account->id, $data['account']['id']);
        self::assertArrayHasKey('aggregate_portfolio', $data);
        self::assertTrue($data['aggregate_portfolio']['is_aggregate']);
    }

    public function test_statistics_accepts_account_param_and_returns_account_provenance(): void
    {
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')->firstOrFail();

        $response = $this->getJson('/api/v1/statistics?account='.$account->id, [
            'X-Exchange' => 'bybit',
        ]);

        $response->assertOk();
        $data = $response->json('data');

        self::assertNotNull($data);
        self::assertArrayHasKey('account', $data);
        self::assertSame($account->id, $data['account']['id']);
        self::assertSame('bybit', $data['account']['exchange_id']);
        self::assertArrayHasKey('accounts', $data);
        self::assertNotEmpty($data['accounts']);
    }

    public function test_risk_accepts_account_param_and_scopes_decisions(): void
    {
        $account = PaperAccountRecord::query()->where('exchange_id', 'bybit')->firstOrFail();

        $signal = SignalRecord::query()->create([
            'symbol' => 'BTCUSDT',
            'exchange' => 'bybit',
            'category' => 'linear',
            'timeframe' => '15',
            'side' => 'BUY',
            'decision_time' => now('UTC'),
            'candle_close_time' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
            'maximum_holding_minutes' => 240,
            'score' => 85,
            'quality_class' => 'A',
            'reasons' => ['test_signal'],
            'risks' => [],
            'indicator_snapshot' => [],
            'contract' => ['research_mode' => true],
            'strategy_id' => 'baseline',
            'strategy_version' => 'v1.0.0',
            'schema_version' => '1.0.0',
        ]);

        RiskDecisionRecord::query()->create([
            'signal_id' => $signal->id,
            'paper_account_id' => $account->id,
            'exchange_id' => 'bybit',
            'allowed' => true,
            'reasons' => ['allowed_by_policy'],
            'snapshot' => ['equity' => '10000'],
            'risk_version' => '1.0.0',
            'decided_at' => now('UTC'),
        ]);

        $response = $this->getJson('/api/v1/risk?account='.$account->id, [
            'X-Exchange' => 'bybit',
        ]);

        $response->assertOk();
        $data = $response->json('data');

        self::assertNotNull($data);
        self::assertSame($account->id, $data['account']['id']);
        self::assertNotEmpty($data['decisions']['data']);
        self::assertSame((string) $signal->id, (string) $data['decisions']['data'][0]['signal_id']);
    }

    public function test_paper_accounts_endpoint_returns_accounts_with_exchange_filtering(): void
    {
        $response = $this->getJson('/api/v1/paper/accounts', [
            'X-Exchange' => 'bybit',
        ]);

        $response->assertOk();
        $accounts = $response->json('data');

        self::assertNotEmpty($accounts);
        foreach ($accounts as $acc) {
            self::assertSame('bybit', $acc['exchange_id']);
        }
    }

    public function test_f09_twenty_six_account_census_all_selectable_with_distinct_balances_and_foreign_rejection(): void
    {
        $service = app(PaperAccountService::class);
        $initializer = app(BinanceDeploymentInitializer::class);
        $initializer->initialize();

        $bybitAccounts = $service->allPrimaryAccounts('bybit');
        $binanceAccounts = $service->allPrimaryAccounts('binance');

        self::assertCount(13, $bybitAccounts, 'Exactly 13 Bybit PRIMARY accounts required');
        self::assertCount(13, $binanceAccounts, 'Exactly 13 Binance PRIMARY accounts required');

        // Assign distinct starting balances to verify aggregation sum
        $bybitSum = 0;
        foreach ($bybitAccounts as $idx => $acc) {
            $bal = 10000 + ($idx * 100);
            $bybitSum += $bal;
            $acc->update([
                'starting_balance' => (string) $bal,
                'current_equity' => (string) $bal,
                'peak_equity' => (string) $bal,
            ]);
        }

        // 1. Verify aggregate dashboard sum matches exactly
        $aggResponse = $this->getJson('/api/v1/dashboard?account=all', ['X-Exchange' => 'bybit']);
        $aggResponse->assertOk();
        $aggData = $aggResponse->json('data');
        self::assertSame(13, $aggData['portfolio']['accounts_count']);
        self::assertEqualsWithDelta($bybitSum, (float) $aggData['portfolio']['current_equity'], 0.01);

        // 2. Verify all individual accounts are selectable and distinct
        foreach ($bybitAccounts as $acc) {
            $indivRes = $this->getJson('/api/v1/dashboard?account='.$acc->id, ['X-Exchange' => 'bybit']);
            $indivRes->assertOk();
            self::assertSame($acc->id, $indivRes->json('data.account.id'));
            self::assertEqualsWithDelta((float) $acc->current_equity, (float) $indivRes->json('data.account.current_equity'), 0.01);
        }

        // 3. Foreign exchange account requested on Bybit returns null account without provisioning
        $foreignAccount = $binanceAccounts->first();
        $foreignRes = $this->getJson('/api/v1/dashboard?account='.$foreignAccount->id, ['X-Exchange' => 'bybit']);
        $foreignRes->assertOk();
        self::assertNull($foreignRes->json('data.account'), 'Foreign exchange account must return null on Bybit');
    }
}
