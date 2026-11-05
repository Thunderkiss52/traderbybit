<?php

declare(strict_types=1);

use App\HistoricalData\AcquisitionFailure;
use App\HistoricalData\OfficialTransport;
use App\PaperTrading\Application\PaperTopologySynchronizer;
use App\StrategyBuilder\DefinitionStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\StrategyBuilder\DefinitionTest;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'bybit_vision_browser') {
    throw new RuntimeException('Historical fixtures require the isolated bybit_vision_browser database.');
}
config(['historical_data.reserve_disk_bytes' => 0, 'historical_data.max_staging_bytes' => 104857600, 'historical_data.queue_connection' => 'database', 'services.research_api_token' => 'vision-browser-test']);
$app->instance(OfficialTransport::class, new class extends OfficialTransport
{
    public function fetch(string $url, string $target, int $maximum, callable $tick): array
    {
        $this->assertUrl($url);
        $tick();
        $root = base_path('tests/Fixtures/historical/research/');
        if (str_contains($url, 'fapi.binance.com')) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $rows = json_decode(file_get_contents($root.'funding.json'), true);
            $content = json_encode(array_values(array_filter($rows, fn ($r) => $r['fundingTime'] >= $query['startTime'] && $r['fundingTime'] <= $query['endTime'])));
        } else {
            $name = basename(str_replace('.CHECKSUM', '', $url));
            if (! is_file($root.$name)) {
                throw new AcquisitionFailure('SOURCE_UNAVAILABLE');
            }
            $content = str_ends_with($url, '.CHECKSUM') ? hash_file('sha256', $root.$name).'  '.$name : file_get_contents($root.$name);
        }
        file_put_contents($target, $content);

        return ['bytes' => strlen($content), 'sha256' => hash('sha256', $content)];
    }
});
if (PHP_SAPI !== 'cli-server') {
    if (($argv[1] ?? '') === 'work') {
        $app->make(Kernel::class)->call('queue:work', ['connection' => 'database', '--queue' => 'research', '--max-time' => 45, '--sleep' => 1, '--tries' => 1, '--timeout' => 1600]);
    } elseif (($argv[1] ?? '') === 'snapshot') {
        echo DB::selectOne("SELECT md5(string_agg(row_to_json(p)::text, '' ORDER BY p.id)) || '|' || count(*) || '|' || count(*) filter (where status='BLOCKED') AS snapshot FROM paper_accounts p")->snapshot;
    } elseif (($argv[1] ?? '') === 'seed') {
        config(['exchanges.binance_paper_provisioning_approved' => true]);
        $app->make(PaperTopologySynchronizer::class)->sync(true);
        DB::table('paper_accounts')->update(['status' => 'BLOCKED']);
        $d = DefinitionTest::example();
        $d['schema_version'] = 2;
        $d['id'] = 'user_vision_browser';
        $d['exchanges'] = ['binance'];
        $d['entry'] = ['BUY' => ['op' => 'gt', 'left' => 'fast', 'right' => 0]];
        $d['funding'] = ['enabled' => true, 'interval_hours' => 8];
        $d['timeout_bars'] = 4;
        $app->make(DefinitionStore::class)->save($d, 'isolated-browser-owner');
        echo 'SEEDED '.DB::table('paper_accounts')->count()." BLOCKED accounts\n";
    } else {
        throw new RuntimeException('Use seed or work.');
    }
    exit;
}
$app->handleRequest(Request::capture());
