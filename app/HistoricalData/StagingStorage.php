<?php

declare(strict_types=1);

namespace App\HistoricalData;

final class StagingStorage
{
    public function root(): string
    {
        $root = storage_path('app/historical-acquisition');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new AcquisitionFailure('STAGING_UNAVAILABLE');
        }
        if (is_link($root)) {
            throw new AcquisitionFailure('STAGING_SYMLINK_REJECTED');
        }

return $root;
    }

    public function admit(int $databaseEstimate = 0): array
    {
        $root = $this->root();
        $used = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new AcquisitionFailure('STAGING_SYMLINK_REJECTED');
            }if ($file->isFile()) {
                $used += $file->getSize();
            }
        }
        $free = disk_free_space($root);
        $required = (int) config('historical_data.reserve_disk_bytes') + (int) config('historical_data.max_staging_bytes') + $databaseEstimate;
        if ($free === false || $free < $required || $used > (int) config('historical_data.max_staging_bytes')) {
            throw new AcquisitionFailure('INSUFFICIENT_SAFE_DISK_SPACE');
        }

        return ['free_bytes' => (int) $free, 'required_bytes' => $required, 'staged_bytes' => $used];
    }

    public function directory(string $entry, string $lease): string
    {
        foreach ([$entry, $lease] as $id) {
            if (! preg_match('/^[a-f0-9-]{36}$/D', $id)) {
                throw new AcquisitionFailure('INVALID_STAGING_IDENTITY');
            }
        }
        $dir = $this->root().'/'.$entry.'-'.$lease;
        if (! is_dir($dir) && ! mkdir($dir, 0700)) {
            throw new AcquisitionFailure('STAGING_UNAVAILABLE');
        }if (is_link($dir)) {
            throw new AcquisitionFailure('STAGING_SYMLINK_REJECTED');
        }

return $dir;
    }

    public function clear(string $directory): void
    {
        if (dirname($directory) !== $this->root() || is_link($directory)) {
            throw new AcquisitionFailure('UNSAFE_CLEANUP_PATH');
        }
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isDot()) {
                continue;
            }if (! $file->isFile() || $file->isLink()) {
                throw new AcquisitionFailure('UNSAFE_CLEANUP_ENTRY');
            }unlink($file->getPathname());
        }rmdir($directory);
    }
}
