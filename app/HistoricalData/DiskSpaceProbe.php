<?php

declare(strict_types=1);

namespace App\HistoricalData;

class DiskSpaceProbe
{
    /**
     * Return available free disk space in bytes for the given filesystem path,
     * or false on failure.
     */
    public function freeBytes(string $path): float|int|false
    {
        return @disk_free_space($path);
    }
}
