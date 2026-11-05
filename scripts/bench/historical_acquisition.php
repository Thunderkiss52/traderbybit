<?php

declare(strict_types=1);

use App\HistoricalData\AcquisitionWorker;
use App\HistoricalData\ArchiveDownloader;
use App\HistoricalData\BatchControl;
use App\HistoricalData\BatchRepository;
use App\HistoricalData\OfficialTransport;
use App\HistoricalData\StagingStorage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'bybit_vision_benchmark') {
    throw new RuntimeException('Use only the isolated bybit_vision_benchmark database.');
}
if (($argv[1] ?? '') === 'official-sample') {
    $storage = app(StagingStorage::class);
    $dir = $storage->directory('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');
    $name = 'BTCUSDT-fundingRate-2024-01.zip';
    $url = 'https://data.binance.vision/data/futures/um/monthly/fundingRate/BTCUSDT/'.$name;
    $start = hrtime(true);
    try {
        $download = app(ArchiveDownloader::class)->download((object) ['url' => $url, 'checksum_url' => $url.'.CHECKSUM', 'filename' => $name], $dir, fn () => null);
        $seconds = (hrtime(true) - $start) / 1000000000;
        echo json_encode(['official_sample' => $url, 'bytes' => $download['bytes'], 'sha256' => $download['sha256'], 'elapsed_seconds_including_checksum_and_throttling' => $seconds, 'effective_bytes_per_second' => $download['bytes'] / $seconds, 'imported' => false], JSON_PRETTY_PRINT)."\n";
    } finally {
        $storage->clear($dir);
    }
    exit;
}
$fixture = '/fixture/BTCUSDT-1m-2024-01.zip';
if (! is_file($fixture)) {
    throw new RuntimeException('Mount the synthetic January fixture at /fixture.');
}
config(['historical_data.reserve_disk_bytes' => 0, 'historical_data.max_staging_bytes' => 104857600]);
$app->instance(OfficialTransport::class, new class($fixture) extends OfficialTransport
{
    public function __construct(private string $fixture) {}

    public function fetch(string $url, string $target, int $maximum, callable $tick): array
    {
        $this->assertUrl($url);
        $tick();
        if (str_ends_with($url, '.CHECKSUM')) {
            file_put_contents($target, hash_file('sha256', $this->fixture).'  BTCUSDT-1m-2024-01.zip');
        } else {
            copy($this->fixture, $target);
        }

        return ['bytes' => filesize($target), 'sha256' => hash_file('sha256', $target)];
    }
});
$batch = app(BatchRepository::class)->create(['provider' => 'BINANCE_VISION', 'market' => 'futures/um', 'symbols' => ['BTCUSDT'], 'intervals' => ['1m'], 'from' => '2024-01-01T00:00:00Z', 'to' => '2024-01-31T23:59:59.999Z', 'funding' => false, 'intended_use' => 'NONCOMMERCIAL_RESEARCH'], 'synthetic-benchmark-'.($argv[1] ?? 'one'));
app(BatchControl::class)->change($batch->id, 'start', 'SYNTHETIC-LOCAL-BENCH', config('historical_data.license.version'));
$start = hrtime(true);
app(AcquisitionWorker::class)->once($batch->id);
$seconds = (hrtime(true) - $start) / 1000000000;
$entry = DB::table('historical_download_entries')->where('batch_id', $batch->id)->first();
if ($entry->status !== 'IMPORTED' || $entry->checkpoint !== 44640) {
    throw new RuntimeException('Benchmark import failed: '.json_encode($entry));
}
echo json_encode(['synthetic' => true, 'network_download_tested' => false, 'seconds' => $seconds, 'inserted' => $entry->imported_rows, 'duplicates' => $entry->duplicate_rows, 'rows_per_second' => 44640 / $seconds, 'php_peak_allocated_bytes' => memory_get_peak_usage(true), 'php_peak_rss_kib' => getrusage()['ru_maxrss'], 'container_peak_bytes' => is_readable('/sys/fs/cgroup/memory.peak') ? trim(file_get_contents('/sys/fs/cgroup/memory.peak')) : null, 'chunk_rows' => config('historical_data.chunk_rows'), 'archive_bytes' => filesize($fixture)], JSON_PRETTY_PRINT)."\n";
