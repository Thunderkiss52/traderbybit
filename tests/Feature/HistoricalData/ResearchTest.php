<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\AcquisitionWorker;
use App\HistoricalData\BatchControl;
use App\HistoricalData\BatchRepository;
use App\HistoricalData\CoverageValidator;
use App\HistoricalData\LearningExport;
use App\HistoricalData\OfficialTransport;
use App\HistoricalData\ResearchService;
use App\HistoricalData\ResearchWorker;
use App\StrategyBuilder\DefinitionStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\StrategyBuilder\DefinitionTest;
use Tests\TestCase;

final class ResearchTest extends TestCase
{
    use RefreshDatabase;

    private function imported(): object
    {
        config(['historical_data.reserve_disk_bytes' => 0, 'historical_data.max_staging_bytes' => 104857600]);
        $http = $this->createMock(OfficialTransport::class);
        $http->method('assertUrl');
        $http->method('fetch')->willReturnCallback(function ($url, $file, $max, $tick): array {
            $tick();
            $root = base_path('tests/Fixtures/historical/research/');
            if (str_contains($url, 'fapi.binance.com')) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $data = json_decode(file_get_contents($root.'funding.json'), true);
                $data = array_values(array_filter($data, fn ($r) => $r['fundingTime'] >= $query['startTime'] && $r['fundingTime'] <= $query['endTime']));
                $content = json_encode($data);
            } else {
                $name = basename(str_replace('.CHECKSUM', '', $url));
                $content = str_ends_with($url, '.CHECKSUM') ? hash_file('sha256', $root.$name).'  '.$name : file_get_contents($root.$name);
            }file_put_contents($file, $content);

            return ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
        });
        $this->app->instance(OfficialTransport::class, $http);
        $input = array_replace(PlannerTest::preset(), ['symbols' => ['BTCUSDT'], 'intervals' => ['5m', '15m', '30m', '1h'], 'from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-15T00:59:59.999Z']);
        $batch = app(BatchRepository::class)->create($input, 'research-fixture');
        app(BatchControl::class)->change($batch->id, 'start', 'TEST-OWNER-ACK', config('historical_data.license.version'));
        $worker = app(AcquisitionWorker::class);
        for ($i = 0; $i < 6; $i++) {
            self::assertTrue($worker->once($batch->id));
        }$quality = app(CoverageValidator::class)->validate($batch->id);
        self::assertSame('VALIDATED', $quality['status']);

        return $batch;
    }

    public function test_verified_archives_pin_to_deterministic_go_research_trades_without_paper_writes(): void
    {
        $batch = $this->imported();
        $d = DefinitionTest::example();
        $d['schema_version'] = 2;
        $d['id'] = 'user_vision_fixture';
        $d['exchanges'] = ['binance'];
        $d['entry'] = ['BUY' => ['op' => 'gt', 'left' => 'fast', 'right' => 0]];
        $d['funding'] = ['enabled' => true, 'interval_hours' => 8];
        $d['timeout_bars'] = 4;
        $row = app(DefinitionStore::class)->save($d, 'owner');
        $scope = ['strategy_id' => $d['id'], 'definition_id' => $row->id, 'symbol' => 'BTCUSDT', 'from' => '2024-01-12T00:00:00Z', 'to' => '2024-01-14T00:00:00Z', 'capital' => '10000', 'fee' => '0.0005', 'slippage' => '0.0002', 'risk_percent' => '0.5', 'funding' => 'EXACT', 'intended_use' => 'NONCOMMERCIAL_RESEARCH'];
        $service = app(ResearchService::class);
        $run = $service->create($batch->id, $scope);
        self::assertSame($run->id, $service->create($batch->id, $scope)->id);
        app(ResearchWorker::class)->once($run->id);
        $done = DB::table('historical_research_runs')->find($run->id);
        self::assertSame('COMPLETED', $done->status, $done->error_code ?? '');
        $result = json_decode($done->result, true);
        self::assertGreaterThan(0, $result['report']['total_trades']);
        self::assertGreaterThan(0, DB::table('historical_research_trades')->where('run_id', $run->id)->count());
        self::assertGreaterThan(0, DB::table('historical_research_equity')->where('run_id', $run->id)->count());
        $trade = json_decode(DB::table('historical_research_trades')->where('run_id', $run->id)->value('trade'), true);
        self::assertArrayHasKey('funding', $trade);
        self::assertArrayHasKey('slippage', $trade);
        self::assertNotSame('{}', DB::table('historical_research_trades')->where('run_id', $run->id)->value('features'));
        self::assertSame(0, DB::table('virtual_positions')->count());
        self::assertSame(0, DB::table('trading_journal_events')->count());
        self::assertSame(0, DB::table('market_funding_events')->count());
        self::assertSame(0, DB::table('signals')->count());
        app(ResearchWorker::class)->once($run->id);
        self::assertSame($done->result, DB::table('historical_research_runs')->find($run->id)->result);
        $legacy = $scope;
        unset($legacy['definition_id']);
        $legacy['strategy_id'] = 'momentum';
        $legacy['funding'] = 'DISABLED';
        $registered = $service->create($batch->id, $legacy);
        for ($i = 0; $i < 4 && DB::table('historical_research_runs')->find($registered->id)->status !== 'COMPLETED'; $i++) {
            app(ResearchWorker::class)->once($registered->id);
        }
        self::assertSame('COMPLETED', DB::table('historical_research_runs')->find($registered->id)->status);
        self::assertSame(0, DB::table('virtual_positions')->count());
        config(['historical_data.license.exports_enabled' => true]);
        $learning = app(LearningExport::class)->partition($run->id, 'train', 100);
        self::assertTrue($learning['chronological_only']);
        foreach ($learning['rows'] as $example) {
            self::assertLessThan($learning['to_exclusive'], CarbonImmutable::parse($example['exit_at'])->toISOString());
        }

    }

    public function test_incomplete_180_day_scope_is_rejected_without_lowering_coverage(): void
    {
        $batch = $this->imported();
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Research scope is outside imported dataset coverage.');
        app(ResearchService::class)->create($batch->id, ['strategy_id' => 'momentum', 'symbol' => 'BTCUSDT', 'from' => '2024-01-12T00:00:00Z', 'to' => '2024-07-10T00:00:00Z', 'capital' => '10000', 'fee' => '0.0005', 'slippage' => '0.0002', 'risk_percent' => '0.5', 'funding' => 'DISABLED', 'intended_use' => 'NONCOMMERCIAL_RESEARCH']);
    }
}
