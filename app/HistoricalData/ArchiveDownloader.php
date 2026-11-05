<?php

declare(strict_types=1);

namespace App\HistoricalData;

final readonly class ArchiveDownloader
{
    public function __construct(private OfficialTransport $http) {}

    public function download(object $entry, string $directory, callable $tick): array
    {
        $this->http->assertUrl($entry->url);
        $this->http->assertUrl($entry->checksum_url);
        $zip = $directory.'/source.zip';
        $part = $zip.'.partial';
        $check = $directory.'/checksum.txt';
        $this->http->fetch($entry->checksum_url, $check, 1024, $tick);
        $checksum = file_get_contents($check);
        if (! preg_match('/^([a-fA-F0-9]{64})[ \t]+\*?'.preg_quote($entry->filename, '/').'[\r\n]*$/D', $checksum, $m)) {
            throw new AcquisitionFailure('CHECKSUM_FORMAT_INVALID');
        }
        $download = $this->http->fetch($entry->url, $part, (int) config('historical_data.max_archive_bytes'), $tick);
        if (! hash_equals(strtolower($m[1]), $download['sha256'])) {
            throw new AcquisitionFailure('CHECKSUM_MISMATCH');
        }
        if (! rename($part, $zip)) {
            throw new AcquisitionFailure('STAGING_RENAME_FAILED');
        }

        return $download + ['file' => $zip, 'official_checksum' => strtolower($m[1])];
    }
}
