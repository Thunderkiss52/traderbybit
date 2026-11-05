<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\AcquisitionFailure;
use App\HistoricalData\ArchiveDownloader;
use App\HistoricalData\OfficialTransport;
use Tests\TestCase;

final class DownloaderTest extends TestCase
{
    public function test_checksum_is_mandatory_and_mismatch_never_publishes_an_archive(): void
    {
        $dir = sys_get_temp_dir().'/vector-download-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $transport = $this->createMock(OfficialTransport::class);
        $transport->method('assertUrl');
        $transport->method('fetch')->willReturnCallback(function ($url, $path) {
            file_put_contents($path, str_ends_with($url, '.CHECKSUM') ? str_repeat('a', 64).'  BTCUSDT-1m-2024-01.zip' : 'zip fixture');

            return ['bytes' => 11, 'sha256' => hash_file('sha256', $path)];
        });
        try {
            $entry = (object) ['url' => 'https://data.binance.vision/data/futures/um/monthly/klines/BTCUSDT/1m/BTCUSDT-1m-2024-01.zip', 'checksum_url' => 'https://data.binance.vision/data/futures/um/monthly/klines/BTCUSDT/1m/BTCUSDT-1m-2024-01.zip.CHECKSUM', 'filename' => 'BTCUSDT-1m-2024-01.zip'];
            try {
                (new ArchiveDownloader($transport))->download($entry, $dir, fn () => null);
                self::fail('Mismatch accepted');
            } catch (AcquisitionFailure $e) {
                self::assertSame('CHECKSUM_MISMATCH', $e->reason);
                self::assertFileDoesNotExist($dir.'/source.zip');
            }
        } finally {
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
        }
    }

    public function test_only_official_strict_https_source_paths_are_allowed(): void
    {
        $transport = new OfficialTransport;
        foreach (['http://data.binance.vision/data/a.zip', 'https://127.0.0.1/foo', 'https://data.binance.vision.evil.test/data/a.zip', 'https://data.binance.vision@127.0.0.1/a.zip', 'https://data.binance.vision/../../a.zip', 'https://fapi.binance.com/fapi/v1/order'] as $url) {
            try {
                $transport->assertUrl($url);
                self::fail('Unsafe URL accepted');
            } catch (AcquisitionFailure $e) {
                self::assertSame('UNSAFE_SOURCE_URL', $e->reason);
            }
        }
    }
}
